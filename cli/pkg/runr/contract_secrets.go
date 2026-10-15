package runr

// Secret variables of a project (docs/security.md, "Secret environment variables"). The
// generator leaves these answers untyped; the fields are checked against the controller and
// ProjectSecret::toPublicArray.
var secretSources = []string{
	"app/Http/Controllers/Api/ProjectSecretController.php",
	"app/Models/ProjectSecret.php",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/secrets", Status: 200, Permission: "env.view",
			Response: []string{"data[].name", "data[].updated_at", "data[].updated_by.id", "data[].updated_by.name", "can_view_values"},
			Sources:  secretSources},
		// 201 for a new secret, 200 for a replaced one (the document names 200 only).
		Endpoint{Method: "PUT", Path: "/projects/{project}/secrets/{name}", Status: 200, Permission: "env.edit",
			Request:  []string{"value"},
			Response: []string{"data.name", "data.updated_at", "requires_deploy"},
			Sources:  secretSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/secrets/{name}", Status: 200, Permission: "env.edit",
			Response: []string{"requires_deploy"}},
		Endpoint{Method: "GET", Path: "/projects/{project}/secrets/{name}/value", Status: 200, Permission: "secrets.view_values",
			Response: []string{"name", "value"}, Sources: secretSources},
	)
}
