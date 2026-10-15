package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
)

func main() {
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("K-Network-Probe") != "" || r.Header.Get("K-Kubelet-Probe") != "" {
			w.WriteHeader(200)
			w.Write([]byte("ok"))
			return
		}

		body, _ := io.ReadAll(r.Body)
		defer r.Body.Close()

		var event interface{}
		if err := json.Unmarshal(body, &event); err != nil {
			event = map[string]interface{}{"body": string(body)}
		}

		context := map[string]interface{}{
			"method":  r.Method,
			"path":    r.URL.Path,
			"headers": r.Header,
		}

		// CORS preflight
		if r.Method == "OPTIONS" {
			w.Header().Set("Access-Control-Allow-Origin", "*")
			w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, PATCH, OPTIONS")
			w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization")
			w.Header().Set("Access-Control-Max-Age", "86400")
			w.WriteHeader(204)
			return
		}

		result, err := Handler(event, context)
		if err != nil {
			w.Header().Set("Access-Control-Allow-Origin", "*")
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(500)
			json.NewEncoder(w).Encode(map[string]string{"error": err.Error()})
			return
		}

		w.Header().Set("Access-Control-Allow-Origin", "*")

		if m, ok := result.(map[string]interface{}); ok {
			if hRaw, hasHeaders := m["headers"]; hasHeaders {
				if hMap, ok := hRaw.(map[string]interface{}); ok {
					for k, v := range hMap {
						w.Header().Set(k, fmt.Sprintf("%v", v))
					}
				}
				statusCode := 200
				if sc, ok := m["statusCode"]; ok {
					if scF, ok := sc.(float64); ok {
						statusCode = int(scF)
					}
				}
				respBody := m["body"]
				ct := w.Header().Get("Content-Type")
				if bodyStr, ok := respBody.(string); ok && !strings.Contains(ct, "application/json") {
					w.WriteHeader(statusCode)
					w.Write([]byte(bodyStr))
				} else {
					w.Header().Set("Content-Type", "application/json")
					w.WriteHeader(statusCode)
					json.NewEncoder(w).Encode(respBody)
				}
				return
			}
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(result)
	})

	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}
	fmt.Printf("RunR Go runtime listening on :%s\n", port)
	http.ListenAndServe(":"+port, nil)
}
