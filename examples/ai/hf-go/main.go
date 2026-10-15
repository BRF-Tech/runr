package main

// RunR Go function using the model bound as EMBED (Project → Models): RunR injects
// RUNR_MODEL_EMBED_URL and RUNR_MODEL_EMBED_MODEL; the request goes to the OpenAI-compatible
// /v1/embeddings route inside the cluster. Standard library only.
//
// POST {"text": "..."} → {"dims": 384, "head": [...]}

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"strings"
	"time"
)

var client = &http.Client{Timeout: 120 * time.Second}

type embeddingResponse struct {
	Data []struct {
		Embedding []float64 `json:"embedding"`
	} `json:"data"`
}

func embed(text string) ([]float64, error) {
	url := strings.TrimRight(os.Getenv("RUNR_MODEL_EMBED_URL"), "/")
	if url == "" {
		return nil, fmt.Errorf("no model bound as EMBED: bind one in the project's Models tab and redeploy")
	}

	payload, _ := json.Marshal(map[string]interface{}{
		"model": os.Getenv("RUNR_MODEL_EMBED_MODEL"),
		"input": []string{text},
	})
	resp, err := client.Post(url+"/v1/embeddings", "application/json", bytes.NewReader(payload))
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("model answered %d", resp.StatusCode)
	}

	var out embeddingResponse
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, err
	}
	if len(out.Data) == 0 {
		return nil, fmt.Errorf("model returned no embedding")
	}
	return out.Data[0].Embedding, nil
}

// Handler is the entry point for this function.
func Handler(event interface{}, context interface{}) (interface{}, error) {
	text := "RunR runs models next to your functions."
	if m, ok := event.(map[string]interface{}); ok {
		if t, ok := m["text"].(string); ok && t != "" {
			text = t
		}
	}

	vector, err := embed(text)
	if err != nil {
		return map[string]interface{}{"statusCode": 502, "body": map[string]string{"error": err.Error()}}, nil
	}

	head := vector
	if len(head) > 4 {
		head = head[:4]
	}
	return map[string]interface{}{
		"statusCode": 200,
		"body":       map[string]interface{}{"dims": len(vector), "head": head},
	}, nil
}
