package runr

// orgTransferEndpoints are the organization export / import endpoints (docs/org-export.md)
// `runr org` calls. They are Optional: an API without them answers 404, and the contract test
// checks them when the OpenAPI document has them. Every one needs the Owner role of the
// organization besides the org.transfer permission (the controller checks the role).
var orgTransferEndpoints = []Endpoint{
	{Method: "GET", Path: "/org-transfers", Status: 200, Optional: true, Permission: "org.transfer",
		Response: prefix("data[].", orgTransferFields), Sources: orgTransferSources},
	{Method: "POST", Path: "/org-transfers/export/estimate", Status: 200, Optional: true, Permission: "org.transfer",
		Request: orgExportOptionFields,
		Response: prefix("data.", []string{"projects", "versions", "code_bytes", "bindings", "binding_data_bytes",
			"images", "total_bytes"}),
		Sources: orgTransferSources},
	{Method: "POST", Path: "/org-transfers/export", Status: 202, Optional: true, Permission: "org.transfer",
		Request: concat(orgExportOptionFields, []string{"passphrase", "destination.type", "destination.binding_id",
			"destination.prefix", "destination.endpoint", "destination.bucket", "destination.region",
			"destination.access_key_id", "destination.secret_access_key", "destination.path_style"}),
		Response: prefix("data.", orgTransferFields), Sources: orgTransferSources},
	{Method: "GET", Path: "/org-transfers/{transfer}", Status: 200, Optional: true, Permission: "org.transfer",
		Response: prefix("data.", orgTransferFields), Sources: orgTransferSources},
	{Method: "GET", Path: "/org-transfers/{transfer}/download", Status: 200, Optional: true, Permission: "org.transfer",
		Sources: orgTransferSources},
	{Method: "DELETE", Path: "/org-transfers/{transfer}", Status: 204, Optional: true, Permission: "org.transfer"},
	{Method: "POST", Path: "/org-transfers/import", Status: 201, Optional: true, Permission: "org.transfer",
		Request: []string{"filename", "size", "sha256", "source.type", "source.binding_id", "source.key", "source.endpoint",
			"source.bucket", "source.region", "source.path_style", "source.access_key_id", "source.secret_access_key"},
		Response: prefix("data.", orgTransferFields), Sources: orgTransferSources},
	{Method: "POST", Path: "/org-transfers/{transfer}/fetch", Status: 202, Optional: true, Permission: "org.transfer",
		Request:  []string{"access_key_id", "secret_access_key"},
		Response: prefix("data.", orgTransferFields), Sources: orgTransferSources},
	{Method: "GET", Path: "/org-transfers/{transfer}/history", Query: []string{"kind", "page", "per_page"}, Status: 200, Optional: true,
		Permission: "org.transfer", Response: []string{"data[].id", "data[].kind", "data[].source_table", "data[].project_id",
			"data[].source_project_id", "data[].subject", "data[].period_date", "data[].data", "meta.total"},
		Sources: concat(orgTransferSources, []string{"app/Models/ImportedHistoryRecord.php"})},
	{Method: "PUT", Path: "/org-transfers/{transfer}/chunk", Query: []string{"offset"}, RawBody: true, Status: 200, Optional: true,
		Permission: "org.transfer", Response: []string{"uploaded_bytes", "size_bytes"}, Sources: orgTransferSources},
	{Method: "POST", Path: "/org-transfers/{transfer}/complete", Status: 202, Optional: true, Permission: "org.transfer",
		Response: prefix("data.", orgTransferFields), Sources: orgTransferSources},
	{Method: "POST", Path: "/org-transfers/{transfer}/plan", Status: 200, Optional: true, Permission: "org.transfer",
		Request: orgImportChoiceFields, FreeForm: orgImportFreeForm,
		Response: prefix("data.", orgTransferFields), Sources: orgTransferSources},
	{Method: "POST", Path: "/org-transfers/{transfer}/apply", Status: 202, Optional: true, Permission: "org.transfer",
		Request:  concat(orgImportChoiceFields, []string{"images", "invite_members", "deploy"}),
		FreeForm: orgImportFreeForm,
		Response: prefix("data.", orgTransferFields), Sources: orgTransferSources},
}

var orgTransferFields = []string{
	"id", "kind", "status", "phase", "progress", "message", "options", "estimate", "destination",
	"package", "upload", "source", "plan", "report", "created_by", "created_at", "updated_at", "finished_at", "expires_at",
}

var orgExportOptionFields = []string{"projects", "include_members", "include_images", "binding_data", "include_secrets", "include_history"}

var orgImportChoiceFields = []string{"region_map", "renames", "binding_renames", "skip_projects", "passphrase",
	"restore_data", "skip_binding_data", "history"}

// orgImportFreeForm are maps keyed by ids of the package (source region / project / binding).
var orgImportFreeForm = []string{"region_map", "renames", "binding_renames"}

var orgTransferSources = []string{"app/Http/Controllers/Api/OrgTransferController.php", "app/Models/OrgTransfer.php", "app/Services/OrgTransfer/OrgExporter.php", "app/Services/OrgTransfer/OrgTransferService.php"}

func init() {
	Contract = append(Contract, orgTransferEndpoints...)
}
