package runrtest

import (
	"strconv"
	"strings"

	"github.com/runrsh/core/cli/pkg/runr"
)

// The fake's uploaded images (api/routes/images.php, docs/images.md): uploads take chunks at
// their offsets (the contiguous prefix counts, like the region's receiver), complete answers 202,
// and GET …/{upload} reports pushing for ImageFake.PushPolls calls before the image appears.

// ImageFake tunes the fake's image endpoints; change it through Server.Images before the calls.
type ImageFake struct {
	// ChunkSize of new uploads (default 4 bytes, so tests send several chunks).
	ChunkSize int64
	// PreparePolls is how many GET …/{upload} a new upload stays "preparing".
	PreparePolls int
	// PushPolls is how many GET …/{upload} a completed upload stays "pushing".
	PushPolls int
	// PushError makes the push fail with this message.
	PushError string
}

type imagesState struct {
	opts    ImageFake
	images  []runr.UploadedImage
	uploads []*fakeImageUpload
}

type fakeImageUpload struct {
	data      runr.ImageUpload
	received  []byte
	pollsLeft int
}

func (s *Server) imageState() *imagesState {
	return featureState(s, "images", func() *imagesState { return &imagesState{} })
}

// Images returns the knobs of the fake's image endpoints.
func (s *Server) Images() *ImageFake {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.imageState().opts
}

// SeedImage adds an uploaded image directly to the fake's state.
func (s *Server) SeedImage(name, regionName string) runr.UploadedImage {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	img := s.newUploadedImage(name, reg, "seed.tar", 1024)
	st := s.imageState()
	st.images = append(st.images, img)
	return img
}

// UploadedImages returns the fake's uploaded images.
func (s *Server) UploadedImages() []runr.UploadedImage {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]runr.UploadedImage(nil), s.imageState().images...)
}

// ReceivedUpload returns the bytes an image upload got so far.
func (s *Server) ReceivedUpload(id string) []byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	if u := s.imageUploadByID(id); u != nil {
		return append([]byte(nil), u.received...)
	}
	return nil
}

// SeedUpload adds an unfinished image upload with the first `received` bytes already in the region.
func (s *Server) SeedUpload(name, regionName, filename string, size int64, sha string, received []byte) runr.ImageUpload {
	s.mu.Lock()
	defer s.mu.Unlock()
	reg := s.regionByName(regionName)
	if reg == nil {
		s.t.Fatalf("runrtest: region %q does not exist", regionName)
	}
	u := s.newImageUpload(reg.ID, name, filename, size, sha, "auto")
	u.received = append([]byte(nil), received...)
	u.data.UploadedBytes = int64(len(received))
	u.data.Status = runr.UploadReady
	return u.data
}

func (s *Server) newUploadedImage(name string, reg *runr.Region, filename string, size int64) runr.UploadedImage {
	repo, tag, _ := strings.Cut(name, ":")
	if tag == "" {
		tag = "latest"
	}
	digest := "sha256:" + strings.Repeat("ab", 32)
	base := "10.43.0.5:5000/runr-uploads/platform/" + repo
	return runr.UploadedImage{
		ID: s.newID(), Name: repo + ":" + tag, Repository: repo, Tag: tag, RegionID: reg.ID, RegionName: reg.Name,
		ImageRef: base + "@" + digest, TagRef: base + ":" + tag, Digest: digest,
		MediaType: "application/vnd.oci.image.manifest.v1+json", Kind: "image", SourceFormat: "docker",
		SizeBytes: size, Platforms: []string{"linux/amd64"}, SourceFilename: filename,
	}
}

func (s *Server) newImageUpload(regionID, name, filename string, size int64, sha, format string) *fakeImageUpload {
	st := s.imageState()
	chunk := st.opts.ChunkSize
	if chunk <= 0 {
		chunk = 4
	}
	u := &fakeImageUpload{data: runr.ImageUpload{
		ID: s.newID(), Name: name, RegionID: regionID, Filename: filename, Format: format, SizeBytes: size,
		SHA256: sha, ChunkSize: chunk, Status: runr.UploadReady,
	}}
	if st.opts.PreparePolls > 0 {
		u.data.Status = runr.UploadPreparing
		u.pollsLeft = st.opts.PreparePolls
	}
	st.uploads = append(st.uploads, u)
	return u
}

func (s *Server) imageUploadByID(id string) *fakeImageUpload {
	for _, u := range s.imageState().uploads {
		if u.data.ID == id {
			return u
		}
	}
	return nil
}

func (s *Server) imageUploadOf(q *featureReq) *fakeImageUpload {
	if len(q.Segs) < 3 {
		return nil
	}
	return s.imageUploadByID(q.Segs[2])
}

func init() {
	handle("GET /images", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.imageState()
		region, kind := q.query("knative_region_id"), q.query("kind")
		list := []runr.UploadedImage{}
		var used int64
		for _, img := range st.images {
			used += img.SizeBytes
			if (region == "" || img.RegionID == region) && (kind == "" || img.Kind == kind) {
				list = append(list, img)
			}
		}
		return 200, map[string]any{"data": list, "quota": runr.ImageQuota{LimitBytes: 50 << 30, UsedBytes: used, Images: len(st.images)}}, nil
	})

	handle("GET /images/{image}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		for _, img := range s.imageState().images {
			if img.ID == q.Segs[1] {
				if img.Layers == 0 {
					img.Layers, img.ArchiveBytes, img.ConfigMediaType = 3, img.SizeBytes+512, "application/vnd.oci.image.config.v1+json"
				}
				return 200, map[string]any{"data": img}, nil
			}
		}
		return 0, nil, errNotFound()
	})

	handle("DELETE /images/{image}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.imageState()
		for i, img := range st.images {
			if img.ID == q.Segs[1] {
				st.images = append(st.images[:i], st.images[i+1:]...)
				return 200, map[string]any{"deleted": true, "registry_removed": true, "id": img.ID, "name": img.Name}, nil
			}
		}
		return 0, nil, errNotFound()
	})

	handle("GET /images/uploads", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.ImageUpload{}
		for _, u := range s.imageState().uploads {
			switch u.data.Status {
			case runr.UploadPreparing, runr.UploadReady, runr.UploadVerifying, runr.UploadPushing:
				list = append(list, u.data)
			}
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("POST /images/uploads", func(s *Server, q *featureReq) (int, any, *apiErr) {
		regionID := q.str("knative_region_id")
		if s.regionByID(regionID) == nil {
			return 0, nil, errValidation("knative_region_id", "The selected knative region id is invalid.")
		}
		size, _ := q.Body["size"].(float64)
		if size < 1 {
			return 0, nil, errValidation("size", "The size field must be at least 1.")
		}
		name := q.str("name")
		if name == "" {
			name = runr.NameFromFilename(q.str("filename")) + ":latest"
		}
		for _, u := range s.imageState().uploads {
			if u.data.Name == name && u.data.RegionID == regionID && (u.data.Status == runr.UploadReady || u.data.Status == runr.UploadPreparing) {
				return 0, nil, &apiErr{status: 409, message: "An upload of " + name + " into this region is already running; finish or abort it first."}
			}
		}
		format := q.str("format")
		if format == "" {
			format = "auto"
		}
		u := s.newImageUpload(regionID, name, q.str("filename"), int64(size), q.str("sha256"), format)
		return 201, map[string]any{"data": u.data}, nil
	})

	handle("GET /images/uploads/{upload}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		u := s.imageUploadOf(q)
		if u == nil {
			return 0, nil, errNotFound()
		}
		st := s.imageState()
		switch u.data.Status {
		case runr.UploadPreparing:
			if u.pollsLeft--; u.pollsLeft <= 0 {
				u.data.Status = runr.UploadReady
			}
		case runr.UploadVerifying, runr.UploadPushing:
			if u.pollsLeft--; u.pollsLeft > 0 {
				u.data.Status = runr.UploadPushing
			} else if st.opts.PushError != "" {
				u.data.Status, u.data.Message = runr.UploadFailed, st.opts.PushError
			} else {
				img := s.newUploadedImage(u.data.Name, s.regionByID(u.data.RegionID), u.data.Filename, u.data.SizeBytes)
				for i, old := range st.images {
					if old.Name == img.Name && old.RegionID == img.RegionID {
						st.images = append(st.images[:i], st.images[i+1:]...)
						break
					}
				}
				st.images = append(st.images, img)
				u.data.Status, u.data.Image = runr.UploadCompleted, &img
			}
		}
		return 200, map[string]any{"data": u.data}, nil
	})

	handle("PUT /images/uploads/{upload}/chunk", func(s *Server, q *featureReq) (int, any, *apiErr) {
		u := s.imageUploadOf(q)
		if u == nil {
			return 0, nil, errNotFound()
		}
		if u.data.Status != runr.UploadReady {
			return 0, nil, &apiErr{status: 409, message: "The upload is " + u.data.Status + " and takes no chunks."}
		}
		offset, err := strconv.ParseInt(q.query("offset"), 10, 64)
		n := int64(len(q.Raw))
		if err != nil || offset < 0 || n == 0 || n > u.data.ChunkSize || offset+n > u.data.SizeBytes {
			return 0, nil, &apiErr{status: 422, message: "The chunk does not fit the upload."}
		}
		if offset <= int64(len(u.received)) {
			u.received = append(u.received[:offset], q.Raw...)
			if int64(len(u.received)) > u.data.UploadedBytes {
				u.data.UploadedBytes = int64(len(u.received))
			}
		}
		return 200, map[string]any{"uploaded_bytes": u.data.UploadedBytes, "size_bytes": u.data.SizeBytes}, nil
	})

	handle("POST /images/uploads/{upload}/complete", func(s *Server, q *featureReq) (int, any, *apiErr) {
		u := s.imageUploadOf(q)
		if u == nil {
			return 0, nil, errNotFound()
		}
		if u.data.UploadedBytes < u.data.SizeBytes {
			return 0, nil, &apiErr{status: 422, message: strconv.FormatInt(u.data.UploadedBytes, 10) + " of " + strconv.FormatInt(u.data.SizeBytes, 10) + " bytes received; send the rest first."}
		}
		u.data.Status = runr.UploadVerifying
		u.pollsLeft = s.imageState().opts.PushPolls + 1
		return 202, map[string]any{"data": u.data}, nil
	})

	handle("DELETE /images/uploads/{upload}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		u := s.imageUploadOf(q)
		if u == nil {
			return 0, nil, errNotFound()
		}
		u.data.Status = runr.UploadAborted
		return 204, nil, nil
	})
}
