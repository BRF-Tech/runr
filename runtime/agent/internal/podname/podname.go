// Package podname derives the RunR project slug from a Knative pod name.
package podname

import "strings"

// Slug returns the project slug of a Knative Service pod, or "" when the name does not
// follow the Knative pattern.
//
// The Knative Service is named after the project slug, so its pods are named
// {slug}-{revision 00001}-deployment-{replicaset hash}-{pod hash}. The revision number is the
// zero-padded part right before "deployment"; anchoring on that marker keeps a pod hash that
// happens to start with a digit (e.g. "2xk9p") from being taken for the revision.
func Slug(pod string) string {
	parts := strings.Split(pod, "-")
	for i := len(parts) - 1; i >= 2; i-- {
		if parts[i] == "deployment" && isRevision(parts[i-1]) {
			return strings.Join(parts[:i-1], "-")
		}
	}
	return ""
}

// isRevision reports whether s is a Knative revision suffix: five or more digits ("00001").
func isRevision(s string) bool {
	if len(s) < 5 {
		return false
	}
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}
	return true
}
