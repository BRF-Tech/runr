// Package podname derives the RunR project slug from the name of a project's pod or Job.
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
	return len(s) >= 5 && isDigits(s)
}

// cronJobSuffix ends the name of a project's CronJob: the API names it {slug}-trigger.
const cronJobSuffix = "-trigger"

// ScheduleSlug returns the project slug of a Job that the project's CronJob started, or "" for
// any other Job name.
//
// The CronJob is named {slug}-trigger, and the CronJob controller names each Job it starts
// {cronjob}-{scheduled time in minutes since the epoch}. Build Jobs (build-{slug}-v{n}) and Jobs
// created by hand do not follow that pattern.
func ScheduleSlug(job string) string {
	i := strings.LastIndex(job, "-")
	if i < 0 || !isDigits(job[i+1:]) {
		return ""
	}
	cronJob := job[:i]
	if !strings.HasSuffix(cronJob, cronJobSuffix) {
		return ""
	}
	return strings.TrimSuffix(cronJob, cronJobSuffix)
}

// PreviewSlug returns the project slug of a preview environment's Knative Service, or "" when
// the name does not follow the pattern.
//
// The API names the Service <slug>-pr-<number> (PreviewManifests::serviceName); number is the
// preview's number from the pod's label, or "" to take the digits after the last "-pr-". A slug
// too long for the 63-character name was shortened by the API: what is left of it comes back.
func PreviewSlug(service, number string) string {
	if number == "" {
		i := strings.LastIndex(service, "-pr-")
		if i < 0 || !isDigits(service[i+len("-pr-"):]) {
			return ""
		}
		number = service[i+len("-pr-"):]
	}
	if !isDigits(number) || !strings.HasSuffix(service, "-pr-"+number) {
		return ""
	}
	return strings.TrimSuffix(service, "-pr-"+number)
}

// isDigits reports whether s is a non-empty run of ASCII digits.
func isDigits(s string) bool {
	if s == "" {
		return false
	}
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}
	return true
}
