// Package cdc is the gateway source of kind "cdc": the row changes of Postgres tables, read
// through a logical replication slot with the pgoutput plugin (protocol version 1).
//
// Configuration (the trigger's "source" object):
//
//	{"dsn": "postgres://user:pass@db:5432/shop?sslmode=require", "publication": "runr_orders",
//	 "create_publication": false, "tables": ["public.orders"], "slot": "runr_<project>_<trigger>",
//	 "create_slot": true, "operations": ["insert", "update", "delete"], "status_interval": "10s"}
//
// dsn (never logged; its role needs the REPLICATION attribute), publication and slot
// ([a-z0-9_], at most 63 characters) are required. create_publication creates the publication
// FOR TABLE tables when it does not exist (tables is required then) and marks it with a comment
// ("Created by RunR …"), so the cleanup of a removed trigger (Cleanup, GATEWAY_MODE=cdc-cleanup)
// drops only a publication RunR created. create_slot (default true)
// creates a permanent pgoutput slot when it does not exist. operations (default all three) picks
// the changes that become events. status_interval (default 10s) is how often the source reports
// its position to the server.
//
// Each row change is the event
//
//	id              <slot>/<commit LSN>/<n>   (the n-th change of the transaction, from 1; the
//	                same on every redelivery)
//	type            dev.runr.cdc.insert | dev.runr.cdc.update | dev.runr.cdc.delete
//	source          /cdc/<database>/<schema>.<table>
//	subject         <schema>.<table>
//	time            the commit time
//	datacontenttype application/json
//	data            {"op": "update", "schema": "public", "table": "orders", "lsn": "0/16B3748",
//	                 "commit_time": "<RFC 3339>", "xid": 123, "new": {"<column>": "<text>" | null},
//	                 "old": {...} | null}
//
// Values are the Postgres text output of each column (strings, no type conversion). new is null
// for a delete; old is null for an insert and holds the replica identity key — or the whole old
// row with REPLICA IDENTITY FULL — for an update that changed the key and for a delete. TOASTed
// columns an update did not change are left out of new.
//
// Delivery guarantees:
//   - Changes are delivered one at a time, in commit order. A transaction's position is
//     confirmed to the server (flush LSN of the standby status update) only after every change
//     of it settled, so the slot keeps everything not yet settled: after a restart the server
//     sends the unconfirmed transactions again, from their first change (at-least-once).
//   - Between transactions, with nothing in delivery, the source confirms the server's WAL
//     position from its keepalives, so a slot whose tables are quiet does not hold back WAL.
//   - The source reports its position every status_interval, also while a delivery waits for
//     its retries, so the server does not drop the connection (wal_sender_timeout).
//   - A slot serves one connection: a second gateway replica fails to start replication and
//     retries with backoff, taking over when the first one stops.
package cdc

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/jackc/pglogrepl"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgproto3"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func init() { source.Register("cdc", New) }

// Config is the "source" object of a cdc trigger.
type Config struct {
	DSN               string           `json:"dsn"`
	Publication       string           `json:"publication"`
	CreatePublication bool             `json:"create_publication"`
	Tables            []string         `json:"tables"` // for create_publication: "schema.table" or "table"
	Slot              string           `json:"slot"`
	CreateSlot        *bool            `json:"create_slot"`     // default true
	Operations        []string         `json:"operations"`      // default insert, update, delete
	StatusInterval    gateway.Duration `json:"status_interval"` // default 10s
}

// Source streams the changes of one publication through one slot.
type Source struct {
	cfg            Config
	conn           *pgconn.Config
	host, database string
	createSlot     bool
	ops            map[string]bool
	statusInterval time.Duration
}

var slotRE = regexp.MustCompile(`^[a-z0-9_]{1,63}$`)

// New builds a cdc source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	if c.DSN == "" {
		return nil, errors.New("dsn is required")
	}
	pc, err := pgconn.ParseConfig(c.DSN)
	if err != nil {
		return nil, errors.New("dsn is not a valid Postgres connection string") // the parser's error may quote it
	}
	pc.RuntimeParams["replication"] = "database"
	if c.Publication == "" {
		return nil, errors.New("publication is required")
	}
	if !validName(c.Publication) {
		return nil, errors.New("publication must be at most 63 bytes without backslash, quote or NUL")
	}
	if c.CreatePublication {
		if len(c.Tables) == 0 {
			return nil, errors.New("tables is required with create_publication")
		}
		for i, t := range c.Tables {
			if t == "" || !validName(t) || strings.Count(t, ".") > 1 {
				return nil, fmt.Errorf("tables[%d] %q: want schema.table or table", i, t)
			}
		}
	}
	if !slotRE.MatchString(c.Slot) {
		return nil, errors.New("slot is required: [a-z0-9_], at most 63 characters")
	}
	s := &Source{
		cfg:            c,
		conn:           pc,
		database:       pc.Database,
		host:           pc.Host,
		createSlot:     c.CreateSlot == nil || *c.CreateSlot,
		ops:            map[string]bool{},
		statusInterval: c.StatusInterval.D(),
	}
	if len(c.Operations) == 0 {
		c.Operations = []string{"insert", "update", "delete"}
	}
	for _, op := range c.Operations {
		switch op {
		case "insert", "update", "delete":
			s.ops[op] = true
		default:
			return nil, fmt.Errorf("operations: %q is not insert, update or delete", op)
		}
	}
	if s.statusInterval <= 0 {
		s.statusInterval = 10 * time.Second
	}
	return s, nil
}

func validName(s string) bool {
	return len(s) <= 63 && !strings.ContainsAny(s, "\\'\"\x00")
}

// Name is "cdc <slot>@<host>/<database>" (no credentials).
func (s *Source) Name() string { return "cdc " + s.cfg.Slot + "@" + s.host + "/" + s.database }

// Run connects, makes sure the publication and the slot exist, and streams the changes until ctx
// ends (nil) or the connection fails (its error).
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	cctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	conn, err := pgconn.ConnectConfig(cctx, s.conn)
	cancel()
	if err != nil {
		if ctx.Err() != nil {
			return nil
		}
		return fmt.Errorf("connect: %w", err)
	}
	defer func() {
		cctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		conn.Close(cctx)
	}()

	start, err := s.prepare(ctx, conn)
	if err != nil {
		return err
	}
	err = pglogrepl.StartReplication(ctx, conn, s.cfg.Slot, 0, pglogrepl.StartReplicationOptions{
		Mode: pglogrepl.LogicalReplication,
		PluginArgs: []string{
			"proto_version '1'",
			"publication_names " + quoteLiteral(quoteIdent(s.cfg.Publication)),
		},
	})
	if err != nil {
		return fmt.Errorf("start replication on slot %s: %w", s.cfg.Slot, err)
	}
	return s.stream(ctx, conn, start, emit)
}

// prepare creates the publication (create_publication) and the slot (create_slot) when they do
// not exist, and returns the slot's confirmed position.
func (s *Source) prepare(ctx context.Context, conn *pgconn.PgConn) (pglogrepl.LSN, error) {
	if s.cfg.CreatePublication {
		rows, err := query(ctx, conn, "SELECT 1 FROM pg_catalog.pg_publication WHERE pubname = "+quoteLiteral(s.cfg.Publication))
		if err != nil {
			return 0, fmt.Errorf("look up publication %s: %w", s.cfg.Publication, err)
		}
		if len(rows) == 0 {
			tables := make([]string, len(s.cfg.Tables))
			for i, t := range s.cfg.Tables {
				tables[i] = tableIdent(t)
			}
			_, err := query(ctx, conn, "CREATE PUBLICATION "+quoteIdent(s.cfg.Publication)+" FOR TABLE "+strings.Join(tables, ", "))
			if err != nil && !isDuplicate(err) {
				return 0, fmt.Errorf("create publication %s: %w", s.cfg.Publication, err)
			}
			if err == nil {
				// The mark the cleanup of a removed trigger looks for: it drops only a publication
				// RunR created (Cleanup)
				if _, err := query(ctx, conn, "COMMENT ON PUBLICATION "+quoteIdent(s.cfg.Publication)+" IS "+quoteLiteral(publicationComment(s.cfg.Slot))); err != nil {
					log.Printf("%s: marking publication %s as RunR's failed (a removed trigger leaves it): %v", s.Name(), s.cfg.Publication, err)
				}
			}
			log.Printf("%s: created publication %s for %s", s.Name(), s.cfg.Publication, strings.Join(s.cfg.Tables, ", "))
		}
	}

	for range 2 {
		rows, err := query(ctx, conn, "SELECT confirmed_flush_lsn::text, plugin FROM pg_catalog.pg_replication_slots WHERE slot_name = "+quoteLiteral(s.cfg.Slot))
		if err != nil {
			return 0, fmt.Errorf("look up slot %s: %w", s.cfg.Slot, err)
		}
		if len(rows) > 0 {
			if string(rows[0][1]) != "pgoutput" || rows[0][0] == nil {
				return 0, fmt.Errorf("slot %s is not a logical pgoutput slot", s.cfg.Slot)
			}
			return pglogrepl.ParseLSN(string(rows[0][0]))
		}
		if !s.createSlot {
			return 0, fmt.Errorf("slot %s does not exist (create_slot is false)", s.cfg.Slot)
		}
		res, err := pglogrepl.CreateReplicationSlot(ctx, conn, s.cfg.Slot, "pgoutput", pglogrepl.CreateReplicationSlotOptions{
			Mode:           pglogrepl.LogicalReplication,
			SnapshotAction: "NOEXPORT_SNAPSHOT",
		})
		if isDuplicate(err) {
			continue // another replica created it meanwhile
		}
		if err != nil {
			return 0, fmt.Errorf("create slot %s: %w", s.cfg.Slot, err)
		}
		log.Printf("%s: created slot %s at %s", s.Name(), s.cfg.Slot, res.ConsistentPoint)
		return pglogrepl.ParseLSN(res.ConsistentPoint)
	}
	return 0, fmt.Errorf("slot %s: created and missing at once", s.cfg.Slot)
}

// item is what the reader hands the delivery worker: a change to deliver, or the end of a
// transaction whose changes were all handed over before it.
type item struct {
	event     *source.Event
	commitEnd pglogrepl.LSN
}

// stream runs the reader (this goroutine: the connection, status updates) and the delivery
// worker until ctx ends or the connection fails.
func (s *Source) stream(ctx context.Context, conn *pgconn.PgConn, start pglogrepl.LSN, emit func(context.Context, source.Event) error) error {
	sctx, cancel := context.WithCancel(ctx)
	defer cancel()
	p := newProgress(start)
	work := make(chan item, 64)
	workerDone := make(chan struct{})
	go func() {
		defer close(workerDone)
		deliverAll(sctx, work, emit, p)
	}()

	err := s.read(sctx, conn, work, workerDone, p)
	cancel() // on a failed connection the deliveries in flight are abandoned: the server sends them again
	<-workerDone
	if err == nil {
		// Shutdown: report what settled meanwhile.
		pglogrepl.SendStandbyStatusUpdate(context.Background(), conn, pglogrepl.StandbyStatusUpdate{WALWritePosition: p.confirmed()})
	}
	if ctx.Err() != nil {
		return nil
	}
	return err
}

// read receives the replication stream, hands the changes to the worker and sends a status
// update every status_interval (also while the worker is busy) and when the server asks.
func (s *Source) read(ctx context.Context, conn *pgconn.PgConn, work chan<- item, workerDone <-chan struct{}, p *progress) error {
	dec := newDecoder(s.cfg.Slot, s.database, s.ops)
	next := time.Now().Add(s.statusInterval)
	status := func() error {
		next = time.Now().Add(s.statusInterval)
		if err := pglogrepl.SendStandbyStatusUpdate(ctx, conn, pglogrepl.StandbyStatusUpdate{WALWritePosition: p.confirmed()}); err != nil {
			return fmt.Errorf("status update: %w", err)
		}
		return nil
	}
	push := func(it item) error {
		for {
			t := time.NewTimer(time.Until(next))
			select {
			case work <- it:
				t.Stop()
				return nil
			case <-t.C:
				if err := status(); err != nil {
					return err
				}
			case <-ctx.Done():
				t.Stop()
				return nil
			case <-workerDone:
				t.Stop()
				return errors.New("delivery stopped")
			}
		}
	}

	for ctx.Err() == nil {
		if !time.Now().Before(next) {
			if err := status(); err != nil {
				return err
			}
		}
		rctx, cancel := context.WithDeadline(ctx, next)
		msg, err := conn.ReceiveMessage(rctx)
		cancel()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			if pgconn.Timeout(err) {
				continue
			}
			return fmt.Errorf("receive: %w", err)
		}
		var cd *pgproto3.CopyData
		switch m := msg.(type) {
		case *pgproto3.CopyData:
			cd = m
		case *pgproto3.ErrorResponse:
			return pgconn.ErrorResponseToPgError(m)
		default:
			continue
		}
		if len(cd.Data) == 0 {
			continue
		}
		switch cd.Data[0] {
		case pglogrepl.PrimaryKeepaliveMessageByteID:
			ka, err := pglogrepl.ParsePrimaryKeepaliveMessage(cd.Data[1:])
			if err != nil {
				return err
			}
			if !dec.inTx {
				p.advanceIdle(ka.ServerWALEnd)
			}
			if ka.ReplyRequested {
				if err := status(); err != nil {
					return err
				}
			}
		case pglogrepl.XLogDataByteID:
			xld, err := pglogrepl.ParseXLogData(cd.Data[1:])
			if err != nil {
				return err
			}
			lm, err := pglogrepl.Parse(xld.WALData)
			if err != nil {
				return fmt.Errorf("decode pgoutput message: %w", err)
			}
			it, ok, err := dec.apply(lm)
			if err != nil {
				return err
			}
			if !ok {
				continue
			}
			if it.event == nil {
				p.queue(it.commitEnd)
			}
			if err := push(it); err != nil {
				return err
			}
		}
	}
	return nil
}

// deliverAll delivers the changes one at a time and confirms a transaction's end once every
// change of it settled. It returns when ctx ends or a delivery is not settled.
func deliverAll(ctx context.Context, work <-chan item, emit func(context.Context, source.Event) error, p *progress) {
	for {
		select {
		case <-ctx.Done():
			return
		case it := <-work:
			if it.event == nil {
				p.settle(it.commitEnd)
				continue
			}
			if !source.Settled(emit(ctx, *it.event)) {
				return // shutting down: the slot keeps the transaction
			}
		}
	}
}

// progress tracks the position the source confirms: the end of the last transaction whose
// changes all settled.
type progress struct {
	conf atomic.Uint64

	mu     sync.Mutex
	queued pglogrepl.LSN // end of the last transaction handed to the worker
}

func newProgress(start pglogrepl.LSN) *progress {
	p := &progress{queued: start}
	p.conf.Store(uint64(start))
	return p
}

func (p *progress) confirmed() pglogrepl.LSN { return pglogrepl.LSN(p.conf.Load()) }

// queue records that the end of a transaction was handed to the worker.
func (p *progress) queue(end pglogrepl.LSN) {
	p.mu.Lock()
	p.queued = max(p.queued, end)
	p.mu.Unlock()
}

// settle confirms the end of a transaction whose changes all settled.
func (p *progress) settle(end pglogrepl.LSN) {
	for {
		cur := p.conf.Load()
		if uint64(end) <= cur || p.conf.CompareAndSwap(cur, uint64(end)) {
			return
		}
	}
}

// advanceIdle confirms the server's WAL position walEnd when every transaction handed to the
// worker settled (called between transactions only): everything before walEnd was sent already.
func (p *progress) advanceIdle(walEnd pglogrepl.LSN) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.confirmed() != p.queued || walEnd <= p.queued {
		return
	}
	p.queued = walEnd
	p.settle(walEnd)
}

// decoder turns pgoutput messages into change events; it tracks the relations and the current
// transaction.
type decoder struct {
	slot, database string
	ops            map[string]bool
	relations      map[uint32]*pglogrepl.RelationMessage

	inTx       bool
	commitLSN  pglogrepl.LSN
	commitTime time.Time
	xid        uint32
	n          int // changes of the transaction so far
}

func newDecoder(slot, database string, ops map[string]bool) *decoder {
	return &decoder{slot: slot, database: database, ops: ops, relations: map[uint32]*pglogrepl.RelationMessage{}}
}

// apply handles one message; ok reports an item for the worker: a change event, or the end of
// a transaction (commitEnd).
func (d *decoder) apply(m pglogrepl.Message) (it item, ok bool, err error) {
	switch m := m.(type) {
	case *pglogrepl.BeginMessage:
		d.inTx, d.commitLSN, d.commitTime, d.xid, d.n = true, m.FinalLSN, m.CommitTime, m.Xid, 0
	case *pglogrepl.CommitMessage:
		d.inTx = false
		return item{commitEnd: m.TransactionEndLSN}, true, nil
	case *pglogrepl.RelationMessage:
		d.relations[m.RelationID] = m
	case *pglogrepl.InsertMessage:
		return d.change("insert", m.RelationID, nil, 0, m.Tuple)
	case *pglogrepl.UpdateMessage:
		return d.change("update", m.RelationID, m.OldTuple, m.OldTupleType, m.NewTuple)
	case *pglogrepl.DeleteMessage:
		return d.change("delete", m.RelationID, m.OldTuple, m.OldTupleType, nil)
	}
	return item{}, false, nil
}

// changeData is the data of a change event.
type changeData struct {
	Op         string             `json:"op"`
	Schema     string             `json:"schema"`
	Table      string             `json:"table"`
	LSN        string             `json:"lsn"`
	CommitTime string             `json:"commit_time"`
	Xid        uint32             `json:"xid"`
	New        map[string]*string `json:"new"`
	Old        map[string]*string `json:"old"`
}

func (d *decoder) change(op string, relID uint32, oldTuple *pglogrepl.TupleData, oldType uint8, newTuple *pglogrepl.TupleData) (item, bool, error) {
	d.n++
	if !d.ops[op] {
		return item{}, false, nil
	}
	rel, ok := d.relations[relID]
	if !ok {
		return item{}, false, fmt.Errorf("%s of relation %d before its Relation message", op, relID)
	}
	table := rel.Namespace + "." + rel.RelationName
	data, err := json.Marshal(changeData{
		Op:         op,
		Schema:     rel.Namespace,
		Table:      rel.RelationName,
		LSN:        d.commitLSN.String(),
		CommitTime: d.commitTime.UTC().Format(time.RFC3339Nano),
		Xid:        d.xid,
		New:        row(rel, newTuple, false),
		Old:        row(rel, oldTuple, oldType == pglogrepl.UpdateMessageTupleTypeKey),
	})
	if err != nil {
		return item{}, false, err
	}
	return item{event: &source.Event{
		ID:              fmt.Sprintf("%s/%s/%d", d.slot, d.commitLSN, d.n),
		Type:            "dev.runr.cdc." + op,
		Source:          "/cdc/" + d.database + "/" + table,
		Subject:         table,
		Time:            d.commitTime,
		DataContentType: "application/json",
		Data:            data,
	}}, true, nil
}

// row maps a tuple to column → text value (nil for SQL NULL); unchanged TOASTed values are left
// out, and keyOnly keeps the replica identity columns only. A nil tuple is a nil map (JSON null).
func row(rel *pglogrepl.RelationMessage, t *pglogrepl.TupleData, keyOnly bool) map[string]*string {
	if t == nil {
		return nil
	}
	out := make(map[string]*string, len(t.Columns))
	for i, col := range t.Columns {
		if i >= len(rel.Columns) {
			break
		}
		rc := rel.Columns[i]
		if keyOnly && rc.Flags&1 == 0 {
			continue
		}
		switch col.DataType {
		case pglogrepl.TupleDataTypeNull:
			out[rc.Name] = nil
		case pglogrepl.TupleDataTypeText, pglogrepl.TupleDataTypeBinary:
			v := string(col.Data)
			out[rc.Name] = &v
		}
	}
	return out
}

// query runs one SQL statement (simple protocol: the replication connection takes no other).
func query(ctx context.Context, conn *pgconn.PgConn, sql string) ([][][]byte, error) {
	results, err := conn.Exec(ctx, sql).ReadAll()
	if err != nil {
		return nil, err
	}
	if len(results) == 0 {
		return nil, nil
	}
	return results[0].Rows, results[0].Err
}

func isDuplicate(err error) bool {
	var pgErr *pgconn.PgError
	return errors.As(err, &pgErr) && pgErr.Code == "42710" // duplicate_object
}

func quoteIdent(s string) string { return `"` + strings.ReplaceAll(s, `"`, `""`) + `"` }

func quoteLiteral(s string) string { return `'` + strings.ReplaceAll(s, `'`, `''`) + `'` }

// tableIdent quotes "schema.table" (or "table") as an identifier.
func tableIdent(t string) string {
	if schema, name, ok := strings.Cut(t, "."); ok {
		return quoteIdent(schema) + "." + quoteIdent(name)
	}
	return quoteIdent(t)
}
