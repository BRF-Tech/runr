// Compiled once while the build image is built, so the module cache, go.sum and TinyGo's cache hold
// the Spin Go SDK before any function build.
package main

import (
	"fmt"
	"net/http"

	spinhttp "github.com/spinframework/spin-go-sdk/v2/http"
)

func init() {
	spinhttp.Handle(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprint(w, "prewarm")
	})
}

func main() {}
