package runr

import "fmt"

// FailedError means something the client waited for ended in a failed state: a workflow run,
// a runtime build, a model service, a binding being provisioned. The CLI exits with code 6.
type FailedError struct {
	// What failed ("workflow run", "model service", …) and its id or name.
	What string
	Ref  string
	// Status is the final status the API reported; Message its reason, if any.
	Status  string
	Message string
}

func (e *FailedError) Error() string {
	msg := e.Message
	if msg == "" {
		msg = e.Status
	}
	if msg == "" {
		msg = "no reason given"
	}
	return fmt.Sprintf("%s %s failed: %s", e.What, e.Ref, msg)
}
