// Command runr is the RunR command line.
package main

import (
	"os"

	"github.com/brf-tech/runr/cli/internal/cmd"
)

func main() {
	os.Exit(cmd.Execute(cmd.NewApp(), os.Args[1:]))
}
