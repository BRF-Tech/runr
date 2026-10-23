package cdc

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgconn"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
)

// PublicationMarker starts the comment a cdc source puts on a publication it created
// (create_publication); Cleanup drops a publication only when its comment starts with it.
const PublicationMarker = "Created by RunR"

func publicationComment(slot string) string {
	return PublicationMarker + " for the cdc trigger of slot " + slot + "; RunR drops it with the trigger."
}

// CleanupConfig is what GATEWAY_MODE=cdc-cleanup reads from CLEANUP_CONFIG: the database of a
// removed cdc trigger, the slots it read through (one per workload: the project's own and each
// environment's, or the one named slot) and its publication.
//
//	{"dsn": "postgres://…", "slots": ["runr_shop_orders"], "publication": "runr_orders",
//	 "drop_publication": true, "wait": "60s"}
type CleanupConfig struct {
	DSN             string           `json:"dsn"`
	Slots           []string         `json:"slots"`
	Publication     string           `json:"publication"`
	DropPublication bool             `json:"drop_publication"` // the trigger had create_publication: drop it if RunR created it
	Wait            gateway.Duration `json:"wait"`             // how long a slot still in use is waited for, default 60s
}

// CleanupResult is what Cleanup did, printed as `RUNR_RESULT <json>`.
type CleanupResult struct {
	Database    string        `json:"database"`        // host/database, no credentials
	Reachable   bool          `json:"reachable"`       // the connection succeeded
	Error       string        `json:"error,omitempty"` // why it did not
	Slots       []CleanupItem `json:"slots"`
	Publication *CleanupItem  `json:"publication,omitempty"`
}

// CleanupItem is the outcome for one slot or the publication: dropped, absent (nothing to do),
// in_use (an active connection still reads the slot), kept (a publication RunR did not create)
// or failed (Detail says why).
type CleanupItem struct {
	Name   string `json:"name"`
	Status string `json:"status"`
	Detail string `json:"detail,omitempty"`
}

// OK reports whether nothing is left behind that RunR should have removed.
func (r CleanupResult) OK() bool {
	if !r.Reachable {
		return false
	}
	for _, s := range r.Slots {
		if s.Status != "dropped" && s.Status != "absent" {
			return false
		}
	}
	return r.Publication == nil || r.Publication.Status != "failed"
}

// cleanupDB is the part of a Postgres connection Cleanup uses; tests replace it.
type cleanupDB interface {
	query(ctx context.Context, sql string) ([][][]byte, error)
}

type pgDB struct{ conn *pgconn.PgConn }

func (d pgDB) query(ctx context.Context, sql string) ([][][]byte, error) {
	return query(ctx, d.conn, sql)
}

// RunCleanup is GATEWAY_MODE=cdc-cleanup: it reads CLEANUP_CONFIG, runs Cleanup and prints one
// line `RUNR_RESULT <json>` (the RunR API reads it from the pod's log). It returns an error only
// for a configuration it cannot read; an unreachable database is a result.
func RunCleanup(ctx context.Context, getenv func(string) string, out io.Writer) error {
	path := getenv("CLEANUP_CONFIG")
	if path == "" {
		return errors.New("CLEANUP_CONFIG is required in GATEWAY_MODE=cdc-cleanup")
	}
	b, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	var c CleanupConfig
	if err := json.Unmarshal(b, &c); err != nil {
		return fmt.Errorf("%s: %w", path, err)
	}
	if c.DSN == "" {
		return fmt.Errorf("%s: dsn is required", path)
	}
	for _, s := range c.Slots {
		if !slotRE.MatchString(s) {
			return fmt.Errorf("%s: slot %q: want [a-z0-9_], at most 63 characters", path, s)
		}
	}
	if c.Publication != "" && !validName(c.Publication) {
		return fmt.Errorf("%s: publication must be at most 63 bytes without backslash, quote or NUL", path)
	}

	res := connectAndClean(ctx, c)
	line, _ := json.Marshal(res)
	fmt.Fprintf(out, "RUNR_RESULT %s\n", line)
	return nil
}

func connectAndClean(ctx context.Context, c CleanupConfig) CleanupResult {
	pc, err := pgconn.ParseConfig(c.DSN)
	if err != nil {
		return CleanupResult{Error: "dsn is not a valid Postgres connection string"}
	}
	res := CleanupResult{Database: pc.Host + "/" + pc.Database}
	cctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	conn, err := pgconn.ConnectConfig(cctx, pc)
	cancel()
	if err != nil {
		res.Error = "connect: " + redact(err.Error(), pc.Password)
		return res
	}
	defer func() {
		cctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		conn.Close(cctx)
	}()
	return cleanup(ctx, pgDB{conn}, c, res, 2*time.Second)
}

// cleanup drops each slot (waiting up to c.Wait for one that is still in use) and, with
// drop_publication, the publication when its comment says RunR created it.
func cleanup(ctx context.Context, db cleanupDB, c CleanupConfig, res CleanupResult, poll time.Duration) CleanupResult {
	res.Reachable = true
	wait := c.Wait.D()
	if wait <= 0 {
		wait = time.Minute
	}
	for _, slot := range c.Slots {
		res.Slots = append(res.Slots, dropSlot(ctx, db, slot, wait, poll))
	}
	if c.Publication != "" && c.DropPublication {
		item := dropPublication(ctx, db, c.Publication)
		res.Publication = &item
	}
	return res
}

func dropSlot(ctx context.Context, db cleanupDB, slot string, wait, poll time.Duration) CleanupItem {
	item := CleanupItem{Name: slot}
	deadline := time.Now().Add(wait)
	for {
		rows, err := db.query(ctx, "SELECT active::text, coalesce(active_pid::text, '') FROM pg_catalog.pg_replication_slots WHERE slot_name = "+quoteLiteral(slot))
		if err != nil {
			item.Status, item.Detail = "failed", "look up: "+err.Error()
			return item
		}
		if len(rows) == 0 {
			item.Status = "absent"
			return item
		}
		if string(rows[0][0]) != "true" {
			break
		}
		if !time.Now().Before(deadline) || ctx.Err() != nil {
			item.Status = "in_use"
			item.Detail = "an active connection (pid " + string(rows[0][1]) + ") still reads it"
			return item
		}
		select {
		case <-ctx.Done():
		case <-time.After(poll):
		}
	}
	if _, err := db.query(ctx, "SELECT pg_catalog.pg_drop_replication_slot("+quoteLiteral(slot)+")"); err != nil {
		var pgErr *pgconn.PgError
		if errors.As(err, &pgErr) && pgErr.Code == "42704" { // undefined_object: gone meanwhile
			item.Status = "absent"
			return item
		}
		item.Status, item.Detail = "failed", err.Error()
		return item
	}
	item.Status = "dropped"
	return item
}

func dropPublication(ctx context.Context, db cleanupDB, pub string) CleanupItem {
	item := CleanupItem{Name: pub}
	rows, err := db.query(ctx, "SELECT coalesce(obj_description(oid, 'pg_publication'), '') FROM pg_catalog.pg_publication WHERE pubname = "+quoteLiteral(pub))
	if err != nil {
		item.Status, item.Detail = "failed", "look up: "+err.Error()
		return item
	}
	if len(rows) == 0 {
		item.Status = "absent"
		return item
	}
	if !strings.HasPrefix(string(rows[0][0]), PublicationMarker) {
		item.Status, item.Detail = "kept", "RunR did not create it (it existed before the trigger)"
		return item
	}
	if _, err := db.query(ctx, "DROP PUBLICATION IF EXISTS "+quoteIdent(pub)); err != nil {
		item.Status, item.Detail = "failed", err.Error()
		return item
	}
	item.Status = "dropped"
	return item
}

// redact removes the password from a connection error, should the driver quote it.
func redact(msg, password string) string {
	if password == "" {
		return msg
	}
	return strings.ReplaceAll(msg, password, "***")
}
