package ws

import (
	"context"
	"encoding/json"
	"log"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// Client manages a persistent WebSocket connection to the RunR platform.
type Client struct {
	url      string
	regionID string
	token    string
	conn     *websocket.Conn
	mu       sync.Mutex
	sendCh   chan []byte

	initialBackoff time.Duration
	maxBackoff     time.Duration
	// stableAfter is how long a connection must stay up before the reconnect backoff resets;
	// a server that accepts and drops at once is retried with a growing delay, not in a loop.
	stableAfter time.Duration
}

func NewClient(url, regionID, token string) *Client {
	return &Client{
		url:            url,
		regionID:       regionID,
		token:          token,
		sendCh:         make(chan []byte, 256),
		initialBackoff: time.Second,
		maxBackoff:     30 * time.Second,
		stableAfter:    10 * time.Second,
	}
}

// Run connects and reconnects with exponential backoff.
func (c *Client) Run(ctx context.Context) {
	backoff := c.initialBackoff

	for {
		select {
		case <-ctx.Done():
			c.close()
			return
		default:
		}

		conn, err := c.connect(ctx)
		if err != nil {
			log.Printf("WS connect failed: %v (retry in %v)", err, backoff)
			if !sleep(ctx, backoff) {
				return
			}
			backoff = min(backoff*2, c.maxBackoff)
			continue
		}

		log.Printf("WS connected to %s", c.url)
		connectedAt := time.Now()

		c.runLoop(ctx, conn)

		if ctx.Err() != nil {
			return
		}
		if time.Since(connectedAt) >= c.stableAfter {
			backoff = c.initialBackoff
			continue
		}
		log.Printf("WS connection dropped after %v (retry in %v)", time.Since(connectedAt).Round(time.Millisecond), backoff)
		if !sleep(ctx, backoff) {
			return
		}
		backoff = min(backoff*2, c.maxBackoff)
	}
}

// Send marshals and queues a message for delivery.
func (c *Client) Send(msg map[string]interface{}) {
	msg["region_id"] = c.regionID

	data, err := json.Marshal(msg)
	if err != nil {
		log.Printf("WS marshal error: %v", err)
		return
	}

	select {
	case c.sendCh <- data:
	default:
		log.Println("WS send buffer full, dropping message")
	}
}

func (c *Client) connect(ctx context.Context) (*websocket.Conn, error) {
	header := make(map[string][]string)
	header["Authorization"] = []string{"Bearer " + c.token}
	header["X-Region-ID"] = []string{c.regionID}

	conn, _, err := websocket.DefaultDialer.DialContext(ctx, c.url, header)
	if err != nil {
		return nil, err
	}

	c.mu.Lock()
	c.conn = conn
	c.mu.Unlock()

	return conn, nil
}

// runLoop pumps queued messages to conn until the connection fails or ctx is cancelled.
// It always closes conn and waits for its reader to stop before returning, so a dropped
// connection leaks neither the socket nor the read goroutine.
func (c *Client) runLoop(ctx context.Context, conn *websocket.Conn) {
	done := make(chan struct{})

	// Read loop (handles pings and incoming config updates)
	go func() {
		defer close(done)
		for {
			_, msg, err := conn.ReadMessage()
			if err != nil {
				if ctx.Err() == nil {
					log.Printf("WS read error: %v", err)
				}
				return
			}
			c.handleMessage(msg)
		}
	}()

	defer func() {
		c.close()
		<-done
	}()

	// Write loop
	for {
		select {
		case <-ctx.Done():
			return
		case <-done:
			return
		case data := <-c.sendCh:
			c.mu.Lock()
			err := conn.WriteMessage(websocket.TextMessage, data)
			c.mu.Unlock()
			if err != nil {
				log.Printf("WS write error: %v", err)
				return
			}
		}
	}
}

func (c *Client) handleMessage(msg []byte) {
	var data map[string]interface{}
	if err := json.Unmarshal(msg, &data); err != nil {
		return
	}

	msgType, _ := data["type"].(string)
	switch msgType {
	case "config_update":
		log.Println("Received config update from platform")
	case "force_snapshot":
		log.Println("Force snapshot requested")
	}
}

func (c *Client) close() {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.conn != nil {
		c.conn.Close()
		c.conn = nil
	}
}

// sleep waits for d or until ctx is cancelled; it reports whether the full wait elapsed.
func sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}
