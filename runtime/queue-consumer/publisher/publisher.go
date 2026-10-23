// Package publisher is the region's topic publisher (GATEWAY_MODE=publisher): functions and the
// RunR API publish events to a topic over HTTP, the publisher appends each one to the topic's
// Redis stream, and every subscribed trigger reads that stream with a redis-stream source in
// topic format.
//
// Environment:
//
//	REDIS_ADDRESS       localhost:6379 (host:port, redis://, rediss:// = TLS; userinfo ignored)
//	REDIS_PASSWORD
//	TOPICS_SIGNING_KEY  required: the HMAC key of the publish tokens (shared with the API)
//	TOPIC_MAXLEN        10000: entries a topic stream keeps (XADD MAXLEN ~)
//	PUBLISH_MAX_BYTES   262144: largest event body
//	LISTEN              :8080
//
// GET /healthz on :8081 answers "ok".
//
// API:
//
//	POST /v1/topics/{name}/events   Authorization: Bearer <token> (or X-Runr-Topic-Token: <token>:
//	                                the RunR API calls through the Kubernetes API server's service
//	                                proxy, which keeps the Authorization header for itself)
//	  body = event data; Content-Type = its datacontenttype (default application/json);
//	  optional ce-type (default dev.runr.topic.message), ce-subject, ce-id (default generated)
//	  → 202 {"id": "...", "stream_id": "..."}
//	DELETE /v1/topics/{name}        an admin token ("adm": true) → 204 (the stream is deleted)
//
// Errors: 400 bad topic name, 401 missing, invalid or expired token, 403 topic not allowed (or
// not an admin token for DELETE), 413 body too large, 502 Redis error.
//
// A topic name matches ^[A-Za-z0-9._-]{1,100}$; its stream is runr.topic.<name><sfx>, where the
// token's sfx keeps the topics of different projects (or environments) apart. Each entry holds
// the fields id, type, subject (when set), source ("/projects/<prj>", "/runr" for the API),
// time (RFC 3339, UTC), datacontenttype and data.
package publisher

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"

	"github.com/brf-tech/runr/runtime/queue-consumer/internal/conf"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/httpx"
	"github.com/brf-tech/runr/runtime/queue-consumer/internal/redisx"
)

// Claims are the claims of a publish token.
type Claims struct {
	Suffix  string   `json:"sfx"`           // appended to the stream name of every topic
	Project string   `json:"prj"`           // publishing project id, or "api" for the RunR API
	Topics  []string `json:"top"`           // topics the token may use; "*" = all
	Expires int64    `json:"exp,omitempty"` // unix seconds; 0 = no expiry
	Admin   bool     `json:"adm,omitempty"` // may delete topics
}

// Allows reports whether the token may use topic.
func (c Claims) Allows(topic string) bool {
	return slices.Contains(c.Topics, "*") || slices.Contains(c.Topics, topic)
}

// Token errors.
var (
	ErrBadToken = errors.New("invalid token")
	ErrExpired  = errors.New("token expired")
)

// SignToken is the publish token of c:
// v1.<base64url(claims JSON)>.<base64url(HMAC-SHA256(key, "v1." + <base64url claims>))>
// (URL-safe base64 without padding).
func SignToken(key []byte, c Claims) (string, error) {
	b, err := json.Marshal(c)
	if err != nil {
		return "", err
	}
	signed := "v1." + base64.RawURLEncoding.EncodeToString(b)
	return signed + "." + base64.RawURLEncoding.EncodeToString(mac(key, signed)), nil
}

// VerifyToken checks the signature (in constant time) and the expiry of token at now.
func VerifyToken(key []byte, token string, now time.Time) (Claims, error) {
	parts := strings.Split(token, ".")
	if len(parts) != 3 || parts[0] != "v1" {
		return Claims{}, ErrBadToken
	}
	sig, err := base64.RawURLEncoding.DecodeString(parts[2])
	if err != nil || !hmac.Equal(sig, mac(key, parts[0]+"."+parts[1])) {
		return Claims{}, ErrBadToken
	}
	raw, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil {
		return Claims{}, ErrBadToken
	}
	var c Claims
	if err := json.Unmarshal(raw, &c); err != nil || c.Project == "" {
		return Claims{}, ErrBadToken
	}
	if c.Expires > 0 && now.Unix() >= c.Expires {
		return Claims{}, ErrExpired
	}
	return c, nil
}

func mac(key []byte, msg string) []byte {
	m := hmac.New(sha256.New, key)
	m.Write([]byte(msg))
	return m.Sum(nil)
}

var topicNameRE = regexp.MustCompile(`^[A-Za-z0-9._-]{1,100}$`)

// TokenHeader carries the token of a request that cannot use Authorization: the RunR API's, sent
// through the Kubernetes API server's service proxy.
const TokenHeader = "X-Runr-Topic-Token"

// Server is the publisher's HTTP API.
type Server struct {
	rdb      *redis.Client
	key      []byte
	maxLen   int64
	maxBytes int64
	now      func() time.Time
}

// NewServer is the API over rdb: key verifies tokens, maxLen trims every topic stream
// (≤ 0: no trimming), maxBytes bounds an event body.
func NewServer(rdb *redis.Client, key []byte, maxLen, maxBytes int64) *Server {
	return &Server{rdb: rdb, key: key, maxLen: maxLen, maxBytes: maxBytes, now: time.Now}
}

// Handler serves POST /v1/topics/{name}/events and DELETE /v1/topics/{name}.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /v1/topics/{name}/events", s.publish)
	mux.HandleFunc("DELETE /v1/topics/{name}", s.deleteTopic)
	return mux
}

// authorize checks the topic name and the bearer token; it answers the request itself and
// returns false when either is not acceptable.
func (s *Server) authorize(w http.ResponseWriter, r *http.Request, name string) (Claims, bool) {
	if !topicNameRE.MatchString(name) {
		writeError(w, http.StatusBadRequest, "topic name must match ^[A-Za-z0-9._-]{1,100}$")
		return Claims{}, false
	}
	token, ok := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer ")
	if !ok || strings.TrimSpace(token) == "" {
		// the RunR API through the API server's service proxy, which authenticates with (and
		// drops) the Authorization header
		token, ok = r.Header.Get(TokenHeader), true
	}
	if !ok || strings.TrimSpace(token) == "" {
		writeError(w, http.StatusUnauthorized, "bearer token required")
		return Claims{}, false
	}
	c, err := VerifyToken(s.key, strings.TrimSpace(token), s.now())
	if err != nil {
		writeError(w, http.StatusUnauthorized, err.Error())
		return Claims{}, false
	}
	if !c.Allows(name) {
		writeError(w, http.StatusForbidden, "topic not allowed for this token")
		return Claims{}, false
	}
	return c, true
}

func (s *Server) publish(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	c, ok := s.authorize(w, r, name)
	if !ok {
		return
	}
	data, err := io.ReadAll(http.MaxBytesReader(w, r.Body, s.maxBytes))
	if err != nil {
		var tooLarge *http.MaxBytesError
		if errors.As(err, &tooLarge) {
			writeError(w, http.StatusRequestEntityTooLarge, fmt.Sprintf("event larger than %d bytes", s.maxBytes))
			return
		}
		writeError(w, http.StatusBadRequest, "reading the body: "+err.Error())
		return
	}

	id := r.Header.Get("ce-id")
	if id == "" {
		id = newID(s.now())
	}
	typ := r.Header.Get("ce-type")
	if typ == "" {
		typ = "dev.runr.topic.message"
	}
	contentType := r.Header.Get("Content-Type")
	if contentType == "" {
		contentType = "application/json"
	}
	src := "/projects/" + c.Project
	if c.Project == "api" {
		src = "/runr"
	}
	values := []any{"id", id, "type", typ}
	if subject := r.Header.Get("ce-subject"); subject != "" {
		values = append(values, "subject", subject)
	}
	values = append(values,
		"source", src,
		"time", s.now().UTC().Format(time.RFC3339Nano),
		"datacontenttype", contentType,
		"data", string(data),
	)

	streamID, err := s.rdb.XAdd(r.Context(), &redis.XAddArgs{
		Stream: "runr.topic." + name + c.Suffix,
		MaxLen: max(s.maxLen, 0),
		Approx: s.maxLen > 0,
		Values: values,
	}).Result()
	if err != nil {
		log.Printf("publish to %s: XADD error: %v", name, err)
		writeError(w, http.StatusBadGateway, "the topic store is not available")
		return
	}
	writeJSON(w, http.StatusAccepted, map[string]string{"id": id, "stream_id": streamID})
}

func (s *Server) deleteTopic(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	c, ok := s.authorize(w, r, name)
	if !ok {
		return
	}
	if !c.Admin {
		writeError(w, http.StatusForbidden, "deleting a topic needs an admin token")
		return
	}
	if err := s.rdb.Del(r.Context(), "runr.topic."+name+c.Suffix).Err(); err != nil {
		log.Printf("delete topic %s: DEL error: %v", name, err)
		writeError(w, http.StatusBadGateway, "the topic store is not available")
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"error": msg})
}

// crockford is the alphabet of the generated ids (ULID: 48-bit millisecond time + 80 random
// bits, 26 characters, sortable by time).
const crockford = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

func newID(now time.Time) string {
	var b [16]byte
	var ms [8]byte
	binary.BigEndian.PutUint64(ms[:], uint64(now.UnixMilli()))
	copy(b[:6], ms[2:])
	rand.Read(b[6:])

	// 128 bits → 26 base32 digits (the first digit holds the top 3 bits)
	hi := binary.BigEndian.Uint64(b[:8])
	lo := binary.BigEndian.Uint64(b[8:])
	var out [26]byte
	for i := 25; i >= 0; i-- {
		out[i] = crockford[lo&31]
		lo = lo>>5 | hi<<59
		hi >>= 5
	}
	return string(out[:])
}

// Run is GATEWAY_MODE=publisher: the API on LISTEN and /healthz on :8081, until ctx ends.
func Run(ctx context.Context, getenv func(string) string) error {
	key := getenv("TOPICS_SIGNING_KEY")
	if key == "" {
		return errors.New("TOPICS_SIGNING_KEY is required")
	}
	maxLen := conf.Int(getenv, "TOPIC_MAXLEN", 10000)
	maxBytes := conf.Int(getenv, "PUBLISH_MAX_BYTES", 262144)
	if maxBytes <= 0 {
		maxBytes = 262144
	}
	listen := conf.String(getenv, "LISTEN", ":8080")
	rdb := redisx.NewClient(conf.String(getenv, "REDIS_ADDRESS", "localhost:6379"), getenv("REDIS_PASSWORD"))
	defer rdb.Close()

	log.Printf("topic publisher starting listen=%s redis=%s topic_maxlen=%d publish_max_bytes=%d",
		listen, rdb.Options().Addr, maxLen, maxBytes)

	health := http.NewServeMux()
	health.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("ok"))
	})
	go func() {
		if err := httpx.Serve(ctx, ":8081", health); err != nil {
			log.Printf("health server error: %v", err)
		}
	}()

	err := httpx.Serve(ctx, listen, NewServer(rdb, []byte(key), maxLen, maxBytes).Handler())
	log.Println("topic publisher shutdown complete")
	return err
}
