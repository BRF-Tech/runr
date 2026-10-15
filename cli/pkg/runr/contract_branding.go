package runr

// White-label branding (routes/white-label.php; the organization brand is a route of the
// Tenancy module).

var brandingRequestFields = concat(BrandingTextFields, []string{"footer_links", "powered_by"})

var brandingResponseFields = concat([]string{"stored", "branding", "tenant_overrides_enabled"},
	prefix("stored.", brandingRequestFields))

// brandingUploadMarkers are the stored.<asset>_uploaded flags of images uploaded in the console
// (built from the asset kind in BrandingService::adminPayload, so not literal keys there).
var brandingUploadMarkers = []string{
	"stored.logo_light_uploaded", "stored.logo_dark_uploaded", "stored.logo_mark_uploaded",
	"stored.favicon_uploaded", "stored.email_logo_uploaded",
}

var brandingSources = []string{
	"app/Services/WhiteLabel/BrandingService.php", "app/Http/Controllers/Api/WhiteLabel/BrandingController.php",
}

// An organization's brand has no powered_by (BrandingService::PLATFORM_ONLY): the API neither
// takes nor stores it.
var tenantBrandingRequestFields = concat(BrandingTextFields, []string{"footer_links"})

var tenantBrandingResponseFields = concat([]string{"stored", "branding", "tenant_overrides_enabled", "enabled"},
	prefix("stored.", tenantBrandingRequestFields))

var tenantBrandingSources = concat(brandingSources,
	[]string{"Modules/Tenancy/app/Http/Controllers/WhiteLabel/TenantBrandingController.php"})

func init() {
	Contract = append(Contract,
		Endpoint{Method: "GET", Path: "/admin/branding", Status: 200, Permission: "branding.manage",
			Response: brandingResponseFields, Capabilities: brandingUploadMarkers, Sources: brandingSources},
		Endpoint{Method: "PUT", Path: "/admin/branding", Status: 200, Permission: "branding.manage",
			Request:  concat(brandingRequestFields, []string{"tenant_overrides_enabled"}),
			FreeForm: []string{"footer_links"},
			Response: brandingResponseFields, Capabilities: brandingUploadMarkers, Sources: brandingSources},
		Endpoint{Method: "GET", Path: "/tenant/branding", Status: 200, Optional: true, Permission: "branding.manage",
			Response: tenantBrandingResponseFields, Capabilities: brandingUploadMarkers, Sources: tenantBrandingSources},
		Endpoint{Method: "PUT", Path: "/tenant/branding", Status: 200, Optional: true, Permission: "branding.manage",
			Request: tenantBrandingRequestFields, FreeForm: []string{"footer_links"},
			Response: tenantBrandingResponseFields, Capabilities: brandingUploadMarkers, Sources: tenantBrandingSources},
	)
}
