package cdc

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/jackc/pglogrepl"
	"github.com/jackc/pgx/v5/pgconn"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func TestConfig(t *testing.T) {
	const dsn = `"dsn": "postgres://u:hunter2@db:5432/shop"`
	for name, tc := range map[string]struct{ cfg, wantErr string }{
		"no dsn":         {`{"publication": "p", "slot": "s"}`, "dsn"},
		"bad dsn":        {`{"dsn": "postgres://u:hunter2@db:notaport/x", "publication": "p", "slot": "s"}`, "dsn"},
		"no publication": {`{` + dsn + `, "slot": "s"}`, "publication"},
		"no slot":        {`{` + dsn + `, "publication": "p"}`, "slot"},
		"bad slot":       {`{` + dsn + `, "publication": "p", "slot": "Runr-Slot"}`, "slot"},
		"no tables":      {`{` + dsn + `, "publication": "p", "slot": "s", "create_publication": true}`, "tables"},
		"bad table":      {`{` + dsn + `, "publication": "p", "slot": "s", "create_publication": true, "tables": ["a.b.c"]}`, "tables[0]"},
		"operation":      {`{` + dsn + `, "publication": "p", "slot": "s", "operations": ["truncate"]}`, "operations"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := New(json.RawMessage(tc.cfg))
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("New(%s) = %v, want an error naming %q", tc.cfg, err, tc.wantErr)
			}
			if strings.Contains(err.Error(), "hunter2") {
				t.Errorf("error %q leaks the password", err)
			}
		})
	}

	s, err := New(json.RawMessage(`{` + dsn + `, "publication": "runr_orders", "slot": "runr_p_orders"}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	if !src.createSlot || src.statusInterval != 10*time.Second || len(src.ops) != 3 || src.conn.RuntimeParams["replication"] != "database" {
		t.Errorf("defaults: createSlot %v interval %s ops %v params %v", src.createSlot, src.statusInterval, src.ops, src.conn.RuntimeParams)
	}
	if src.Name() != "cdc runr_p_orders@db/shop" {
		t.Errorf("Name %q", src.Name())
	}
	s, _ = New(json.RawMessage(`{` + dsn + `, "publication": "p", "slot": "s", "create_slot": false, "operations": ["insert"], "status_interval": "2s"}`))
	if src := s.(*Source); src.createSlot || !src.ops["insert"] || src.ops["delete"] || src.statusInterval != 2*time.Second {
		t.Errorf("explicit settings not taken: %+v", src)
	}
}

func TestQuoting(t *testing.T) {
	if got := tableIdent("public.Orders"); got != `"public"."Orders"` {
		t.Errorf("tableIdent = %s", got)
	}
	if got := tableIdent(`we"ird`); got != `"we""ird"` {
		t.Errorf("tableIdent = %s", got)
	}
	if got := quoteLiteral(quoteIdent("runr_x")); got != `'"runr_x"'` {
		t.Errorf("publication_names value = %s", got)
	}
}

func text(s string) *pglogrepl.TupleDataColumn {
	return &pglogrepl.TupleDataColumn{DataType: pglogrepl.TupleDataTypeText, Length: uint32(len(s)), Data: []byte(s)}
}

func tuple(cols ...*pglogrepl.TupleDataColumn) *pglogrepl.TupleData {
	return &pglogrepl.TupleData{ColumnNum: uint16(len(cols)), Columns: cols}
}

var (
	null  = &pglogrepl.TupleDataColumn{DataType: pglogrepl.TupleDataTypeNull}
	toast = &pglogrepl.TupleDataColumn{DataType: pglogrepl.TupleDataTypeToast}
)

func TestDecoder(t *testing.T) {
	commit := time.Date(2026, 10, 21, 10, 0, 0, 123000000, time.UTC)
	d := newDecoder("runr_s", "shop", map[string]bool{"insert": true, "update": true, "delete": true})
	steps := []pglogrepl.Message{
		&pglogrepl.RelationMessage{RelationID: 16384, Namespace: "public", RelationName: "orders", Columns: []*pglogrepl.RelationMessageColumn{
			{Flags: 1, Name: "id"}, {Name: "status"}, {Name: "note"}, {Name: "doc"},
		}},
		&pglogrepl.BeginMessage{FinalLSN: 0x16B3748, CommitTime: commit, Xid: 731},
		&pglogrepl.InsertMessage{RelationID: 16384, Tuple: tuple(text("7"), text("new"), null, text("{}"))},
		&pglogrepl.UpdateMessage{RelationID: 16384, NewTuple: tuple(text("7"), text("paid"), null, toast)},
		&pglogrepl.UpdateMessage{RelationID: 16384, OldTupleType: pglogrepl.UpdateMessageTupleTypeKey,
			OldTuple: tuple(text("7"), null, null, null), NewTuple: tuple(text("8"), text("paid"), null, toast)},
		&pglogrepl.DeleteMessage{RelationID: 16384, OldTupleType: pglogrepl.DeleteMessageTupleTypeOld, OldTuple: tuple(text("8"), text("paid"), null, text("{}"))},
		&pglogrepl.CommitMessage{CommitLSN: 0x16B3748, TransactionEndLSN: 0x16B3778, CommitTime: commit},
	}
	var items []item
	for _, m := range steps {
		it, ok, err := d.apply(m)
		if err != nil {
			t.Fatal(err)
		}
		if ok {
			items = append(items, it)
		}
	}
	if len(items) != 5 || items[4].event != nil || items[4].commitEnd != 0x16B3778 || d.inTx {
		t.Fatalf("items %+v, want four changes and the end of the transaction", items)
	}

	ins := items[0].event
	if ins.ID != "runr_s/0/16B3748/1" || ins.Type != "dev.runr.cdc.insert" || ins.Source != "/cdc/shop/public.orders" ||
		ins.Subject != "public.orders" || !ins.Time.Equal(commit) || ins.DataContentType != "application/json" {
		t.Errorf("insert event %+v", ins)
	}
	wantJSON(t, ins.Data, `{"op":"insert","schema":"public","table":"orders","lsn":"0/16B3748","commit_time":"2026-10-21T10:00:00.123Z","xid":731,`+
		`"new":{"doc":"{}","id":"7","note":null,"status":"new"},"old":null}`)
	// an unchanged TOASTed column is left out of new
	wantJSON(t, items[1].event.Data, `{"op":"update","schema":"public","table":"orders","lsn":"0/16B3748","commit_time":"2026-10-21T10:00:00.123Z","xid":731,`+
		`"new":{"id":"7","note":null,"status":"paid"},"old":null}`)
	// a key change: old holds the key columns only
	wantJSON(t, items[2].event.Data, `{"op":"update","schema":"public","table":"orders","lsn":"0/16B3748","commit_time":"2026-10-21T10:00:00.123Z","xid":731,`+
		`"new":{"id":"8","note":null,"status":"paid"},"old":{"id":"7"}}`)
	// REPLICA IDENTITY FULL: old is the whole row
	wantJSON(t, items[3].event.Data, `{"op":"delete","schema":"public","table":"orders","lsn":"0/16B3748","commit_time":"2026-10-21T10:00:00.123Z","xid":731,`+
		`"new":null,"old":{"doc":"{}","id":"8","note":null,"status":"paid"}}`)
	if items[3].event.ID != "runr_s/0/16B3748/4" || items[3].event.Type != "dev.runr.cdc.delete" {
		t.Errorf("delete event %+v", items[3].event)
	}
}

func TestDecoderFiltersOperationsAndNeedsRelations(t *testing.T) {
	d := newDecoder("s", "db", map[string]bool{"delete": true})
	d.apply(&pglogrepl.RelationMessage{RelationID: 1, Namespace: "public", RelationName: "t", Columns: []*pglogrepl.RelationMessageColumn{{Flags: 1, Name: "id"}}})
	d.apply(&pglogrepl.BeginMessage{FinalLSN: 100})
	if _, ok, _ := d.apply(&pglogrepl.InsertMessage{RelationID: 1, Tuple: tuple(text("1"))}); ok {
		t.Error("an insert became an event though operations is [delete]")
	}
	it, ok, err := d.apply(&pglogrepl.DeleteMessage{RelationID: 1, OldTupleType: 'K', OldTuple: tuple(text("1"))})
	if err != nil || !ok || it.event.ID != "s/0/64/2" {
		t.Errorf("delete item %+v ok %v err %v, want the second change of the transaction", it, ok, err)
	}
	if _, _, err := d.apply(&pglogrepl.InsertMessage{RelationID: 99, Tuple: tuple(text("1"))}); err != nil {
		t.Errorf("a filtered change of an unknown relation failed: %v", err)
	}
	if _, _, err := d.apply(&pglogrepl.DeleteMessage{RelationID: 99, OldTuple: tuple(text("1"))}); err == nil {
		t.Error("a change of an unknown relation must fail")
	}
}

func wantJSON(t *testing.T, got []byte, want string) {
	t.Helper()
	var g, w any
	if err := json.Unmarshal(got, &g); err != nil {
		t.Fatalf("data %s: %v", got, err)
	}
	json.Unmarshal([]byte(want), &w)
	gb, _ := json.Marshal(g)
	wb, _ := json.Marshal(w)
	if string(gb) != string(wb) {
		t.Errorf("data\n got %s\nwant %s", gb, wb)
	}
}

func TestDeliverAllConfirmsSettledTransactions(t *testing.T) {
	p := newProgress(10)
	work := make(chan item, 16)
	ev := func(id string) item { return item{event: &source.Event{ID: id}} }
	// tx 1: two changes, settled (one delivered, one dead-lettered); tx 2: interrupted
	p.queue(20)
	work <- ev("a")
	work <- ev("b")
	work <- item{commitEnd: 20}
	p.queue(30)
	work <- ev("c")
	work <- item{commitEnd: 30}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	var order []string
	emit := func(ctx context.Context, e source.Event) error {
		order = append(order, e.ID)
		switch e.ID {
		case "a":
			if p.confirmed() != 10 {
				t.Errorf("confirmed %s before the transaction settled", p.confirmed())
			}
			return nil
		case "b":
			return fmt.Errorf("%w after 6 attempts", source.ErrDeadLettered)
		}
		cancel() // shutdown during c
		return ctx.Err()
	}
	deliverAll(ctx, work, emit, p)
	if strings.Join(order, "") != "abc" {
		t.Errorf("delivery order %v", order)
	}
	if p.confirmed() != 20 {
		t.Fatalf("confirmed %s, want the end of the settled transaction (0/14), not of the interrupted one", p.confirmed())
	}
}

func TestProgressAdvancesWhenIdle(t *testing.T) {
	p := newProgress(100)
	p.advanceIdle(150) // nothing in delivery: the keepalive's position is confirmed
	if p.confirmed() != 150 {
		t.Fatalf("confirmed %d, want 150", p.confirmed())
	}
	p.queue(200) // a transaction handed to the worker, not settled yet
	p.advanceIdle(250)
	if p.confirmed() != 150 {
		t.Fatalf("confirmed %d while a transaction is in delivery, want 150", p.confirmed())
	}
	p.settle(200)
	p.advanceIdle(180) // an older keepalive never moves back
	if p.confirmed() != 200 {
		t.Fatalf("confirmed %d, want 200", p.confirmed())
	}
	p.advanceIdle(260)
	if p.confirmed() != 260 {
		t.Fatalf("confirmed %d, want 260", p.confirmed())
	}
}

// ── Integration: a Postgres with wal_level=logical, QC_TEST_POSTGRES=postgres://… go test ./sources/cdc ──

func TestPostgres(t *testing.T) {
	dsn := os.Getenv("QC_TEST_POSTGRES")
	if dsn == "" {
		t.Skip("QC_TEST_POSTGRES not set")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	db, err := pgconn.Connect(ctx, dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close(context.Background())
	exec := func(sql string) {
		t.Helper()
		if _, err := db.Exec(ctx, sql).ReadAll(); err != nil {
			t.Fatalf("%s: %v", sql, err)
		}
	}
	suffix := fmt.Sprint(time.Now().UnixNano())
	table, pub, slot := "qc_cdc_"+suffix, "qc_pub_"+suffix, "qc_slot_"+suffix
	exec("CREATE TABLE public." + table + " (id int PRIMARY KEY, status text)")
	defer func() {
		db.Exec(context.Background(), "SELECT pg_drop_replication_slot('"+slot+"')").ReadAll()
		db.Exec(context.Background(), "DROP PUBLICATION IF EXISTS "+pub).ReadAll()
		db.Exec(context.Background(), "DROP TABLE IF EXISTS public."+table).ReadAll()
	}()

	raw, _ := json.Marshal(map[string]any{"dsn": dsn, "publication": pub, "create_publication": true, "tables": []string{"public." + table},
		"slot": slot, "status_interval": "200ms"})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	var got []source.Event
	runCtx, stop := context.WithCancel(ctx)
	done := make(chan error, 1)
	go func() {
		done <- s.Run(runCtx, func(_ context.Context, ev source.Event) error {
			mu.Lock()
			got = append(got, ev)
			mu.Unlock()
			return nil
		})
	}()
	// The slot exists once the source started.
	for ctx.Err() == nil {
		res, _ := db.Exec(ctx, "SELECT 1 FROM pg_replication_slots WHERE slot_name = '"+slot+"' AND active").ReadAll()
		if len(res) > 0 && len(res[0].Rows) > 0 {
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	exec("INSERT INTO public." + table + " VALUES (1, 'new')")
	exec("UPDATE public." + table + " SET status = 'paid' WHERE id = 1")
	exec("DELETE FROM public." + table + " WHERE id = 1")
	for {
		mu.Lock()
		n := len(got)
		mu.Unlock()
		if n >= 3 || ctx.Err() != nil {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	time.Sleep(500 * time.Millisecond) // a status update confirms the last transaction
	stop()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(got) != 3 || got[0].Type != "dev.runr.cdc.insert" || got[1].Type != "dev.runr.cdc.update" || got[2].Type != "dev.runr.cdc.delete" ||
		got[0].Subject != "public."+table {
		t.Fatalf("events %+v", got)
	}
	var del changeData
	json.Unmarshal(got[2].Data, &del)
	if del.Old == nil || del.Old["id"] == nil || *del.Old["id"] != "1" || del.New != nil {
		t.Errorf("delete data %s", got[2].Data)
	}

	// Everything was confirmed: a new run delivers nothing again.
	s2, _ := New(raw)
	ctx2, cancel2 := context.WithTimeout(ctx, 3*time.Second)
	defer cancel2()
	again := 0
	s2.Run(ctx2, func(context.Context, source.Event) error { again++; return nil })
	if again != 0 {
		t.Fatalf("%d changes delivered again after they were confirmed", again)
	}

	// The trigger is removed: the cleanup drops the slot and the publication the source created
	// (and marked).
	res := connectAndClean(ctx, CleanupConfig{DSN: dsn, Slots: []string{slot}, Publication: pub, DropPublication: true, Wait: gateway.Duration(10 * time.Second)})
	if !res.OK() || res.Slots[0].Status != "dropped" || res.Publication == nil || res.Publication.Status != "dropped" {
		t.Fatalf("cleanup %+v", res)
	}
	left, _ := db.Exec(ctx, "SELECT 1 FROM pg_replication_slots WHERE slot_name = '"+slot+"' UNION ALL SELECT 1 FROM pg_publication WHERE pubname = '"+pub+"'").ReadAll()
	if len(left) > 0 && len(left[0].Rows) > 0 {
		t.Fatalf("the slot or the publication is still there")
	}
}
