package cdc

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgconn"
)

// fakeDB answers Cleanup's queries from a table of slots and publications and records them.
type fakeDB struct {
	slots    map[string][]bool // slot → its "active" answers in turn (the last one repeats)
	pubs     map[string]string // publication → comment
	dropErr  error
	executed []string
}

func (f *fakeDB) query(_ context.Context, sql string) ([][][]byte, error) {
	f.executed = append(f.executed, sql)
	name := func() string { // the last quoted literal
		s := sql[:max(strings.LastIndex(sql, "'"), 0)]
		return s[strings.LastIndex(s, "'")+1:]
	}
	switch {
	case strings.Contains(sql, "FROM pg_catalog.pg_replication_slots"):
		answers, ok := f.slots[name()]
		if !ok {
			return nil, nil
		}
		active := answers[0]
		if len(answers) > 1 {
			f.slots[name()] = answers[1:]
		}
		v := "false"
		if active {
			v = "true"
		}
		return [][][]byte{{[]byte(v), []byte("4242")}}, nil
	case strings.Contains(sql, "pg_drop_replication_slot"):
		if f.dropErr != nil {
			return nil, f.dropErr
		}
		delete(f.slots, name())
		return [][][]byte{{nil}}, nil
	case strings.Contains(sql, "FROM pg_catalog.pg_publication"):
		comment, ok := f.pubs[name()]
		if !ok {
			return nil, nil
		}
		return [][][]byte{{[]byte(comment)}}, nil
	case strings.HasPrefix(sql, "DROP PUBLICATION"):
		return nil, nil
	}
	return nil, errors.New("unexpected query " + sql)
}

func TestCleanupDropsSlotsAndRunrsPublication(t *testing.T) {
	db := &fakeDB{
		slots: map[string][]bool{"runr_shop_orders": {false}, "runr_shop_staging_orders": {true, true, false}},
		pubs:  map[string]string{"runr_orders": publicationComment("runr_shop_orders")},
	}
	res := cleanup(context.Background(), db, CleanupConfig{
		Slots:       []string{"runr_shop_orders", "runr_shop_staging_orders", "runr_gone"},
		Publication: "runr_orders", DropPublication: true, Wait: 0,
	}, CleanupResult{Database: "db/shop"}, time.Millisecond)

	want := []CleanupItem{
		{Name: "runr_shop_orders", Status: "dropped"},
		{Name: "runr_shop_staging_orders", Status: "dropped"}, // waited until it was no longer active
		{Name: "runr_gone", Status: "absent"},
	}
	if len(res.Slots) != 3 || res.Slots[0] != want[0] || res.Slots[1] != want[1] || res.Slots[2] != want[2] {
		t.Fatalf("slots %+v", res.Slots)
	}
	if res.Publication == nil || res.Publication.Status != "dropped" {
		t.Fatalf("publication %+v", res.Publication)
	}
	if !res.OK() {
		t.Error("OK = false")
	}
	if last := db.executed[len(db.executed)-1]; last != `DROP PUBLICATION IF EXISTS "runr_orders"` {
		t.Errorf("last statement %q", last)
	}
}

func TestCleanupKeepsWhatIsNotRunrsOrStillRead(t *testing.T) {
	db := &fakeDB{
		slots: map[string][]bool{"runr_busy": {true}},
		pubs:  map[string]string{"orders": "the team's publication"},
	}
	res := cleanup(context.Background(), db, CleanupConfig{
		Slots: []string{"runr_busy"}, Publication: "orders", DropPublication: true, Wait: 1,
	}, CleanupResult{}, time.Millisecond)
	if res.Slots[0].Status != "in_use" || !strings.Contains(res.Slots[0].Detail, "4242") {
		t.Errorf("busy slot %+v", res.Slots[0])
	}
	if res.Publication.Status != "kept" {
		t.Errorf("publication %+v, want kept", res.Publication)
	}
	if res.OK() {
		t.Error("OK = true with a slot in use")
	}
	for _, sql := range db.executed {
		if strings.Contains(sql, "pg_drop_replication_slot") || strings.HasPrefix(sql, "DROP PUBLICATION") {
			t.Errorf("dropped although it should not: %s", sql)
		}
	}

	// without drop_publication the publication is not looked at
	db = &fakeDB{slots: map[string][]bool{}}
	res = cleanup(context.Background(), db, CleanupConfig{Slots: []string{"runr_x"}, Publication: "orders"}, CleanupResult{}, time.Millisecond)
	if res.Publication != nil || len(db.executed) != 1 {
		t.Errorf("publication %+v, queries %v", res.Publication, db.executed)
	}

	// a slot dropped by someone else in between is absent, another error a failure
	db = &fakeDB{slots: map[string][]bool{"runr_x": {false}}, dropErr: &pgconn.PgError{Code: "42704"}}
	if res := cleanup(context.Background(), db, CleanupConfig{Slots: []string{"runr_x"}}, CleanupResult{}, time.Millisecond); res.Slots[0].Status != "absent" {
		t.Errorf("gone meanwhile: %+v", res.Slots[0])
	}
	db = &fakeDB{slots: map[string][]bool{"runr_x": {false}}, dropErr: errors.New("permission denied")}
	if res := cleanup(context.Background(), db, CleanupConfig{Slots: []string{"runr_x"}}, CleanupResult{}, time.Millisecond); res.Slots[0].Status != "failed" || res.OK() {
		t.Errorf("drop error: %+v", res.Slots[0])
	}
}

// An unreachable database is a result (with the reason, never the password), not an error.
func TestRunCleanupReportsAnUnreachableDatabase(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "cleanup.json")
	raw, _ := json.Marshal(CleanupConfig{DSN: "postgres://runr:s3cret@127.0.0.1:1/shop?connect_timeout=2", Slots: []string{"runr_shop_orders"}})
	os.WriteFile(path, raw, 0o600)

	var out bytes.Buffer
	if err := RunCleanup(context.Background(), func(k string) string { return map[string]string{"CLEANUP_CONFIG": path}[k] }, &out); err != nil {
		t.Fatal(err)
	}
	line := strings.TrimSpace(out.String())
	if !strings.HasPrefix(line, "RUNR_RESULT ") || strings.Contains(line, "s3cret") {
		t.Fatalf("output %q", line)
	}
	var res CleanupResult
	if err := json.Unmarshal([]byte(strings.TrimPrefix(line, "RUNR_RESULT ")), &res); err != nil {
		t.Fatal(err)
	}
	if res.Reachable || res.Error == "" || res.Database != "127.0.0.1/shop" || res.OK() {
		t.Errorf("result %+v", res)
	}

	for name, cfg := range map[string]string{
		"no dsn":   `{"slots": ["a"]}`,
		"bad slot": `{"dsn": "postgres://x@db/y", "slots": ["Bad-Slot"]}`,
		"not json": `{`,
	} {
		os.WriteFile(path, []byte(cfg), 0o600)
		if err := RunCleanup(context.Background(), func(k string) string { return map[string]string{"CLEANUP_CONFIG": path}[k] }, &out); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	if err := RunCleanup(context.Background(), func(string) string { return "" }, &out); err == nil {
		t.Error("no CLEANUP_CONFIG accepted")
	}
}
