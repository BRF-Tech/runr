package main

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"strconv"
	"strings"
	"sync"
	"testing"

	"github.com/redis/go-redis/v9"
)

// fakeRedis speaks enough RESP2 for redisQueue: RPUSH, LPUSH, LPOP, LLEN (anything else, such as
// the HELLO / CLIENT SETINFO go-redis sends on connect, is an error reply).
type fakeRedis struct {
	ln    net.Listener
	mu    sync.Mutex
	lists map[string][]string
}

func startFakeRedis(t *testing.T) *fakeRedis {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	f := &fakeRedis{ln: ln, lists: map[string][]string{}}
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			go f.serve(conn)
		}
	}()
	t.Cleanup(func() { ln.Close() })
	return f
}

func (f *fakeRedis) serve(conn net.Conn) {
	defer conn.Close()
	r := bufio.NewReader(conn)
	for {
		args, err := readCommand(r)
		if err != nil {
			return
		}
		if _, err := io.WriteString(conn, f.reply(args)); err != nil {
			return
		}
	}
}

func (f *fakeRedis) reply(args []string) string {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(args) < 2 {
		return "-ERR unknown command\r\n"
	}
	key, list := args[1], f.lists[args[1]]
	switch strings.ToUpper(args[0]) {
	case "RPUSH":
		f.lists[key] = append(list, args[2:]...)
	case "LPUSH":
		for _, v := range args[2:] {
			list = append([]string{v}, list...)
		}
		f.lists[key] = list
	case "LPOP":
		if len(list) == 0 {
			return "$-1\r\n"
		}
		f.lists[key] = list[1:]
		return fmt.Sprintf("$%d\r\n%s\r\n", len(list[0]), list[0])
	case "LLEN":
	default:
		return "-ERR unknown command\r\n"
	}
	return fmt.Sprintf(":%d\r\n", len(f.lists[key]))
}

// readCommand reads one RESP array of bulk strings.
func readCommand(r *bufio.Reader) ([]string, error) {
	line, err := r.ReadString('\n')
	if err != nil {
		return nil, err
	}
	if !strings.HasPrefix(line, "*") {
		return nil, errors.New("not an array")
	}
	n, err := strconv.Atoi(strings.TrimSpace(line[1:]))
	if err != nil {
		return nil, err
	}
	args := make([]string, 0, n)
	for i := 0; i < n; i++ {
		head, err := r.ReadString('\n')
		if err != nil {
			return nil, err
		}
		size, err := strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(head, "$")))
		if err != nil {
			return nil, err
		}
		buf := make([]byte, size+2)
		if _, err := io.ReadFull(r, buf); err != nil {
			return nil, err
		}
		args = append(args, string(buf[:size]))
	}
	return args, nil
}

func TestRedisQueue(t *testing.T) {
	f := startFakeRedis(t)
	rdb := redis.NewClient(&redis.Options{Addr: f.ln.Addr().String(), Protocol: 2, DisableIndentity: true})
	defer rdb.Close()
	q := redisQueue{rdb: rdb, key: queueKey}
	ctx := context.Background()

	if _, err := q.Pop(ctx); !errors.Is(err, errEmpty) {
		t.Fatalf("Pop on an empty list: %v, want errEmpty", err)
	}
	for _, step := range []func() error{
		func() error { return q.Push(ctx, []byte("a")) },
		func() error { return q.Push(ctx, []byte("b")) },
		func() error { return q.PushFront(ctx, []byte("retried")) },
	} {
		if err := step(); err != nil {
			t.Fatal(err)
		}
	}
	if n, err := q.Len(ctx); err != nil || n != 3 {
		t.Fatalf("Len = %d, %v; want 3", n, err)
	}
	var got []string
	for {
		raw, err := q.Pop(ctx)
		if errors.Is(err, errEmpty) {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		got = append(got, string(raw))
	}
	if strings.Join(got, ",") != "retried,a,b" {
		t.Fatalf("popped %v, want the requeued envelope first, then in push order", got)
	}
	if keys := len(f.lists); keys != 1 {
		t.Fatalf("%d Redis keys used, want only %s", keys, queueKey)
	}
}

func TestRedisQueueUnreachable(t *testing.T) {
	f := startFakeRedis(t)
	addr := f.ln.Addr().String()
	f.ln.Close()

	rdb := redis.NewClient(&redis.Options{Addr: addr, MaxRetries: -1})
	defer rdb.Close()
	q := redisQueue{rdb: rdb, key: queueKey}

	// A Redis error is not "empty": the worker logs it instead of treating the queue as drained
	if _, err := q.Pop(context.Background()); err == nil || errors.Is(err, errEmpty) {
		t.Fatalf("Pop with Redis down: %v, want a connection error", err)
	}
}
