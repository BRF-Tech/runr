package publisher

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

var key = []byte("topics-signing-key")

var now = time.Date(2026, 10, 21, 12, 0, 0, 0, time.UTC)

func token(t *testing.T, c Claims) string {
	t.Helper()
	tok, err := SignToken(key, c)
	if err != nil {
		t.Fatal(err)
	}
	return tok
}

func TestTokenVerify(t *testing.T) {
	ok := Claims{Suffix: "-acme", Project: "prj-1", Topics: []string{"orders"}, Expires: now.Add(time.Hour).Unix()}
	c, err := VerifyToken(key, token(t, ok), now)
	if err != nil || c.Project != "prj-1" || c.Suffix != "-acme" || !c.Allows("orders") || c.Allows("billing") {
		t.Fatalf("VerifyToken = %+v, %v", c, err)
	}

	// The API's PHP signs hash_hmac('sha256', 'v1.'.$b64claims, $keyString, true) over the raw
	// claims JSON it builds; the same bytes must verify here.
	claims := base64.RawURLEncoding.EncodeToString([]byte(`{"sfx":"-acme","prj":"api","top":["*"],"exp":0,"adm":true}`))
	m := hmac.New(sha256.New, key)
	m.Write([]byte("v1." + claims))
	php := "v1." + claims + "." + base64.RawURLEncoding.EncodeToString(m.Sum(nil))
	if c, err := VerifyToken(key, php, now); err != nil || !c.Admin || !c.Allows("anything") || c.Expires != 0 {
		t.Fatalf("PHP-made token: %+v, %v", c, err)
	}

	good := token(t, ok)
	parts := strings.Split(good, ".")
	other, _ := SignToken([]byte("another key"), ok)
	tampered := parts[0] + "." + base64.RawURLEncoding.EncodeToString([]byte(`{"prj":"prj-1","top":["*"]}`)) + "." + parts[2]
	for name, bad := range map[string]string{
		"other key": other,
		"tampered":  tampered,
		"no sig":    parts[0] + "." + parts[1],
		"version":   "v2." + parts[1] + "." + parts[2],
		"garbage":   "not-a-token",
		"empty":     "",
	} {
		if _, err := VerifyToken(key, bad, now); !errors.Is(err, ErrBadToken) {
			t.Errorf("%s: err = %v, want ErrBadToken", name, err)
		}
	}

	expired := ok
	expired.Expires = now.Unix()
	if _, err := VerifyToken(key, token(t, expired), now); !errors.Is(err, ErrExpired) {
		t.Errorf("expired token: err = %v", err)
	}
	if _, err := VerifyToken(key, token(t, Claims{Topics: []string{"*"}}), now); !errors.Is(err, ErrBadToken) {
		t.Errorf("token without prj accepted: %v", err)
	}
}

func testServer(t *testing.T, maxLen, maxBytes int64) (*Server, *redis.Client, bool) {
	t.Helper()
	addr := os.Getenv("QC_TEST_REDIS")
	mini := addr == ""
	if mini {
		addr = miniredis.RunT(t).Addr()
	}
	rdb := redis.NewClient(&redis.Options{Addr: addr})
	t.Cleanup(func() {
		rdb.Del(context.Background(), "runr.topic.orders-acme", "runr.topic.events-api")
		rdb.Close()
	})
	s := NewServer(rdb, key, maxLen, maxBytes)
	s.now = func() time.Time { return now }
	return s, rdb, mini
}

func do(t *testing.T, s *Server, method, path, tok, body string, headers map[string]string) (int, map[string]string) {
	t.Helper()
	req := httptest.NewRequest(method, path, strings.NewReader(body))
	if tok != "" {
		req.Header.Set("Authorization", "Bearer "+tok)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	w := httptest.NewRecorder()
	s.Handler().ServeHTTP(w, req)
	out := map[string]string{}
	b, _ := io.ReadAll(w.Result().Body)
	json.Unmarshal(b, &out)
	return w.Code, out
}

func TestPublishAppendsToTheTopicStream(t *testing.T) {
	s, rdb, _ := testServer(t, 1000, 1024)
	tok := token(t, Claims{Suffix: "-acme", Project: "prj-1", Topics: []string{"orders"}})

	code, out := do(t, s, http.MethodPost, "/v1/topics/orders/events", tok, `{"order":17}`, map[string]string{
		"Content-Type": "application/json; charset=utf-8",
		"ce-type":      "com.acme.order.created",
		"ce-subject":   "order-17",
		"ce-id":        "evt-1",
	})
	if code != http.StatusAccepted || out["id"] != "evt-1" || out["stream_id"] == "" {
		t.Fatalf("publish = %d %v", code, out)
	}
	entries := rdb.XRange(context.Background(), "runr.topic.orders-acme", "-", "+").Val()
	if len(entries) != 1 || entries[0].ID != out["stream_id"] {
		t.Fatalf("stream entries %v", entries)
	}
	want := map[string]string{
		"id": "evt-1", "type": "com.acme.order.created", "subject": "order-17", "source": "/projects/prj-1",
		"time": "2026-10-21T12:00:00Z", "datacontenttype": "application/json; charset=utf-8", "data": `{"order":17}`,
	}
	for k, w := range want {
		if entries[0].Values[k] != w {
			t.Errorf("field %s = %v, want %q", k, entries[0].Values[k], w)
		}
	}
	if len(entries[0].Values) != len(want) {
		t.Errorf("fields %v", entries[0].Values)
	}
}

func TestPublishDefaults(t *testing.T) {
	s, rdb, _ := testServer(t, 1000, 1024)
	tok := token(t, Claims{Project: "api", Suffix: "-api", Topics: []string{"*"}})

	code, out := do(t, s, http.MethodPost, "/v1/topics/events/events", tok, "hello", nil)
	if code != http.StatusAccepted {
		t.Fatalf("publish = %d %v", code, out)
	}
	if id := out["id"]; len(id) != 26 || strings.Trim(id, crockford) != "" {
		t.Errorf("generated id %q, want 26 Crockford base32 characters", id)
	}
	v := rdb.XRange(context.Background(), "runr.topic.events-api", "-", "+").Val()[0].Values
	if v["type"] != "dev.runr.topic.message" || v["source"] != "/runr" || v["datacontenttype"] != "application/json" || v["id"] != out["id"] {
		t.Errorf("fields %v", v)
	}
	if _, ok := v["subject"]; ok {
		t.Error("subject written although none was given")
	}

	a, b := newID(now), newID(now.Add(time.Millisecond))
	if a >= b || a[:10] == "0000000000" {
		t.Errorf("ids %s, %s are not time-sortable", a, b)
	}
}

func TestPublishTrimsToTopicMaxLen(t *testing.T) {
	s, rdb, mini := testServer(t, 2, 1024)
	if !mini {
		t.Skip("a real Redis trims MAXLEN ~ by whole nodes, not to the entry")
	}
	tok := token(t, Claims{Suffix: "-acme", Project: "prj-1", Topics: []string{"*"}})
	for range 5 {
		if code, out := do(t, s, http.MethodPost, "/v1/topics/orders/events", tok, "{}", nil); code != http.StatusAccepted {
			t.Fatalf("publish = %d %v", code, out)
		}
	}
	if n := rdb.XLen(context.Background(), "runr.topic.orders-acme").Val(); n != 2 {
		t.Fatalf("stream holds %d entries, want TOPIC_MAXLEN 2", n)
	}
}

func TestPublishErrors(t *testing.T) {
	s, _, _ := testServer(t, 1000, 16)
	orders := token(t, Claims{Suffix: "-acme", Project: "prj-1", Topics: []string{"orders"}})
	expired := token(t, Claims{Suffix: "-acme", Project: "prj-1", Topics: []string{"*"}, Expires: now.Add(-time.Minute).Unix()})
	forged, _ := SignToken([]byte("wrong"), Claims{Project: "prj-1", Topics: []string{"*"}})

	cases := []struct {
		name, path, tok, body string
		want                  int
	}{
		{"no token", "/v1/topics/orders/events", "", "{}", http.StatusUnauthorized},
		{"forged token", "/v1/topics/orders/events", forged, "{}", http.StatusUnauthorized},
		{"expired token", "/v1/topics/orders/events", expired, "{}", http.StatusUnauthorized},
		{"topic not allowed", "/v1/topics/billing/events", orders, "{}", http.StatusForbidden},
		{"bad name", "/v1/topics/or%20ders/events", orders, "{}", http.StatusBadRequest},
		{"long name", "/v1/topics/" + strings.Repeat("a", 101) + "/events", orders, "{}", http.StatusBadRequest},
		{"too large", "/v1/topics/orders/events", orders, strings.Repeat("x", 17), http.StatusRequestEntityTooLarge},
		{"exactly the limit", "/v1/topics/orders/events", orders, strings.Repeat("x", 16), http.StatusAccepted},
	}
	for _, tc := range cases {
		if code, out := do(t, s, http.MethodPost, tc.path, tc.tok, tc.body, nil); code != tc.want {
			t.Errorf("%s: %d %v, want %d", tc.name, code, out, tc.want)
		}
	}
}

func TestPublishRedisErrorIs502(t *testing.T) {
	rdb := redis.NewClient(&redis.Options{Addr: "127.0.0.1:1", MaxRetries: -1, DialTimeout: 200 * time.Millisecond})
	defer rdb.Close()
	s := NewServer(rdb, key, 1000, 1024)
	tok := token(t, Claims{Project: "prj-1", Topics: []string{"*"}})
	if code, out := do(t, s, http.MethodPost, "/v1/topics/orders/events", tok, "{}", nil); code != http.StatusBadGateway {
		t.Fatalf("publish with Redis down = %d %v, want 502", code, out)
	}
}

func TestDeleteTopicNeedsAnAdminToken(t *testing.T) {
	s, rdb, _ := testServer(t, 1000, 1024)
	user := token(t, Claims{Suffix: "-acme", Project: "prj-1", Topics: []string{"*"}})
	admin := token(t, Claims{Suffix: "-acme", Project: "api", Topics: []string{"*"}, Admin: true})
	ctx := context.Background()
	if code, _ := do(t, s, http.MethodPost, "/v1/topics/orders/events", user, "{}", nil); code != http.StatusAccepted {
		t.Fatal("publish failed")
	}

	if code, _ := do(t, s, http.MethodDelete, "/v1/topics/orders", user, "", nil); code != http.StatusForbidden {
		t.Errorf("delete with a non-admin token = %d, want 403", code)
	}
	if rdb.Exists(ctx, "runr.topic.orders-acme").Val() != 1 {
		t.Fatal("stream deleted by a non-admin token")
	}
	if code, _ := do(t, s, http.MethodDelete, "/v1/topics/orders", "", "", nil); code != http.StatusUnauthorized {
		t.Errorf("delete without a token = %d, want 401", code)
	}
	if code, _ := do(t, s, http.MethodDelete, "/v1/topics/orders", admin, "", nil); code != http.StatusNoContent {
		t.Errorf("delete with an admin token = %d, want 204", code)
	}
	if rdb.Exists(ctx, "runr.topic.orders-acme").Val() != 0 {
		t.Error("stream still exists")
	}
}

func TestRunNeedsTheSigningKey(t *testing.T) {
	err := Run(context.Background(), func(string) string { return "" })
	if err == nil || !strings.Contains(err.Error(), "TOPICS_SIGNING_KEY") {
		t.Fatalf("Run without TOPICS_SIGNING_KEY = %v", err)
	}
}

// The RunR API publishes through the Kubernetes API server's service proxy, which authenticates
// with the Authorization header and drops it: its token comes in X-Runr-Topic-Token.
func TestPublishTakesTheTokenHeaderOfTheAPI(t *testing.T) {
	s, _, _ := testServer(t, 1000, 1024)
	tok := token(t, Claims{Suffix: "-acme", Project: "runr", Topics: []string{"orders"}})

	code, out := do(t, s, http.MethodPost, "/v1/topics/orders/events", "", `{}`, map[string]string{TokenHeader: tok})
	if code != http.StatusAccepted {
		t.Fatalf("publish with %s = %d %v", TokenHeader, code, out)
	}
	// an Authorization header the proxy left (another bearer, a basic one) does not hide it
	code, _ = do(t, s, http.MethodPost, "/v1/topics/orders/events", "", `{}`, map[string]string{"Authorization": "Basic abc", TokenHeader: tok})
	if code != http.StatusAccepted {
		t.Fatalf("publish with a basic Authorization and %s = %d", TokenHeader, code)
	}
	if code, _ = do(t, s, http.MethodPost, "/v1/topics/orders/events", "", `{}`, nil); code != http.StatusUnauthorized {
		t.Fatalf("no token = %d", code)
	}
}
