package email

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/emersion/go-imap"
	"github.com/emersion/go-imap/backend/memory"
	"github.com/emersion/go-imap/client"
	"github.com/emersion/go-imap/server"

	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func TestConfig(t *testing.T) {
	for name, tc := range map[string]struct{ cfg, wantErr string }{
		"no host":      {`{"username": "u", "password": "p"}`, "host"},
		"no password":  {`{"host": "h", "username": "u"}`, "username and password"},
		"tls":          {`{"host": "h", "username": "u", "password": "p", "tls": "ssl"}`, "tls"},
		"port":         {`{"host": "h", "username": "u", "password": "p", "port": 70000}`, "port"},
		"after":        {`{"host": "h", "username": "u", "password": "p", "after": "archive"}`, "after"},
		"move to self": {`{"host": "h", "username": "u", "password": "p", "after": "move", "move_to": "INBOX"}`, "move_to"},
		"max size":     {`{"host": "h", "username": "u", "password": "p", "max_message_kb": -1}`, "max_message_kb"},
	} {
		t.Run(name, func(t *testing.T) {
			_, err := New(json.RawMessage(tc.cfg))
			if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
				t.Fatalf("New(%s) = %v, want an error naming %q", tc.cfg, err, tc.wantErr)
			}
		})
	}
	s, err := New(json.RawMessage(`{"host": "imap.example.com", "username": "in@example.com", "password": "hunter2"}`))
	if err != nil {
		t.Fatal(err)
	}
	src := s.(*Source)
	c := src.cfg
	if c.Port != 993 || c.TLS != "implicit" || c.Mailbox != "INBOX" || c.After != "mark_seen" || src.poll != 30*time.Second || src.maxBytes != 10<<20 {
		t.Errorf("defaults %+v poll %s max %d", c, src.poll, src.maxBytes)
	}
	if src.Name() != "email in@example.com/INBOX@imap.example.com:993" || strings.Contains(src.Name(), "hunter2") {
		t.Errorf("Name %q", src.Name())
	}
	s, _ = New(json.RawMessage(`{"host": "h", "username": "u", "password": "p", "tls": "starttls", "after": "move"}`))
	if c := s.(*Source).cfg; c.Port != 143 || c.MoveTo != "Processed" {
		t.Errorf("starttls / move defaults %+v", c)
	}
}

const multipartMail = "From: =?UTF-8?Q?J=C3=B6rg?= <jorg@example.com>\r\n" +
	"To: a@example.com, \"Bee\" <b@example.com>\r\n" +
	"Cc: c@example.com\r\n" +
	"Reply-To: replies@example.com\r\n" +
	"Subject: =?UTF-8?B?w5xyw7xu?=\r\n" +
	"Date: Wed, 21 Oct 2026 10:00:00 +0000\r\n" +
	"Message-ID: <abc@example.com>\r\n" +
	"X-Tag: one\r\n" +
	"X-Tag: two\r\n" +
	"MIME-Version: 1.0\r\n" +
	"Content-Type: multipart/mixed; boundary=\"b1\"\r\n" +
	"\r\n" +
	"--b1\r\n" +
	"Content-Type: multipart/alternative; boundary=\"b2\"\r\n" +
	"\r\n" +
	"--b2\r\n" +
	"Content-Type: text/plain; charset=utf-8\r\n" +
	"\r\n" +
	"Hello\r\n" +
	"--b2\r\n" +
	"Content-Type: text/html; charset=utf-8\r\n" +
	"\r\n" +
	"<p>Hello</p>\r\n" +
	"--b2--\r\n" +
	"--b1\r\n" +
	"Content-Type: application/pdf\r\n" +
	"Content-Disposition: attachment; filename=\"invoice.pdf\"\r\n" +
	"Content-Transfer-Encoding: base64\r\n" +
	"\r\n" +
	"JVBERi0=\r\n" +
	"--b1--\r\n"

func testSource(t *testing.T, extra string) *Source {
	t.Helper()
	s, err := New(json.RawMessage(`{"host": "h", "username": "in@example.com", "password": "p"` + extra + `}`))
	if err != nil {
		t.Fatal(err)
	}
	return s.(*Source)
}

func TestParseMultipart(t *testing.T) {
	s := testSource(t, `, "include_raw": true`)
	internal := time.Date(2026, 10, 21, 11, 0, 0, 0, time.UTC)
	ev := s.event(fetched{uid: 7, size: int64(len(multipartMail)), internalDate: internal, raw: []byte(multipartMail)}, 1)
	if ev.ID != "abc@example.com" || ev.Type != "dev.runr.email.received" || ev.Source != "/email/in@example.com/INBOX" ||
		ev.Subject != "Ürün" || ev.DataContentType != "application/json" || !ev.Time.Equal(time.Date(2026, 10, 21, 10, 0, 0, 0, time.UTC)) {
		t.Errorf("event %+v", ev)
	}
	var d emailData
	if err := json.Unmarshal(ev.Data, &d); err != nil {
		t.Fatal(err)
	}
	if d.MessageID != "abc@example.com" || d.From != "Jörg <jorg@example.com>" || fmt.Sprint(d.To) != "[a@example.com Bee <b@example.com>]" ||
		fmt.Sprint(d.Cc) != "[c@example.com]" || d.ReplyTo != "replies@example.com" || d.Subject != "Ürün" || d.Truncated {
		t.Errorf("addresses / subject %+v", d)
	}
	if date, err := time.Parse(time.RFC3339, d.Date); err != nil || !date.Equal(ev.Time) {
		t.Errorf("date %q", d.Date)
	}
	if d.Text != "Hello" || d.HTML != "<p>Hello</p>" {
		t.Errorf("text %q html %q", d.Text, d.HTML)
	}
	if len(d.Attachments) != 1 || d.Attachments[0].Filename != "invoice.pdf" || d.Attachments[0].ContentType != "application/pdf" ||
		d.Attachments[0].Size != 5 || d.Attachments[0].ContentBase64 != base64.StdEncoding.EncodeToString([]byte("%PDF-")) {
		t.Errorf("attachments %+v", d.Attachments)
	}
	if d.Headers["X-Tag"] != "one, two" {
		t.Errorf("headers %v", d.Headers)
	}
	if raw, _ := base64.StdEncoding.DecodeString(d.RawBase64); string(raw) != multipartMail {
		t.Error("raw_base64 is not the message as received")
	}
}

func TestParseTruncatedAndFallbacks(t *testing.T) {
	s := testSource(t, `, "include_raw": true`)
	internal := time.Date(2026, 10, 21, 11, 0, 0, 0, time.UTC)
	header := "Subject: big\r\nFrom: x@example.com\r\n\r\n"
	ev := s.event(fetched{uid: 9, size: 50 << 20, internalDate: internal, raw: []byte(header), truncated: true}, 4)
	var d emailData
	json.Unmarshal(ev.Data, &d)
	if !d.Truncated || d.Subject != "big" || d.Text != "" || d.RawBase64 != "" || len(d.Attachments) != 0 {
		t.Errorf("truncated data %+v", d)
	}
	// no Message-Id: mailbox/uidvalidity/uid; no Date: the internal date
	if ev.ID != "INBOX/4/9" || !ev.Time.Equal(internal) {
		t.Errorf("event %+v", ev)
	}
	// to, cc and attachments are [] (not null) when empty
	if !strings.Contains(string(ev.Data), `"to":[]`) || !strings.Contains(string(ev.Data), `"attachments":[]`) {
		t.Errorf("data %s", ev.Data)
	}
}

// fakeMailbox is a mailbox in memory; it records what was applied to which UIDs.
type fakeMailbox struct {
	mu       sync.Mutex
	msgs     map[uint32]string
	seen     map[uint32]bool
	moved    []uint32
	deleted  []uint32
	moveErr  error
	fetchErr error
	fetches  [][]uint32
}

func newFakeMailbox(n int) *fakeMailbox {
	m := &fakeMailbox{msgs: map[uint32]string{}, seen: map[uint32]bool{}}
	for i := 1; i <= n; i++ {
		m.msgs[uint32(i)] = fmt.Sprintf("Message-ID: <m%d@x>\r\nSubject: s%d\r\n\r\nbody\r\n", i, i)
	}
	return m
}

func (m *fakeMailbox) unseen() ([]uint32, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	var out []uint32
	for uid := uint32(1); uid <= uint32(len(m.msgs)); uid++ {
		if _, ok := m.msgs[uid]; ok && !m.seen[uid] {
			out = append(out, uid)
		}
	}
	return out, nil
}

func (m *fakeMailbox) fetch(uids []uint32, maxBytes int64) ([]fetched, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.fetches = append(m.fetches, append([]uint32(nil), uids...))
	if m.fetchErr != nil {
		return nil, m.fetchErr
	}
	var out []fetched
	for _, uid := range uids {
		out = append(out, fetched{uid: uid, raw: []byte(m.msgs[uid])})
	}
	return out, nil
}

func (m *fakeMailbox) markSeen(uids []uint32) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, uid := range uids {
		m.seen[uid] = true
	}
	return nil
}

func (m *fakeMailbox) move(uids []uint32, dest string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.moveErr != nil {
		return m.moveErr
	}
	m.moved = append(m.moved, uids...)
	return nil
}

func (m *fakeMailbox) delete(uids []uint32) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.deleted = append(m.deleted, uids...)
	return nil
}

func (m *fakeMailbox) uidValidity() uint32 { return 1 }
func (m *fakeMailbox) close() error        { return nil }

func TestPollMarksSettledSeenInBatches(t *testing.T) {
	s := testSource(t, "")
	mb := newFakeMailbox(5)
	emit := func(_ context.Context, ev source.Event) error {
		switch ev.ID {
		case "m2@x":
			return fmt.Errorf("%w after 6 attempts", source.ErrDeadLettered)
		case "m4@x":
			return context.Canceled // interrupted: stays unseen
		}
		return nil
	}
	if err := s.pollOnce(context.Background(), mb, emit, 2); err != nil {
		t.Fatal(err)
	}
	if fmt.Sprint(mb.fetches) != "[[1 2] [3 4] [5]]" {
		t.Errorf("fetches %v, want batches of the window", mb.fetches)
	}
	if uids, _ := mb.unseen(); fmt.Sprint(uids) != "[4]" {
		t.Errorf("unseen after the poll %v, want only the interrupted message", uids)
	}
}

func TestMoveFallsBackToSeen(t *testing.T) {
	s := testSource(t, `, "after": "move"`)
	mb := newFakeMailbox(2)
	ok := func(context.Context, source.Event) error { return nil }
	if err := s.pollOnce(context.Background(), mb, ok, 10); err != nil {
		t.Fatal(err)
	}
	if fmt.Sprint(mb.moved) != "[1 2]" || len(mb.seen) != 0 {
		t.Errorf("moved %v seen %v", mb.moved, mb.seen)
	}

	mb = newFakeMailbox(2)
	mb.moveErr = errors.New("NO [TRYCREATE] no such mailbox")
	if err := s.pollOnce(context.Background(), mb, ok, 10); err != nil {
		t.Fatal(err)
	}
	if uids, _ := mb.unseen(); len(uids) != 0 {
		t.Errorf("unseen %v after a failed move, want them marked seen", uids)
	}

	s = testSource(t, `, "after": "delete"`)
	mb = newFakeMailbox(1)
	s.pollOnce(context.Background(), mb, ok, 10)
	if fmt.Sprint(mb.deleted) != "[1]" {
		t.Errorf("deleted %v", mb.deleted)
	}
}

func TestPollErrorReconnects(t *testing.T) {
	s := testSource(t, `, "poll_interval": "10ms"`)
	broken := newFakeMailbox(1)
	broken.fetchErr = errors.New("connection reset")
	healthy := newFakeMailbox(1)
	var dials int
	s.dial = func() (mailbox, error) {
		dials++
		switch dials {
		case 1:
			return nil, errors.New("dial tcp: i/o timeout")
		case 2:
			return broken, nil
		}
		return healthy, nil
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	err := s.Run(ctx, func(context.Context, source.Event) error { cancel(); return nil })
	if err != nil || dials != 3 {
		t.Fatalf("Run = %v after %d dials, want a reconnect after the failed dial and the failed poll", err, dials)
	}
	if !healthy.seen[1] {
		t.Error("the delivered message was not marked seen")
	}
}

// ── An in-process IMAP server (go-imap's memory backend) ────────────────────────────────

func startIMAP(t *testing.T, mails ...string) string {
	t.Helper()
	be := memory.New()
	u, err := be.Login(nil, "username", "password")
	if err != nil {
		t.Fatal(err)
	}
	inbox, err := u.GetMailbox("INBOX")
	if err != nil {
		t.Fatal(err)
	}
	for _, m := range mails {
		if err := inbox.CreateMessage(nil, time.Now(), bytes.NewBufferString(m)); err != nil {
			t.Fatal(err)
		}
	}
	srv := server.New(be)
	srv.AllowInsecureAuth = true
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	go srv.Serve(l)
	t.Cleanup(func() { srv.Close() })
	return l.Addr().String()
}

// mailboxState lists the unseen UIDs of mailbox and how many messages it holds.
func mailboxState(t *testing.T, addr, name string) ([]uint32, uint32) {
	t.Helper()
	c, err := client.Dial(addr)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Logout()
	if err := c.Login("username", "password"); err != nil {
		t.Fatal(err)
	}
	st, err := c.Select(name, true)
	if err != nil {
		t.Fatal(err)
	}
	crit := imap.NewSearchCriteria()
	crit.WithoutFlags = []string{imap.SeenFlag}
	uids, err := c.UidSearch(crit)
	if err != nil {
		t.Fatal(err)
	}
	return uids, st.Messages
}

func runUntil(t *testing.T, s *Source, n int) []source.Event {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	var mu sync.Mutex
	var got []source.Event
	done := make(chan error, 1)
	go func() {
		done <- s.Run(ctx, func(_ context.Context, ev source.Event) error {
			mu.Lock()
			defer mu.Unlock()
			got = append(got, ev)
			if len(got) == n {
				cancel()
			}
			return nil
		})
	}()
	if err := <-done; err != nil {
		t.Fatalf("Run = %v", err)
	}
	mu.Lock()
	defer mu.Unlock()
	return got
}

func TestIMAPServerMarkSeen(t *testing.T) {
	big := "Message-ID: <big@x>\r\nSubject: big\r\n\r\n" + strings.Repeat("x", 2048) + "\r\n"
	addr := startIMAP(t, multipartMail, big)
	host, port, _ := net.SplitHostPort(addr)
	s := testSource(t, "")
	s.cfg.Host, s.cfg.Port, s.cfg.TLS, s.cfg.Username, s.cfg.Password = host, mustAtoi(port), "none", "username", "password"
	s.maxBytes = 1024 // the second message is delivered with its header only

	got := runUntil(t, s, 2)
	byID := map[string]emailData{}
	for _, ev := range got {
		var d emailData
		json.Unmarshal(ev.Data, &d)
		byID[ev.ID] = d
	}
	if d := byID["abc@example.com"]; d.Text != "Hello" || len(d.Attachments) != 1 || d.Truncated {
		t.Errorf("multipart message %+v", d)
	}
	if d := byID["big@x"]; !d.Truncated || d.Subject != "big" {
		t.Errorf("big message %+v", d)
	}
	// Both are seen now; the memory backend's own sample message was seen before.
	if unseen, _ := mailboxState(t, addr, "INBOX"); len(unseen) != 0 {
		t.Errorf("unseen %v after delivery", unseen)
	}
}

func TestIMAPServerMove(t *testing.T) {
	addr := startIMAP(t, multipartMail)
	host, port, _ := net.SplitHostPort(addr)
	s := testSource(t, `, "after": "move", "move_to": "Done"`)
	s.cfg.Host, s.cfg.Port, s.cfg.TLS, s.cfg.Username, s.cfg.Password = host, mustAtoi(port), "none", "username", "password"

	runUntil(t, s, 1)
	time.Sleep(100 * time.Millisecond) // the move follows the settled delivery
	if _, n := mailboxState(t, addr, "Done"); n != 1 {
		t.Errorf("Done holds %d messages, want the moved one", n)
	}
	if _, n := mailboxState(t, addr, "INBOX"); n != 1 {
		t.Errorf("INBOX holds %d messages, want only the backend's sample message", n)
	}
}

func mustAtoi(s string) int {
	n, _ := strconv.Atoi(s)
	return n
}

// ── Integration: a real IMAP server with QC_TEST_IMAP=host:port QC_TEST_IMAP_USER=…
// QC_TEST_IMAP_PASSWORD=… go test ./sources/email (implicit TLS; it marks the unseen INBOX
// messages seen) ──

func TestIMAPAccount(t *testing.T) {
	addr := os.Getenv("QC_TEST_IMAP")
	if addr == "" {
		t.Skip("QC_TEST_IMAP not set")
	}
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := json.Marshal(map[string]any{"host": host, "port": mustAtoi(port), "username": os.Getenv("QC_TEST_IMAP_USER"),
		"password": os.Getenv("QC_TEST_IMAP_PASSWORD"), "poll_interval": "1s"})
	s, err := New(raw)
	if err != nil {
		t.Fatal(err)
	}
	mb, err := s.(*Source).dial()
	if err != nil {
		t.Fatalf("connect: %v", err)
	}
	defer mb.close()
	if _, err := mb.unseen(); err != nil {
		t.Fatalf("search: %v", err)
	}
}
