package runr

// A project's network (docs/internal-network.md): access mode and the allow list of internal
// callers. The controller builds the answers by hand; the fields are checked against it.
var networkSources = []string{"app/Http/Controllers/Api/ProjectNetworkController.php"}

var networkFields = []string{
	"access_mode", "allowed_cidrs", "internal_name", "internal_host", "internal_url", "organization",
	"token_header", "token_env", "cross_tenant_grants", "enforced",
	"callers[].caller", "callers[].organization", "callers[].cross_tenant", "callers[].created_at",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/network", Status: 200, Permission: "projects.view",
			Response: prefix("data.", networkFields), Sources: networkSources, Optional: true},
		Endpoint{Method: "PUT", Path: "/projects/{project}/network", Status: 200, Permission: "network.manage",
			Request: []string{"access_mode", "allowed_cidrs"}, Response: prefix("data.", networkFields),
			Sources: networkSources, Optional: true},
		Endpoint{Method: "POST", Path: "/projects/{project}/network/callers", Status: 201, Permission: "network.manage",
			Request:  []string{"caller"},
			Response: []string{"data.caller", "data.organization", "data.cross_tenant", "data.created_at"},
			Sources:  networkSources, Optional: true},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/network/callers/{caller}", Status: 204, Permission: "network.manage",
			Optional: true},
	)
}
