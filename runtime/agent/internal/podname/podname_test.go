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
