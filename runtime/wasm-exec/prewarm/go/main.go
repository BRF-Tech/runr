// Built once with TinyGo while the build image is built (warm TinyGo cache): a WAGI function in Go,
// without a Spin SDK — the request in the environment, the response on stdout.
package main

import (
	"fmt"
	"os"
	"strings"
)

func main() {
	name := "World"
	for _, kv := range strings.Split(os.Getenv("QUERY_STRING"), "&") {
		if v, ok := strings.CutPrefix(kv, "name="); ok && v != "" {
			name = v
		}
	}
	fmt.Print("Content-Type: text/plain\n\n")
	fmt.Printf("Hello, %s! (from Go, Wasm exec)", name)
}
