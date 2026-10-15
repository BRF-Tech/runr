package main

import (
	"encoding/json"
	"fmt"
	"os"
)

func main() {
	event := map[string]interface{}{"trigger": "schedule"}
	context := map[string]interface{}{"mode": "schedule"}

	result, err := Handler(event, context)
	if err != nil {
		fmt.Fprintf(os.Stderr, "Error: %v\n", err)
		os.Exit(1)
	}

	output, _ := json.Marshal(result)
	fmt.Println(string(output))
}
