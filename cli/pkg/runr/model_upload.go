package runr

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"time"
)

// Chunked upload of model files into a region's model volume (air-gapped regions,
// docs/huggingface.md#air-gapped-regions): POST /models/{model}/uploads names every file
// with its size and sha256 and starts a receiver in the region; once the upload is ready each
// file is sent in chunks of at most chunk_size bytes (PUT …/chunk?path=&offset= with the raw
// bytes); complete has the receiver verify sizes and checksums and turns the files into the
// model's offline snapshot in that region.

// Upload statuses (AiModelUpload.status).
const (
	UploadPreparing  = "preparing"
	UploadReady      = "ready"
	UploadCompleting = "completing"
	UploadCompleted  = "completed"
	UploadFailed     = "failed"
	UploadAborted    = "aborted"
)

// DefaultUploadChunkSize is used when the API does not name a chunk size.
const DefaultUploadChunkSize = 8 << 20

// ModelUpload is AiModelUploadResource.
type ModelUpload struct {
	ID         string            `json:"id"`
	AIModelID  string            `json:"ai_model_id"`
	RegionID   string            `json:"knative_region_id"`
	Status     string            `json:"status"`
	ChunkSize  int64             `json:"chunk_size"`
	Files      []ModelUploadFile `json:"files"`
	SnapshotID string            `json:"snapshot_id,omitempty"`
	Message    string            `json:"message,omitempty"`
	ExpiresAt  string            `json:"expires_at,omitempty"`
}

// ModelUploadFile is one file of an upload and how much of it arrived.
type ModelUploadFile struct {
	Path          string `json:"path"`
	Size          int64  `json:"size"`
	UploadedBytes int64  `json:"uploaded_bytes"`
}

// File returns the entry of path, if any.
func (u *ModelUpload) File(path string) *ModelUploadFile {
	for i := range u.Files {
		if u.Files[i].Path == path {
			return &u.Files[i]
		}
	}
	return nil
}

// LocalModelFile is one file of a model directory to upload.
type LocalModelFile struct {
	// Path is relative to the directory, with "/" separators (1_Pooling/config.json).
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256,omitempty"`
	// Abs is the file on disk.
	Abs string `json:"-"`
}

// skippedModelDirs are folders of a model directory that are not part of the model: VCS
// data and the download cache huggingface_hub keeps in a local copy.
var skippedModelDirs = map[string]bool{".git": true, ".cache": true, ".hg": true, ".svn": true}

// ScanModelDir lists the files of a model directory (subfolders included, sorted by path).
// withSHA computes every file's sha256, which the receiver checks. Empty files are returned
// in skipped: the API accepts files of at least one byte.
func ScanModelDir(dir string, withSHA bool) (files []LocalModelFile, skipped []string, err error) {
	root, err := filepath.Abs(dir)
	if err != nil {
		return nil, nil, err
	}
	info, err := os.Stat(root)
	if err != nil {
		return nil, nil, err
	}
	if !info.IsDir() {
		return nil, nil, fmt.Errorf("%s is not a directory", dir)
	}
	err = filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() {
			if p != root && skippedModelDirs[d.Name()] {
				return filepath.SkipDir
			}
			return nil
		}
		if !d.Type().IsRegular() {
			return nil
		}
		rel, err := filepath.Rel(root, p)
		if err != nil {
			return err
		}
		rel = filepath.ToSlash(rel)
		fi, err := d.Info()
		if err != nil {
			return err
		}
		if fi.Size() == 0 {
			skipped = append(skipped, rel)
			return nil
		}
		f := LocalModelFile{Path: rel, Size: fi.Size(), Abs: p}
		if withSHA {
			if f.SHA256, err = fileSHA256(p); err != nil {
				return err
			}
		}
		files = append(files, f)
		return nil
	})
	if err != nil {
		return nil, nil, err
	}
	sort.Slice(files, func(i, j int) bool { return files[i].Path < files[j].Path })
	return files, skipped, nil
}

func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func uploadPath(modelID, uploadID string) string {
	return "/models/" + seg(modelID) + "/uploads/" + seg(uploadID)
}

// StartUpload starts an upload of files into a model (source upload) in a region.
func (c *Client) StartUpload(ctx context.Context, modelID, regionID string, files []LocalModelFile) (*ModelUpload, error) {
	type file struct {
		Path   string `json:"path"`
		Size   int64  `json:"size"`
		SHA256 string `json:"sha256,omitempty"`
	}
	list := make([]file, len(files))
	for i, f := range files {
		list[i] = file{Path: f.Path, Size: f.Size, SHA256: f.SHA256}
	}
	var out ModelUpload
	err := c.do(ctx, request{method: http.MethodPost, path: "/models/" + seg(modelID) + "/uploads",
		body: map[string]any{"knative_region_id": regionID, "files": list}}, &out)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// GetUpload returns an upload with the bytes received per file.
func (c *Client) GetUpload(ctx context.Context, modelID, uploadID string) (*ModelUpload, error) {
	var out ModelUpload
	if err := c.do(ctx, request{method: http.MethodGet, path: uploadPath(modelID, uploadID)}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// UploadChunk writes data at offset of one file of an upload and returns how many bytes of
// the file arrived. Chunks may be repeated, so the request is retried.
func (c *Client) UploadChunk(ctx context.Context, modelID, uploadID, path string, offset int64, data []byte) (int64, error) {
	q := url.Values{"path": {path}, "offset": {strconv.FormatInt(offset, 10)}}
	var out struct {
		Path          string `json:"path"`
		UploadedBytes int64  `json:"uploaded_bytes"`
	}
	raw := data
	if raw == nil {
		raw = []byte{}
	}
	err := c.do(ctx, request{method: http.MethodPut, path: uploadPath(modelID, uploadID) + "/chunk", query: q,
		raw: raw, contentType: "application/octet-stream", retry: true}, &out)
	return out.UploadedBytes, err
}

// CompleteUpload verifies the files and finishes the model's offline snapshot.
func (c *Client) CompleteUpload(ctx context.Context, modelID, uploadID string) (*ModelUpload, *ModelSnapshot, error) {
	var out struct {
		Upload   ModelUpload   `json:"upload"`
		Snapshot ModelSnapshot `json:"snapshot"`
	}
	if err := c.do(ctx, request{method: http.MethodPost, path: uploadPath(modelID, uploadID) + "/complete"}, &out); err != nil {
		return nil, nil, err
	}
	return &out.Upload, &out.Snapshot, nil
}

// AbortUpload aborts an upload and removes its receiver.
func (c *Client) AbortUpload(ctx context.Context, modelID, uploadID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: uploadPath(modelID, uploadID)}, nil)
}

// WaitUploadReady polls an upload until its receiver accepts chunks.
func (c *Client) WaitUploadReady(ctx context.Context, modelID, uploadID string, opts WaitOptions) (*ModelUpload, error) {
	opts = opts.withDefaults()
	ctx, cancel := context.WithTimeout(ctx, opts.Timeout)
	defer cancel()
	last := ""
	what := "upload " + uploadID
	for {
		u, err := c.GetUpload(ctx, modelID, uploadID)
		if err != nil {
			if ctx.Err() != nil {
				return nil, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
			}
			return nil, err
		}
		if u.Status != last {
			last = u.Status
			if opts.Progress != nil {
				opts.Progress(u.Status)
			}
		}
		switch u.Status {
		case UploadReady:
			return u, nil
		case UploadFailed, UploadAborted, UploadCompleted, UploadCompleting:
			return u, &FailedError{What: "upload", Ref: uploadID, Status: u.Status, Message: u.Message}
		}
		select {
		case <-ctx.Done():
			return u, &TimeoutError{What: what, Timeout: opts.Timeout, Last: last}
		case <-time.After(opts.Interval):
		}
	}
}

// UploadProgress is reported after every chunk.
type UploadProgress struct {
	File      string
	FileBytes int64
	FileSize  int64
	// Done and Total count the bytes of all files; FilesDone the files sent completely.
	Done      int64
	Total     int64
	FilesDone int
	Files     int
}

// UploadOptions tune UploadModel.
type UploadOptions struct {
	// ResumeID continues this open upload (its files must match) instead of starting one.
	ResumeID string
	// Ready bounds the wait for the region's receiver.
	Ready WaitOptions
	// Progress is called after every chunk.
	Progress func(UploadProgress)
	// Started is called once the upload exists (with its id, for a later resume).
	Started func(*ModelUpload)
}

// UploadModel uploads files into a model in a region: it starts the upload (or resumes
// ResumeID), waits for the receiver, sends every file in chunks and completes the upload. On
// an error the upload stays open (the returned upload names it); resume it with ResumeID or
// abort it with AbortUpload.
func (c *Client) UploadModel(ctx context.Context, modelID, regionID string, files []LocalModelFile, opts UploadOptions) (*ModelUpload, *ModelSnapshot, error) {
	if len(files) == 0 {
		return nil, nil, errors.New("no files to upload")
	}
	var up *ModelUpload
	var err error
	if opts.ResumeID != "" {
		up, err = c.GetUpload(ctx, modelID, opts.ResumeID)
		if err != nil {
			return nil, nil, err
		}
		for _, f := range files {
			e := up.File(f.Path)
			if e == nil || e.Size != f.Size {
				return up, nil, fmt.Errorf("upload %s does not hold %s with %d bytes: start a new upload", up.ID, f.Path, f.Size)
			}
		}
		if len(up.Files) != len(files) {
			return up, nil, fmt.Errorf("upload %s holds %d files, the directory %d: start a new upload", up.ID, len(up.Files), len(files))
		}
	} else {
		up, err = c.StartUpload(ctx, modelID, regionID, files)
		if err != nil {
			return nil, nil, err
		}
	}
	if opts.Started != nil {
		opts.Started(up)
	}
	if up.Status != UploadReady {
		ready, err := c.WaitUploadReady(ctx, modelID, up.ID, opts.Ready)
		if err != nil {
			return up, nil, err
		}
		up = ready
	}
	chunk := up.ChunkSize
	if chunk <= 0 {
		chunk = DefaultUploadChunkSize
	}

	var total, done int64
	for _, f := range files {
		total += f.Size
	}
	start := make(map[string]int64, len(files))
	for _, f := range files {
		if e := up.File(f.Path); e != nil && e.UploadedBytes > 0 {
			// uploaded_bytes is the highest byte received; chunks may be repeated, so start
			// again at the last chunk boundary below it.
			off := e.UploadedBytes / chunk * chunk
			if e.UploadedBytes >= f.Size {
				off = f.Size
			}
			start[f.Path] = off
			done += off
		}
	}

	buf := make([]byte, chunk)
	filesDone := 0
	for _, f := range files {
		off := start[f.Path]
		if off >= f.Size {
			filesDone++
			continue
		}
		if err := c.uploadFile(ctx, up, f, off, buf, func(n int64, fileBytes int64) {
			done += n
			if opts.Progress != nil {
				opts.Progress(UploadProgress{File: f.Path, FileBytes: fileBytes, FileSize: f.Size, Done: done, Total: total,
					FilesDone: filesDone, Files: len(files)})
			}
		}); err != nil {
			return up, nil, err
		}
		filesDone++
	}
	final, snap, err := c.CompleteUpload(ctx, modelID, up.ID)
	if err != nil {
		return up, nil, err
	}
	return final, snap, nil
}

func (c *Client) uploadFile(ctx context.Context, up *ModelUpload, f LocalModelFile, off int64, buf []byte, sent func(n, fileBytes int64)) error {
	fh, err := os.Open(f.Abs)
	if err != nil {
		return err
	}
	defer fh.Close()
	for off < f.Size {
		if err := ctx.Err(); err != nil {
			return err
		}
		n, err := fh.ReadAt(buf, off)
		if err != nil && !errors.Is(err, io.EOF) {
			return fmt.Errorf("read %s: %w", f.Path, err)
		}
		if n == 0 {
			return fmt.Errorf("read %s: the file is shorter than %d bytes", f.Path, f.Size)
		}
		if _, err := c.UploadChunk(ctx, up.AIModelID, up.ID, f.Path, off, buf[:n]); err != nil {
			return fmt.Errorf("upload %s at offset %d: %w", f.Path, off, err)
		}
		off += int64(n)
		sent(int64(n), off)
	}
	return nil
}
