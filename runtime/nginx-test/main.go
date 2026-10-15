package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

func main() {
	fmt.Println("I'm up!")

	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{
			"status":    "ok",
			"method":    r.Method,
			"path":      r.URL.Path,
			"headers":   r.Header,
			"body":      string(body),
			"timestamp": time.Now().Format(time.RFC3339),
		})
	})

	http.ListenAndServe(":80", nil)
}
