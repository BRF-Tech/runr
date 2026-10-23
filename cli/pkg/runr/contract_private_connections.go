package runr

// Private connections of a project or of one of its environments (PrivateDatabaseBindingController,
// docs/environments.md#private-database-connections). The connection is the model's array plus
// its environment, built by hand: its fields are checked against these sources.
var privateConnectionSources = []string{
	"app/Http/Controllers/Api/PrivateDatabaseBindingController.php",
	"app/Models/PrivateDatabaseBinding.php",
	"app/Services/Skupper/SkupperService.php",
}

var privateConnectionFields = []string{
	"name", "resource_name", "env_prefix", "routing_key", "port", "sslmode", "link_kind", "host",
	"secret_name", "env_names", "environment_id", "environment",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/private-connections", Query: []string{"environment"}, Status: 200,
			Permission: "projects.view", Response: prefix("data[].", privateConnectionFields), Sources: privateConnectionSources},
		Endpoint{Method: "POST", Path: "/projects/{project}/private-connections", Status: 201, Permission: "connections.manage",
			Request: []string{"environment", "name", "env_prefix", "routing_key", "port", "sslmode", "link_token", "database",
				"username", "password"},
			Response: concat(prefix("data.", privateConnectionFields), []string{"env_synced"}), Sources: privateConnectionSources},
		Endpoint{Method: "GET", Path: "/projects/{project}/private-connections/{privateConnection}/status", Status: 200,
			Permission: "projects.view",
			Response:   concat(prefix("data.", privateConnectionFields), []string{"status.state", "status.link", "status.listener"}),
			Sources:    privateConnectionSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/private-connections/{privateConnection}", Status: 200,
			Permission: "connections.manage", Sources: privateConnectionSources},
	)
}
