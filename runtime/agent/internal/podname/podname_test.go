package podname

import "testing"

func TestSlug(t *testing.T) {
	cases := []struct {
		pod  string
		want string
	}{
		{"hello-00001-deployment-6b8f9c7d4-xk9pq", "hello"},
		{"my-api-00012-deployment-7d4f8b9c6-bcdfg", "my-api"},
		// pod hash starting with a digit must not be taken for the revision
		{"hello-00001-deployment-6b8f9c7d4-2xk9p", "hello"},
		{"shop-front-end-00003-deployment-59c7d8b6f4-24567", "shop-front-end"},
		// revision numbers beyond 99999 keep their digits
		{"svc-100000-deployment-abc-def12", "svc"},
		// slug that contains a number part itself
		{"v2-api-00007-deployment-7c9d-zz2kq", "v2-api"},
		// not a Knative serving pod
		{"build-abc123-xyz", ""},
		{"runr-agent-6f7d9-qwert", ""},
		{"hello-0001-deployment-abc-defgh", ""}, // revision too short
		{"hello-deployment-abc-defgh", ""},
		{"00001-deployment-abc-defgh", ""}, // empty slug
		{"", ""},
	}
	for _, tc := range cases {
		if got := Slug(tc.pod); got != tc.want {
			t.Errorf("Slug(%q) = %q, want %q", tc.pod, got, tc.want)
		}
	}
}

func TestPreviewSlug(t *testing.T) {
	cases := []struct {
		service, number string
		want            string
	}{
		{"shop-pr-12", "12", "shop"},
		{"shop-front-pr-3", "", "shop-front"},
		// a slug that has -pr- in it keeps it
		{"my-pr-tool-pr-7", "7", "my-pr-tool"},
		{"my-pr-tool-pr-7", "", "my-pr-tool"},
		// not a preview Service
		{"shop", "", ""},
		{"shop-pr-12", "13", ""},
		{"shop-pr-x", "", ""},
		{"-pr-4", "4", ""},
		{"", "", ""},
	}
	for _, tc := range cases {
		if got := PreviewSlug(tc.service, tc.number); got != tc.want {
			t.Errorf("PreviewSlug(%q, %q) = %q, want %q", tc.service, tc.number, got, tc.want)
		}
	}
}

func TestScheduleSlug(t *testing.T) {
	cases := []struct {
		job  string
		want string
	}{
		{"nightly-trigger-29345678", "nightly"},
		{"shop-front-end-trigger-29345678", "shop-front-end"},
		// a slug that ends in -trigger itself keeps it
		{"on-trigger-trigger-29345678", "on-trigger"},
		{"v2-trigger-1", "v2"},
		// not a run of a project's CronJob
		{"build-nightly-v3", ""},
		{"nightly-trigger", ""},
		{"nightly-trigger-manual", ""},
		{"nightly-29345678", ""},
		{"-trigger-29345678", ""},
		{"trigger-29345678", ""},
		{"", ""},
	}
	for _, tc := range cases {
		if got := ScheduleSlug(tc.job); got != tc.want {
			t.Errorf("ScheduleSlug(%q) = %q, want %q", tc.job, got, tc.want)
		}
	}
}
