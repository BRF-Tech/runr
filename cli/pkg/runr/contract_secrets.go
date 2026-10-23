package runr

// Secret variables of a project (docs/security.md, "Secret environment variables") and the
// organization's secret stores (docs/secret-stores.md). The generator leaves these answers
// untyped; the fields are checked against the controllers and the models' array builders.
var secretSources = []string{
	"app/Http/Controllers/Api/ProjectSecretController.php",
	"app/Models/ProjectSecret.php",
}

// The region's own OpenBao (OpenBaoController, docs/secret-stores.md "OpenBao in the region").
var openBaoSources = []string{"app/Http/Controllers/Api/OpenBaoController.php"}

var openBaoFields = []string{"status", "message", "version", "release_version", "installed_at", "default_for_values",
	"values", "eso_status", "can_manage"}

var secretStoreSources = []string{
	"app/Http/Controllers/Api/SecretStoreController.php",
	"app/Models/SecretStore.php",
	"app/Services/Secrets/External/ClusterIssuer.php",
}

// secretStoreFields are SecretStore::toApiArray() and the number of secrets referring to it.
var secretStoreFields = []string{"id", "name", "provider", "auth", "settings", "refresh_interval", "kubernetes_name",
	"audience", "regions", "references"}

// Fields of a secret the client reads when the API sends them (pass-through and references).
var secretCapabilities = []string{
	"data[].source", "data[].version", "data[].stored_in", "data[].fingerprint",
	"data[].reference.store_id", "data[].reference.store_name", "data[].reference.path", "data[].reference.key",
	"data[].sync.status", "data[].sync.message", "data[].sync.synced_at", "storage",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/secrets", Status: 200, Permission: "env.view",
			Response:     []string{"data[].name", "data[].updated_at", "data[].updated_by.id", "data[].updated_by.name", "can_view_values"},
			Capabilities: secretCapabilities,
			Sources:      secretSources},
		// 201 for a new secret, 200 for a replaced one (the document names 200 only). A value or
		// a reference to a secret store.
		Endpoint{Method: "PUT", Path: "/projects/{project}/secrets/{name}", Status: 200, Permission: "env.edit",
			Request:  []string{"value", "reference.store_id", "reference.path", "reference.key"},
			Response: []string{"data.name", "data.updated_at", "requires_deploy"},
			Sources:  secretSources},
		Endpoint{Method: "DELETE", Path: "/projects/{project}/secrets/{name}", Status: 200, Permission: "env.edit",
			Response: []string{"requires_deploy"}},
		Endpoint{Method: "GET", Path: "/projects/{project}/secrets/{name}/value", Status: 200, Permission: "secrets.view_values",
			Response: []string{"name", "value"}, Capabilities: []string{"matches_fingerprint"}, Sources: secretSources},
		// Sync the project's references now (External Secrets Operator's force-sync).
		Endpoint{Method: "POST", Path: "/projects/{project}/secrets/refresh", Status: 200, Permission: "env.edit", Optional: true,
			Response: []string{"data[].name", "data[].source", "can_view_values"}, Sources: secretSources},

		Endpoint{Method: "GET", Path: "/secret-stores", Status: 200, Permission: "secret_stores.view", Optional: true,
			Response: prefix("data[].", secretStoreFields), Sources: secretStoreSources},
		// The store's settings are the provider's (SecretStore::SETTINGS); an update merges them,
		// null removes one.
		Endpoint{Method: "POST", Path: "/secret-stores", Status: 201, Permission: "secret_stores.manage", Optional: true,
			Request: []string{"name", "provider", "auth", "settings", "refresh_interval"}, FreeForm: []string{"settings"},
			Response: prefix("data.", secretStoreFields), Sources: secretStoreSources},
		Endpoint{Method: "GET", Path: "/secret-stores/{secretStore}", Status: 200, Permission: "secret_stores.view", Optional: true,
			Response: prefix("data.", secretStoreFields), Sources: secretStoreSources},
		Endpoint{Method: "PUT", Path: "/secret-stores/{secretStore}", Status: 200, Permission: "secret_stores.manage", Optional: true,
			Request: []string{"name", "auth", "settings", "refresh_interval"}, FreeForm: []string{"settings"},
			Response: prefix("data.", secretStoreFields), Sources: secretStoreSources},
		// 409 while a secret variable refers to the store
		Endpoint{Method: "DELETE", Path: "/secret-stores/{secretStore}", Status: 204, Permission: "secret_stores.manage", Optional: true},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/openbao", Status: 200, Permission: "regions.view", Optional: true,
			Response: prefix("data.", openBaoFields), Sources: openBaoSources},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/openbao", Status: 202, Permission: "regions.manage", Optional: true,
			Response: concat([]string{"message"}, prefix("data.", openBaoFields)), Sources: openBaoSources},
		Endpoint{Method: "PATCH", Path: "/knative-regions/{knativeRegion}/openbao", Status: 200, Permission: "regions.manage", Optional: true,
			Request: []string{"default_for_values"}, Response: prefix("data.", openBaoFields), Sources: openBaoSources},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/openbao", Status: 202, Permission: "regions.manage", Optional: true,
			Response: concat([]string{"message"}, prefix("data.", openBaoFields)), Sources: openBaoSources},
		Endpoint{Method: "GET", Path: "/secret-stores/{secretStore}/trust", Status: 200, Permission: "secret_stores.view", Optional: true,
			Query: []string{"region_id"},
			Response: []string{"data.issuer", "data.jwks_uri", "data.jwks", "data.pem_keys", "data.publicly_reachable",
				"data.namespace", "data.service_account", "data.subject", "data.audience", "data.region", "data.eso_status"},
			Sources: secretStoreSources},
	)
}
