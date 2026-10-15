package main

import (
	"fmt"
	"io"
	"net/http"

	spinhttp "github.com/spinframework/spin-go-sdk/v2/http"
)

// Every trigger reaches the component as an HTTP request: queue messages and schedule ticks
// arrive as CloudEvents (the ce-type header names the event).
func init() {
	spinhttp.Handle(func(w http.ResponseWriter, r *http.Request) {
		var body string
		switch r.Header.Get("ce-type") {
		case "dev.runr.schedule.tick":
			body = "tick from Go (Wasm)"
		case "dev.runr.queue.message":
			data, _ := io.ReadAll(r.Body)
			body = fmt.Sprintf("processed %s (from Go, Wasm)", data)
		default:
			name := r.URL.Query().Get("name")
			if name == "" {
				name = "World"
			}
			body = fmt.Sprintf("Hello, %s! (from Go, Wasm)", name)
		}
		fmt.Println(body)
		w.Header().Set("Content-Type", "text/plain")
		fmt.Fprint(w, body)
	})
}

// main is required by the compiler; the component's entry point is the handler above.
func main() {}
