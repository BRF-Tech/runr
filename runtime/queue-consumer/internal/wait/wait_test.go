package wait

import (
	"context"
	"testing"
	"time"
)

func TestSleep(t *testing.T) {
	if !Sleep(context.Background(), time.Millisecond) {
		t.Error("Sleep without cancellation reported false")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	began := time.Now()
	if Sleep(ctx, time.Hour) || time.Since(began) > time.Second {
		t.Error("Sleep did not end with the context")
	}
}

func TestWindow(t *testing.T) {
	w := NewWindow(0)
	if w.Free() != 1 {
		t.Fatalf("Free = %d, want the minimum size 1", w.Free())
	}
	w = NewWindow(2)
	w.Take(2)
	if w.Free() != 0 || w.WaitFree(context.Background(), 10*time.Millisecond) {
		t.Fatal("a full window reported a free slot")
	}
	go func() {
		time.Sleep(20 * time.Millisecond)
		w.Done()
	}()
	if !w.WaitFree(context.Background(), 5*time.Second) || w.Free() != 1 {
		t.Fatal("WaitFree did not wake when a slot was given back")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	w.Take(1)
	if w.WaitFree(ctx, time.Hour) {
		t.Fatal("WaitFree on a canceled context reported a free slot")
	}
}
