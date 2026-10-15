package runr

// imageEndpoints are the uploaded-image endpoints (docs/images.md) `runr images` calls. They are
// Optional: an API without uploaded images answers 404, and the contract test checks them when
// the OpenAPI document has them.
var imageEndpoints = []Endpoint{
	{Method: "GET", Path: "/images", Query: []string{"knative_region_id", "kind"}, Status: 200, Optional: true,
		Permission: "images.view",
		Response: concat(prefix("data[].", []string{"id", "name", "repository", "tag", "knative_region_id", "region_name",
			"image_ref", "tag_ref", "digest", "media_type", "kind", "source_format", "size_bytes", "platforms",
			"source_filename", "created_at"}), []string{"quota.limit_bytes", "quota.used_bytes", "quota.images"}),
		Capabilities: []string{"data[].note", "data[].security"},
		Sources: []string{"app/Http/Resources/Images/UploadedImageResource.php", "app/Services/Images/ImageQuota.php",
			"app/Http/Controllers/Api/Images/ImageController.php"}},
	{Method: "GET", Path: "/images/{image}", Status: 200, Optional: true, Permission: "images.view",
		Response: prefix("data.", []string{"id", "name", "repository", "tag", "knative_region_id", "region_name",
			"image_ref", "tag_ref", "digest", "media_type", "config_media_type", "kind", "source_format", "size_bytes",
			"archive_bytes", "archive_sha256", "platforms", "layers", "source_filename", "uploaded_by", "note",
			"security", "created_at", "updated_at"}),
		Sources: []string{"app/Http/Resources/Images/UploadedImageResource.php"}},
	{Method: "DELETE", Path: "/images/{image}", Status: 200, Optional: true, Permission: "images.delete",
		Sources: []string{"app/Http/Controllers/Api/Images/ImageController.php"}},
	{Method: "GET", Path: "/images/uploads", Status: 200, Optional: true, Permission: "images.upload",
		Response: prefix("data[].", imageUploadFields),
		Sources:  []string{"app/Http/Resources/Images/ImageUploadResource.php"}},
	{Method: "POST", Path: "/images/uploads", Status: 201, Optional: true, Permission: "images.upload",
		Request:  []string{"knative_region_id", "filename", "size", "sha256", "name", "format"},
		Response: prefix("data.", imageUploadFields),
		Sources:  []string{"app/Http/Resources/Images/ImageUploadResource.php"}},
	{Method: "GET", Path: "/images/uploads/{upload}", Status: 200, Optional: true, Permission: "images.upload",
		Response: prefix("data.", imageUploadFields), Capabilities: []string{"data.image"},
		Sources: []string{"app/Http/Resources/Images/ImageUploadResource.php"}},
	{Method: "PUT", Path: "/images/uploads/{upload}/chunk", Query: []string{"offset"}, RawBody: true, Status: 200, Optional: true,
		Permission: "images.upload", Response: []string{"uploaded_bytes", "size_bytes"},
		Sources: []string{"app/Services/Images/ImageUploadService.php"}},
	{Method: "POST", Path: "/images/uploads/{upload}/complete", Status: 202, Optional: true, Permission: "images.upload",
		Response: prefix("data.", imageUploadFields),
		Sources:  []string{"app/Http/Resources/Images/ImageUploadResource.php"}},
	{Method: "DELETE", Path: "/images/uploads/{upload}", Status: 204, Optional: true, Permission: "images.upload"},
}

var imageUploadFields = []string{
	"id", "name", "knative_region_id", "filename", "format", "size_bytes", "sha256", "uploaded_bytes",
	"chunk_size", "status", "message",
}

func init() {
	Contract = append(Contract, imageEndpoints...)
}
