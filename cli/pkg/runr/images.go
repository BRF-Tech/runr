package runr

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// UploadedImage is an image uploaded into a region registry (docs/images.md).
type UploadedImage struct {
	ID             string           `json:"id"`
	Name           string           `json:"name"`
	Repository     string           `json:"repository"`
	Tag            string           `json:"tag"`
	RegionID       string           `json:"knative_region_id"`
	RegionName     string           `json:"region_name"`
	ImageRef       string           `json:"image_ref"`
	TagRef         string           `json:"tag_ref"`
	Digest         string           `json:"digest"`
	MediaType      string           `json:"media_type"`
	Kind           string           `json:"kind"`
	SourceFormat   string           `json:"source_format"`
	SizeBytes      int64            `json:"size_bytes"`
	Platforms      []string         `json:"platforms"`
	SourceFilename string           `json:"source_filename"`
	Note           string           `json:"note,omitempty"`
	Security       *ImageScanReport `json:"security,omitempty"`
	CreatedAt      *time.Time       `json:"created_at,omitempty"`
	// Detail fields (GET /images/{image}; the list sends them too).
	ConfigMediaType string     `json:"config_media_type,omitempty"`
	ArchiveBytes    int64      `json:"archive_bytes,omitempty"`
	ArchiveSHA256   string     `json:"archive_sha256,omitempty"`
	Layers          int        `json:"layers,omitempty"`
	UploadedBy      FlexID     `json:"uploaded_by,omitempty"`
	UpdatedAt       *time.Time `json:"updated_at,omitempty"`
}

// ImageScanReport is the supply-chain summary of an uploaded container image.
type ImageScanReport struct {
	Status     string         `json:"status"`
	ScanStatus string         `json:"scan_status"`
	Counts     map[string]int `json:"counts"`
}

// ImageQuota is the organization's storage of uploaded images.
type ImageQuota struct {
	LimitBytes int64 `json:"limit_bytes"`
	UsedBytes  int64 `json:"used_bytes"`
	Images     int   `json:"images"`
}

// ImageUpload is one chunked upload into a region.
type ImageUpload struct {
	ID            string         `json:"id"`
	Name          string         `json:"name"`
	RegionID      string         `json:"knative_region_id"`
	Filename      string         `json:"filename"`
	Format        string         `json:"format"`
	SizeBytes     int64          `json:"size_bytes"`
	SHA256        string         `json:"sha256"`
	UploadedBytes int64          `json:"uploaded_bytes"`
	ChunkSize     int64          `json:"chunk_size"`
	Status        string         `json:"status"`
	Message       string         `json:"message"`
	Image         *UploadedImage `json:"image,omitempty"`
}

// Statuses of an image upload beyond those of every upload (UploadPreparing, UploadReady,
// UploadCompleted, UploadFailed, UploadAborted): the region verifies, then pushes the file.
const (
	UploadVerifying = "verifying"
	UploadPushing   = "pushing"
)

// ListImages returns the organization's uploaded images (regionID and kind optional).
func (c *Client) ListImages(ctx context.Context, regionID, kind string) ([]UploadedImage, *ImageQuota, error) {
	q := url.Values{}
	if regionID != "" {
		q.Set("knative_region_id", regionID)
	}
	if kind != "" {
		q.Set("kind", kind)
	}
	var out struct {
		Data  []UploadedImage `json:"data"`
		Quota *ImageQuota     `json:"quota"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/images", query: q}, &out); err != nil {
		return nil, nil, err
	}
	return out.Data, out.Quota, nil
}

// GetImage returns one uploaded image by id.
func (c *Client) GetImage(ctx context.Context, id string) (*UploadedImage, error) {
	var out struct {
		Data UploadedImage `json:"data"`
	}
	err := c.do(ctx, request{method: http.MethodGet, path: "/images/" + seg(id)}, &out)
	if IsNotFound(err) {
		return nil, &NotFoundError{Kind: "image", Ref: id}
	}
	if err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// DeleteImage deletes an uploaded image and its manifest in the region registry.
func (c *Client) DeleteImage(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/images/" + id}, nil)
}

// ListImageUploads returns the organization's unfinished uploads.
func (c *Client) ListImageUploads(ctx context.Context) ([]ImageUpload, error) {
	var out struct {
		Data []ImageUpload `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/images/uploads"}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

// StartImageUploadInput is the body of POST /images/uploads.
type StartImageUploadInput struct {
	RegionID string `json:"knative_region_id"`
	Filename string `json:"filename"`
	Size     int64  `json:"size"`
	SHA256   string `json:"sha256,omitempty"`
	Name     string `json:"name,omitempty"`
	Format   string `json:"format,omitempty"`
}

// StartImageUpload starts an upload; the region's receiver starts behind it (status preparing).
func (c *Client) StartImageUpload(ctx context.Context, in StartImageUploadInput) (*ImageUpload, error) {
	var out struct {
		Data ImageUpload `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/images/uploads", body: in}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// GetImageUpload returns the state of an upload.
func (c *Client) GetImageUpload(ctx context.Context, id string) (*ImageUpload, error) {
	var out struct {
		Data ImageUpload `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/images/uploads/" + id}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// CompleteImageUpload asks the region to verify and push the uploaded file (202).
func (c *Client) CompleteImageUpload(ctx context.Context, id string) (*ImageUpload, error) {
	var out struct {
		Data ImageUpload `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: "/images/uploads/" + id + "/complete"}, &out); err != nil {
		return nil, err
	}
	return &out.Data, nil
}

// AbortImageUpload aborts an upload and removes its receiver.
func (c *Client) AbortImageUpload(ctx context.Context, id string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/images/uploads/" + id}, nil)
}

// UploadImageChunk sends one chunk (raw bytes) at offset and returns the bytes the region has
// from the start of the file without a gap — where to go on. The chunk is written at a fixed
// offset, so it is retried like a GET.
func (c *Client) UploadImageChunk(ctx context.Context, id string, offset int64, chunk []byte) (int64, error) {
	var out struct {
		UploadedBytes int64 `json:"uploaded_bytes"`
	}
	err := c.do(ctx, request{
		method:      http.MethodPut,
		path:        "/images/uploads/" + id + "/chunk",
		query:       url.Values{"offset": {strconv.FormatInt(offset, 10)}},
		raw:         chunk,
		contentType: "application/octet-stream",
		retry:       true,
	}, &out)
	if err != nil {
		return 0, err
	}
	return out.UploadedBytes, nil
}

// PushOptions configure PushImage.
type PushOptions struct {
	RegionID string
	// Name is repository[:tag]; empty takes it from the file name (myapp.tar → myapp:latest).
	Name string
	// Format is auto (default), docker, oci or wasm.
	Format string
	// PollInterval between status checks (default 2s).
	PollInterval time.Duration
	// Progress is called after every chunk and on every phase change.
	Progress func(phase string, uploaded, total int64)
}

// PushImage uploads a docker save / OCI layout tar or a .wasm file into a region registry:
// resumes an unfinished upload of the same file (same name, region, size and SHA-256), sends the
// chunks, completes the upload and waits until the region pushed it.
func (c *Client) PushImage(ctx context.Context, file string, opts PushOptions) (*UploadedImage, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if st.Size() == 0 {
		return nil, fmt.Errorf("%s is empty", file)
	}
	sum, err := fileSHA256(file)
	if err != nil {
		return nil, err
	}

	name := strings.TrimSpace(opts.Name)
	if name == "" {
		name = NameFromFilename(filepath.Base(file)) + ":latest"
	} else if !strings.Contains(name[strings.LastIndex(name, "/")+1:], ":") {
		name += ":latest"
	}
	format := opts.Format
	if format == "" {
		format = "auto"
		if strings.HasSuffix(strings.ToLower(file), ".wasm") {
			format = "wasm"
		}
	}
	interval := opts.PollInterval
	if interval <= 0 {
		interval = 2 * time.Second
	}
	progress := func(phase string, uploaded int64) {
		if opts.Progress != nil {
			opts.Progress(phase, uploaded, st.Size())
		}
	}

	var up *ImageUpload
	if open, err := c.ListImageUploads(ctx); err == nil {
		for i := range open {
			o := open[i]
			if o.Name == name && o.RegionID == opts.RegionID && o.SizeBytes == st.Size() && (o.SHA256 == "" || o.SHA256 == sum) {
				up = &o
				progress("resume", o.UploadedBytes)
				break
			}
		}
	}
	if up == nil {
		up, err = c.StartImageUpload(ctx, StartImageUploadInput{
			RegionID: opts.RegionID, Filename: filepath.Base(file), Size: st.Size(), SHA256: sum, Name: name, Format: format,
		})
		if err != nil {
			return nil, err
		}
	}

	// the region's receiver starts first
	for up.Status == UploadPreparing {
		progress("preparing", 0)
		if err := sleepCtx(ctx, interval); err != nil {
			return nil, err
		}
		if up, err = c.GetImageUpload(ctx, up.ID); err != nil {
			return nil, err
		}
	}
	if up.Status != UploadReady {
		return nil, uploadError(up)
	}

	chunk := up.ChunkSize
	if chunk <= 0 {
		chunk = 8 << 20
	}
	buf := make([]byte, chunk)
	offset := up.UploadedBytes
	progress("uploading", offset)
	for offset < st.Size() {
		n, err := f.ReadAt(buf, offset)
		if err != nil && !errors.Is(err, io.EOF) {
			return nil, err
		}
		if n == 0 {
			break
		}
		received, err := c.UploadImageChunk(ctx, up.ID, offset, buf[:n])
		if err != nil {
			return nil, err
		}
		if received > offset {
			offset = received
		} else {
			offset += int64(n)
		}
		progress("uploading", offset)
	}

	if up, err = c.CompleteImageUpload(ctx, up.ID); err != nil {
		return nil, err
	}
	for {
		progress(up.Status, st.Size())
		switch up.Status {
		case UploadCompleted:
			if up.Image == nil {
				return nil, fmt.Errorf("the upload completed without an image")
			}
			return up.Image, nil
		case UploadVerifying, UploadPushing:
		default:
			// failed, or ready again: the region found the file damaged (run the push again)
			return nil, uploadError(up)
		}
		if err := sleepCtx(ctx, interval); err != nil {
			return nil, err
		}
		if up, err = c.GetImageUpload(ctx, up.ID); err != nil {
			return nil, err
		}
	}
}

func uploadError(up *ImageUpload) error {
	msg := up.Message
	if msg == "" {
		msg = "the upload is " + up.Status
	}
	return fmt.Errorf("upload of %s: %s", up.Name, msg)
}

func sleepCtx(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

var (
	archiveSuffix = regexp.MustCompile(`(\.tar\.gz|\.tgz|\.tar|\.oci|\.wasm|\.gz)+$`)
	nonName       = regexp.MustCompile(`[^a-z0-9]+`)
)

// NameFromFilename is the repository name the API derives from a file name
// (myapp.tar.gz → myapp).
func NameFromFilename(filename string) string {
	base := strings.ToLower(filepath.Base(strings.ReplaceAll(filename, `\`, "/")))
	base = archiveSuffix.ReplaceAllString(base, "")
	base = strings.Trim(nonName.ReplaceAllString(base, "-"), "-")
	if base == "" {
		return "image"
	}
	if len(base) > 128 {
		base = base[:128]
	}
	return base
}

// FindImage resolves an uploaded image by id, or by repository[:tag] (in regionID when given;
// a name in several regions needs one).
func FindImage(images []UploadedImage, ref, regionID string) (*UploadedImage, error) {
	ref = strings.TrimSpace(ref)
	for i := range images {
		if images[i].ID == ref {
			return &images[i], nil
		}
	}
	name := ref
	if !strings.Contains(name[strings.LastIndex(name, "/")+1:], ":") {
		name += ":latest"
	}
	var match []*UploadedImage
	for i := range images {
		if images[i].Name == name && (regionID == "" || images[i].RegionID == regionID) {
			match = append(match, &images[i])
		}
	}
	switch len(match) {
	case 0:
		return nil, &NotFoundError{Kind: "image", Ref: ref}
	case 1:
		return match[0], nil
	default:
		regions := make([]string, 0, len(match))
		for _, m := range match {
			regions = append(regions, m.RegionName)
		}
		return nil, fmt.Errorf("%s is uploaded in several regions (%s): choose one with --region", name, strings.Join(regions, ", "))
	}
}
