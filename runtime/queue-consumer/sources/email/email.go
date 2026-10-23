// Package email is the gateway source of kind "email": inbound email, polled from an IMAP
// mailbox.
//
// Configuration (the trigger's "source" object):
//
//	{"host": "imap.example.com", "port": 993, "tls": "implicit", "username": "...", "password": "...",
//	 "mailbox": "INBOX", "poll_interval": "30s", "after": "mark_seen", "move_to": "Processed",
//	 "max_message_kb": 10240, "include_raw": false}
//
// host, username and password (never logged) are required. tls is "implicit" (default, port
// 993), "starttls" or "none" (both port 143 by default). after is what happens to a message once
// its event settled: "mark_seen" (default) sets \Seen, "move" moves it to move_to (default
// "Processed", created when missing), "delete" sets \Deleted and expunges the mailbox.
// max_message_kb (default 10240) is the largest message delivered whole; include_raw adds the
// message as received.
//
// Each unseen message is the event
//
//	id              the Message-Id header without <>, else <mailbox>/<uidvalidity>/<uid>
//	type            dev.runr.email.received
//	source          /email/<username>/<mailbox>
//	subject         the Subject header
//	time            the Date header (missing or invalid: the server's internal date)
//	datacontenttype application/json
//	data            {"message_id", "from", "to": [...], "cc": [...], "reply_to", "subject",
//	                 "date", "text", "html", "headers": {"<name>": "<value>"},
//	                 "attachments": [{"filename", "content_type", "size", "content_base64"}],
//	                 "raw_base64" (include_raw), "truncated": false}
//
// Header values are decoded to UTF-8 (repeated headers joined by ", "); text and html are the
// text/plain and text/html parts. A message larger than max_message_kb is delivered with its
// header only and "truncated": true. A message whose MIME structure cannot be parsed is
// delivered with what could be read and raw_base64.
//
// Delivery guarantees:
//   - Every poll_interval (or when the trigger is nudged) the source searches the mailbox for
//     unseen messages and fetches them with BODY.PEEK, which leaves them unseen. They are
//     delivered in batches of 2×concurrency, in parallel within a batch, and the after action is
//     applied to the messages of the batch whose events settled. A message whose event did not
//     settle (shutdown), or whose after action failed, stays unseen and is delivered again by a
//     later poll (at-least-once).
//   - When moving or deleting fails, the message is marked seen instead, so it is not delivered
//     again and again.
//   - Messages someone else marks seen before a poll are not delivered.
package email

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/emersion/go-imap"
	"github.com/emersion/go-imap/client"
	"github.com/emersion/go-message"
	_ "github.com/emersion/go-message/charset" // decodes non-UTF-8 charsets
	"github.com/emersion/go-message/mail"

	"github.com/brf-tech/runr/runtime/queue-consumer/gateway"
	"github.com/brf-tech/runr/runtime/queue-consumer/source"
)

func init() { source.Register("email", New) }

// Config is the "source" object of an email trigger.
type Config struct {
	Host         string           `json:"host"`
	Port         int              `json:"port"` // default 993 (implicit TLS), else 143
	TLS          string           `json:"tls"`  // "implicit" (default), "starttls" or "none"
	Username     string           `json:"username"`
	Password     string           `json:"password"`
	Mailbox      string           `json:"mailbox"`        // default INBOX
	PollInterval gateway.Duration `json:"poll_interval"`  // default 30s
	After        string           `json:"after"`          // "mark_seen" (default), "move" or "delete"
	MoveTo       string           `json:"move_to"`        // default Processed
	MaxMessageKB int              `json:"max_message_kb"` // default 10240
	IncludeRaw   bool             `json:"include_raw"`
}

// mailbox is the selected IMAP mailbox the source works on.
type mailbox interface {
	// unseen returns the UIDs of the unseen messages, ascending.
	unseen() ([]uint32, error)
	// fetch returns the messages of uids; a message larger than maxBytes comes with its header
	// only. Messages expunged meanwhile are missing.
	fetch(uids []uint32, maxBytes int64) ([]fetched, error)
	markSeen(uids []uint32) error
	move(uids []uint32, dest string) error
	delete(uids []uint32) error
	uidValidity() uint32
	close() error
}

// fetched is one fetched message.
type fetched struct {
	uid          uint32
	size         int64
	internalDate time.Time
	raw          []byte // the message, or its header when truncated
	truncated    bool
}

// Source polls one mailbox.
type Source struct {
	cfg      Config
	poll     time.Duration
	maxBytes int64
	dial     func() (mailbox, error)
}

// New builds an email source from its configuration.
func New(raw json.RawMessage) (source.Source, error) {
	var c Config
	if err := json.Unmarshal(raw, &c); err != nil {
		return nil, err
	}
	if c.Host == "" {
		return nil, errors.New("host is required")
	}
	if c.Username == "" || c.Password == "" {
		return nil, errors.New("username and password are required")
	}
	switch c.TLS {
	case "", "implicit":
		c.TLS = "implicit"
		if c.Port == 0 {
			c.Port = 993
		}
	case "starttls", "none":
		if c.Port == 0 {
			c.Port = 143
		}
	default:
		return nil, fmt.Errorf("tls %q: want implicit, starttls or none", c.TLS)
	}
	if c.Port < 0 || c.Port > 65535 {
		return nil, fmt.Errorf("port %d is not a TCP port", c.Port)
	}
	if c.Mailbox == "" {
		c.Mailbox = "INBOX"
	}
	switch c.After {
	case "":
		c.After = "mark_seen"
	case "mark_seen", "delete":
	case "move":
		if c.MoveTo == "" {
			c.MoveTo = "Processed"
		}
		if c.MoveTo == c.Mailbox {
			return nil, errors.New("move_to must differ from mailbox")
		}
	default:
		return nil, fmt.Errorf("after %q: want mark_seen, move or delete", c.After)
	}
	if c.MaxMessageKB < 0 {
		return nil, errors.New("max_message_kb must not be negative")
	}
	if c.MaxMessageKB == 0 {
		c.MaxMessageKB = 10240
	}
	s := &Source{cfg: c, poll: c.PollInterval.D(), maxBytes: int64(c.MaxMessageKB) << 10}
	if s.poll <= 0 {
		s.poll = 30 * time.Second
	}
	s.dial = func() (mailbox, error) { return dialIMAP(s.cfg) }
	return s, nil
}

// Name is "email <username>/<mailbox>@<host>:<port>".
func (s *Source) Name() string {
	return fmt.Sprintf("email %s/%s@%s", s.cfg.Username, s.cfg.Mailbox, net.JoinHostPort(s.cfg.Host, strconv.Itoa(s.cfg.Port)))
}

// Run polls until ctx ends. The connection is kept between polls; after a failure the source
// logs it and connects again.
func (s *Source) Run(ctx context.Context, emit func(context.Context, source.Event) error) error {
	nudges := source.Nudges(ctx)
	window := 2 * source.Concurrency(ctx)
	errWait := min(s.poll, 5*time.Second)
	var mb mailbox
	defer func() {
		if mb != nil {
			mb.close()
		}
	}()
	for ctx.Err() == nil {
		if mb == nil {
			var err error
			if mb, err = s.dial(); err != nil {
				mb = nil
				log.Printf("%s: connect failed, retry in %s: %v", s.Name(), s.poll, err)
				s.sleep(ctx, s.poll, nudges)
				continue
			}
		}
		if err := s.pollOnce(ctx, mb, emit, window); err != nil {
			log.Printf("%s: poll failed, reconnecting in %s: %v", s.Name(), errWait, err)
			mb.close()
			mb = nil
			s.sleep(ctx, errWait, nudges)
			continue
		}
		s.sleep(ctx, s.poll, nudges)
	}
	return nil
}

func (s *Source) sleep(ctx context.Context, d time.Duration, nudges <-chan struct{}) {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	case <-nudges:
	}
}

// pollOnce delivers the unseen messages in batches of window and applies the after action to
// the settled ones of each batch.
func (s *Source) pollOnce(ctx context.Context, mb mailbox, emit func(context.Context, source.Event) error, window int) error {
	uids, err := mb.unseen()
	if err != nil {
		return fmt.Errorf("search: %w", err)
	}
	for start := 0; start < len(uids) && ctx.Err() == nil; start += window {
		batch := uids[start:min(start+window, len(uids))]
		msgs, err := mb.fetch(batch, s.maxBytes)
		if err != nil {
			return fmt.Errorf("fetch: %w", err)
		}
		var (
			mu      sync.Mutex
			settled []uint32
			wg      sync.WaitGroup
		)
		for _, f := range msgs {
			ev := s.event(f, mb.uidValidity())
			wg.Add(1)
			go func() {
				defer wg.Done()
				if source.Settled(emit(ctx, ev)) {
					mu.Lock()
					settled = append(settled, f.uid)
					mu.Unlock()
				}
			}()
		}
		wg.Wait()
		if len(settled) > 0 {
			sort.Slice(settled, func(i, j int) bool { return settled[i] < settled[j] })
			if err := s.finish(mb, settled); err != nil {
				return err
			}
		}
	}
	return nil
}

// finish applies the after action to settled messages; when moving or deleting fails it marks
// them seen, so they are not delivered again.
func (s *Source) finish(mb mailbox, uids []uint32) error {
	var err error
	switch s.cfg.After {
	case "move":
		err = mb.move(uids, s.cfg.MoveTo)
	case "delete":
		err = mb.delete(uids)
	default:
		if err := mb.markSeen(uids); err != nil {
			return fmt.Errorf("mark seen: %w", err)
		}
		return nil
	}
	if err == nil {
		return nil
	}
	log.Printf("%s: %s of %d delivered messages failed, marking them seen instead: %v", s.Name(), s.cfg.After, len(uids), err)
	if err := mb.markSeen(uids); err != nil {
		return fmt.Errorf("mark seen: %w", err)
	}
	return nil
}

// emailData is the data of an email event.
type emailData struct {
	MessageID   string            `json:"message_id"`
	From        string            `json:"from"`
	To          []string          `json:"to"`
	Cc          []string          `json:"cc"`
	ReplyTo     string            `json:"reply_to"`
	Subject     string            `json:"subject"`
	Date        string            `json:"date"`
	Text        string            `json:"text"`
	HTML        string            `json:"html"`
	Headers     map[string]string `json:"headers"`
	Attachments []attachment      `json:"attachments"`
	RawBase64   string            `json:"raw_base64,omitempty"`
	Truncated   bool              `json:"truncated"`
}

type attachment struct {
	Filename      string `json:"filename"`
	ContentType   string `json:"content_type"`
	Size          int    `json:"size"`
	ContentBase64 string `json:"content_base64"`
}

// event maps a fetched message to the event to deliver.
func (s *Source) event(f fetched, uidValidity uint32) source.Event {
	d, date := s.parse(f)
	ev := source.Event{
		ID:              d.MessageID,
		Type:            "dev.runr.email.received",
		Source:          "/email/" + s.cfg.Username + "/" + s.cfg.Mailbox,
		Subject:         d.Subject,
		Time:            date,
		DataContentType: "application/json",
	}
	if ev.ID == "" {
		ev.ID = fmt.Sprintf("%s/%d/%d", s.cfg.Mailbox, uidValidity, f.uid)
	}
	ev.Data, _ = json.Marshal(d)
	return ev
}

// parse reads a fetched message into the event data; date is the Date header, else the
// internal date.
func (s *Source) parse(f fetched) (emailData, time.Time) {
	d := emailData{To: []string{}, Cc: []string{}, Headers: map[string]string{}, Attachments: []attachment{}, Truncated: f.truncated}
	date := f.internalDate
	if s.cfg.IncludeRaw && !f.truncated {
		d.RawBase64 = base64.StdEncoding.EncodeToString(f.raw)
	}
	mr, err := mail.CreateReader(bytes.NewReader(f.raw))
	if mr == nil {
		if !f.truncated {
			d.RawBase64 = base64.StdEncoding.EncodeToString(f.raw) // unparsable: hand over what arrived
		}
		log.Printf("%s: message uid %d is not a parsable message: %v", s.Name(), f.uid, err)
		return d, date
	}
	h := mr.Header
	d.MessageID, _ = h.MessageID()
	d.From = strings.Join(addresses(h, "From"), ", ")
	d.To = append(d.To, addresses(h, "To")...)
	d.Cc = append(d.Cc, addresses(h, "Cc")...)
	d.ReplyTo = strings.Join(addresses(h, "Reply-To"), ", ")
	d.Subject, _ = h.Subject()
	if t, err := h.Date(); err == nil && !t.IsZero() {
		date = t
	}
	if !date.IsZero() {
		d.Date = date.Format(time.RFC3339)
	}
	fields := h.Fields()
	for fields.Next() {
		v, err := fields.Text()
		if err != nil {
			v = fields.Value()
		}
		if prev, ok := d.Headers[fields.Key()]; ok {
			v = prev + ", " + v
		}
		d.Headers[fields.Key()] = v
	}
	if f.truncated {
		return d, date
	}

	budget := s.maxBytes // decoded attachment bytes left
	for {
		p, err := mr.NextPart()
		if err == io.EOF {
			break
		}
		if p == nil {
			d.RawBase64 = base64.StdEncoding.EncodeToString(f.raw) // broken MIME: hand over what arrived
			log.Printf("%s: message uid %d: MIME part: %v", s.Name(), f.uid, err)
			break
		}
		ct, filename, isText := classify(p.Header)
		body, err := io.ReadAll(io.LimitReader(p.Body, budget+1))
		if err != nil && !message.IsUnknownCharset(err) {
			log.Printf("%s: message uid %d: reading a part: %v", s.Name(), f.uid, err)
		}
		switch {
		case isText && ct == "text/plain":
			d.Text = joinText(d.Text, string(body))
		case isText && ct == "text/html":
			d.HTML = joinText(d.HTML, string(body))
		default:
			a := attachment{Filename: filename, ContentType: ct, Size: len(body)}
			if int64(len(body)) > budget {
				d.Truncated = true // over max_message_kb once decoded: left out
			} else {
				a.ContentBase64 = base64.StdEncoding.EncodeToString(body)
				budget -= int64(len(body))
			}
			d.Attachments = append(d.Attachments, a)
		}
	}
	return d, date
}

// classify returns a part's media type (text/plain when missing, RFC 2045), its file name and
// whether it is message text: a text/plain or text/html part that is not an attachment.
func classify(ph mail.PartHeader) (ct, filename string, isText bool) {
	var h message.Header
	switch p := ph.(type) {
	case *mail.InlineHeader:
		h = p.Header
	case *mail.AttachmentHeader:
		h = p.Header
	}
	ct, params, _ := h.ContentType()
	if ct == "" {
		ct = "text/plain"
	}
	disp, dparams, _ := h.ContentDisposition()
	filename = dparams["filename"]
	if filename == "" {
		filename = params["name"]
	}
	isText = disp != "attachment" && filename == "" && (ct == "text/plain" || ct == "text/html")
	return ct, filename, isText
}

func joinText(have, more string) string {
	if have == "" {
		return more
	}
	return have + "\n" + more
}

// addresses formats the addresses of header key as "Name <addr>" (or "addr"); an unparsable
// header is passed on as its decoded text.
func addresses(h mail.Header, key string) []string {
	list, err := h.AddressList(key)
	if err != nil {
		if v, err := h.Text(key); err == nil && v != "" {
			return []string{v}
		}
		return nil
	}
	out := make([]string, 0, len(list))
	for _, a := range list {
		if a.Name != "" {
			out = append(out, a.Name+" <"+a.Address+">")
		} else {
			out = append(out, a.Address)
		}
	}
	return out
}

// imapMailbox is a mailbox on an IMAP server (go-imap v1).
type imapMailbox struct {
	c        *client.Client
	validity uint32
}

func dialIMAP(cfg Config) (*imapMailbox, error) {
	addr := net.JoinHostPort(cfg.Host, strconv.Itoa(cfg.Port))
	dialer := &net.Dialer{Timeout: 15 * time.Second}
	tc := &tls.Config{ServerName: cfg.Host, MinVersion: tls.VersionTLS12}
	var (
		c   *client.Client
		err error
	)
	if cfg.TLS == "implicit" {
		c, err = client.DialWithDialerTLS(dialer, addr, tc)
	} else {
		c, err = client.DialWithDialer(dialer, addr)
	}
	if err != nil {
		return nil, err
	}
	c.Timeout = 2 * time.Minute
	if cfg.TLS == "starttls" {
		if err := c.StartTLS(tc); err != nil {
			c.Terminate()
			return nil, fmt.Errorf("starttls: %w", err)
		}
	}
	if err := c.Login(cfg.Username, cfg.Password); err != nil {
		c.Terminate()
		return nil, fmt.Errorf("login: %w", err)
	}
	if cfg.After == "move" {
		c.Create(cfg.MoveTo) // fails when it exists, which is fine
	}
	status, err := c.Select(cfg.Mailbox, false)
	if err != nil {
		c.Logout()
		return nil, fmt.Errorf("select %s: %w", cfg.Mailbox, err)
	}
	return &imapMailbox{c: c, validity: status.UidValidity}, nil
}

func (m *imapMailbox) uidValidity() uint32 { return m.validity }

func (m *imapMailbox) unseen() ([]uint32, error) {
	criteria := imap.NewSearchCriteria()
	criteria.WithoutFlags = []string{imap.SeenFlag, imap.DeletedFlag}
	uids, err := m.c.UidSearch(criteria)
	if err != nil {
		return nil, err
	}
	sort.Slice(uids, func(i, j int) bool { return uids[i] < uids[j] })
	return uids, nil
}

func uidSet(uids []uint32) *imap.SeqSet {
	set := new(imap.SeqSet)
	set.AddNum(uids...)
	return set
}

// fetch fetches the sizes first, then the whole small messages and the headers of the large
// ones, all with BODY.PEEK so nothing is marked seen.
func (m *imapMailbox) fetch(uids []uint32, maxBytes int64) ([]fetched, error) {
	byUID := map[uint32]*fetched{}
	err := m.uidFetch(uids, []imap.FetchItem{imap.FetchUid, imap.FetchRFC822Size, imap.FetchInternalDate}, func(msg *imap.Message) {
		byUID[msg.Uid] = &fetched{uid: msg.Uid, size: int64(msg.Size), internalDate: msg.InternalDate}
	})
	if err != nil {
		return nil, err
	}
	var whole, headerOnly []uint32
	for uid, f := range byUID {
		if f.size > maxBytes {
			f.truncated = true
			headerOnly = append(headerOnly, uid)
		} else {
			whole = append(whole, uid)
		}
	}
	full := &imap.BodySectionName{Peek: true}
	header := &imap.BodySectionName{Peek: true, BodyPartName: imap.BodyPartName{Specifier: imap.HeaderSpecifier}}
	for _, part := range []struct {
		uids    []uint32
		section *imap.BodySectionName
	}{{whole, full}, {headerOnly, header}} {
		if len(part.uids) == 0 {
			continue
		}
		err := m.uidFetch(part.uids, []imap.FetchItem{imap.FetchUid, part.section.FetchItem()}, func(msg *imap.Message) {
			f := byUID[msg.Uid]
			if f == nil {
				return
			}
			if lit := msg.GetBody(part.section); lit != nil {
				f.raw, _ = io.ReadAll(lit)
			}
		})
		if err != nil {
			return nil, err
		}
	}
	out := make([]fetched, 0, len(byUID))
	for _, f := range byUID {
		if f.raw != nil {
			out = append(out, *f)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].uid < out[j].uid })
	return out, nil
}

func (m *imapMailbox) uidFetch(uids []uint32, items []imap.FetchItem, each func(*imap.Message)) error {
	ch := make(chan *imap.Message, 16)
	done := make(chan error, 1)
	go func() { done <- m.c.UidFetch(uidSet(uids), items, ch) }()
	for msg := range ch {
		each(msg)
	}
	return <-done
}

func (m *imapMailbox) markSeen(uids []uint32) error {
	return m.c.UidStore(uidSet(uids), imap.FormatFlagsOp(imap.AddFlags, true), []interface{}{imap.SeenFlag}, nil)
}

// move moves messages with MOVE (the client falls back to COPY + \Deleted + EXPUNGE where the
// server does not list it). A server that lists MOVE but refuses it gets that fallback too; when
// the copy fails as well (no such mailbox), the MOVE error is returned.
func (m *imapMailbox) move(uids []uint32, dest string) error {
	set := uidSet(uids)
	err := m.c.UidMove(set, dest)
	if err == nil {
		return nil
	}
	if cerr := m.c.UidCopy(set, dest); cerr != nil {
		return err
	}
	if serr := m.c.UidStore(set, imap.FormatFlagsOp(imap.AddFlags, true), []interface{}{imap.DeletedFlag}, nil); serr != nil {
		return serr
	}
	return m.c.Expunge(nil)
}

func (m *imapMailbox) delete(uids []uint32) error {
	if err := m.c.UidStore(uidSet(uids), imap.FormatFlagsOp(imap.AddFlags, true), []interface{}{imap.DeletedFlag}, nil); err != nil {
		return err
	}
	return m.c.Expunge(nil)
}

func (m *imapMailbox) close() error {
	if err := m.c.Logout(); err != nil {
		return m.c.Terminate()
	}
	return nil
}
