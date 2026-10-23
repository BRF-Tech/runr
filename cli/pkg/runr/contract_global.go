package runr

// Contract entries of global projects (runr projects global, runr regions global-dns, runr
// global-dns; docs/global-projects.md). The endpoints are Optional: APIs before global projects
// answer 404, which the client reports as "not supported".

// globalRegionFields are the fields of one region of a global project.
var globalRegionFields = []string{"region_id", "region_name", "role", "position", "status", "status_message",
	"version", "target_version", "healthy", "health_checked_at", "address", "in_dns",
	"triggers_active", "data_status", "data_message", "unhealthy_since", "fenced_at"}

// globalBindingFields are the fields of one binding of a global project across its regions.
var globalBindingFields = concat([]string{"binding_id", "name", "type", "plan", "home_region_id", "home_region_name", "access"},
	prefix("regions[].", []string{"region_id", "region_name", "kind", "status", "status_message", "lag_seconds", "checked_at"}))

// globalProjectFields are the fields of GET /projects/{project}/global (and ProjectResource.global).
var globalProjectFields = concat([]string{"mode", "failover", "failover_after_seconds", "moving_to", "hostname", "primary_region_id", "warnings"},
	prefix("regions[].", globalRegionFields), prefix("bindings[].", globalBindingFields))

// globalCreateRequest are the fields of POST /projects that create a global project.
var globalCreateRequest = []string{"placement", "global_mode", "region_ids"}

// projectGlobalCapabilities are the global-project fields of ProjectResource the client reads
// when the API sends them.
var projectGlobalCapabilities = concat([]string{"placement"}, prefix("global.", []string{"mode", "hostname", "primary_region_id"}),
	prefix("global.regions[].", []string{"region_name", "role", "status", "in_dns"}))

// globalDNSServerFields are the fields of one region's global DNS server.
var globalDNSServerFields = []string{"region_id", "region_name", "status", "status_message", "address", "ns_name",
	"reachable", "sync_status", "sync_message", "synced_at", "tls_status", "tls_message", "tls_expires_at",
	"geoip.source", "geoip.url", "geoip.effective_url", "geoip.status", "geoip.message", "geoip.checked_at"}

var globalDNSFields = concat([]string{"zone", "base_domain", "tls", "update_region_id", "last_sync_at",
	"delegation.status", "delegation.message", "delegation.records[].name", "delegation.records[].type",
	"delegation.records[].value"}, prefix("servers[].", globalDNSServerFields))

var regionGlobalDNSFields = concat([]string{"eligible", "reason"}, globalDNSServerFields)

// globalSources build the answers by hand (the generator types them as untyped arrays): the
// controller and the service that writes the global object.
var globalSources = []string{
	"app/Http/Controllers/Api/GlobalProjectController.php",
	"app/Services/GlobalProjects/GlobalProjectService.php",
}

var globalDNSSources = []string{
	"app/Http/Controllers/Api/GlobalDnsController.php",
}

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/projects/{project}/global", Status: 200, Permission: "projects.view",
			Response: globalProjectFields, Sources: globalSources, Optional: true},
		Endpoint{Method: "PUT", Path: "/projects/{project}/global", Status: 200, Permission: "projects.update",
			Request: []string{"mode", "failover", "primary_region_id", "region_ids"}, Response: globalProjectFields,
			Sources: globalSources, Optional: true},
		Endpoint{Method: "POST", Path: "/projects/{project}/global/failover", Status: 202, Permission: "projects.update",
			Request: []string{"region_id"}, Response: globalProjectFields, Sources: globalSources, Optional: true},
		Endpoint{Method: "POST", Path: "/projects/{project}/global/sync", Status: 202, Permission: "projects.deploy",
			Response: []string{"queued"}, Sources: globalSources, Optional: true},

		Endpoint{Method: "GET", Path: "/global-dns", Status: 200, Permission: "regions.view",
			Response: globalDNSFields, Sources: globalDNSSources, Optional: true},
		Endpoint{Method: "POST", Path: "/global-dns/sync", Status: 202, Permission: "regions.manage",
			Response: []string{"queued"}, Sources: globalDNSSources, Optional: true},
		Endpoint{Method: "GET", Path: "/knative-regions/{knativeRegion}/global-dns", Status: 200, Permission: "regions.view",
			Response: regionGlobalDNSFields, Sources: globalDNSSources, Optional: true},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/global-dns", Status: 202, Permission: "regions.manage",
			Response: regionGlobalDNSFields, Sources: globalDNSSources, Optional: true},
		Endpoint{Method: "DELETE", Path: "/knative-regions/{knativeRegion}/global-dns", Status: 202, Permission: "regions.manage",
			Response: regionGlobalDNSFields, Sources: globalDNSSources, Optional: true},
		Endpoint{Method: "PUT", Path: "/knative-regions/{knativeRegion}/global-dns/geoip", Status: 200, Permission: "regions.manage",
			Request: []string{"source", "url"}, Response: globalDNSServerFields, Sources: globalDNSSources, Optional: true},
		Endpoint{Method: "POST", Path: "/knative-regions/{knativeRegion}/global-dns/geoip/reload", Status: 202, Permission: "regions.manage",
			Response: globalDNSServerFields, Sources: globalDNSSources, Optional: true},
	)
}
