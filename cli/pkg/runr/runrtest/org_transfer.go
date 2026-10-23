package runrtest

import (
	"crypto/sha256"
	"encoding/hex"
	"strconv"
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// The fake's organization export / import (api routes /org-transfers, docs/org-export.md): an
// export runs for OrgTransferFake.ExportPolls GETs and then completes with Package as its file
// (downloadable when the destination is "download", Range bytes=N- answered with 206); an
// import takes chunks at their offsets (the contiguous prefix counts), complete verifies for
// VerifyPolls GETs and then plans; plan applies the choices to the default plan, apply runs for
// ApplyPolls GETs and completes with a report.

// OrgTransferFake tunes the fake's transfer endpoints; change it through Server.OrgTransfers.
type OrgTransferFake struct {
	// ChunkSize of new imports (default 4 bytes, so tests send several chunks).
	ChunkSize int64
	// ExportPolls is how many GETs an export stays running.
	ExportPolls int
	// VerifyPolls is how many GETs a completed upload stays verifying.
	VerifyPolls int
	// ApplyPolls is how many GETs an import stays applying.
	ApplyPolls int
	// ExportError / ApplyError make the export / the apply fail with this message.
	ExportError string
	ApplyError  string
	// Package is the file an export produces (default: a short fixed byte string).
	Package []byte
	// UnmappedRegion makes the plan's region unmapped: a blocking issue until region_map maps
	// it ("src-r1").
	UnmappedRegion bool
	// IgnoreRange makes the download answer a Range request with the whole file (200).
	IgnoreRange bool
	// FetchError makes an import from a binding / S3 source fail reading it (phase fetch);
	// a resume (POST …/fetch) then reads it.
	FetchError string
}

// DefaultOrgPackage is the package an export of the fake produces unless Package is set.
var DefaultOrgPackage = []byte("RUNR-ORG-PACKAGE-0123456789abcdef")

type orgTransfersState struct {
	opts      OrgTransferFake
	transfers []*fakeOrgTransfer
}

type fakeOrgTransfer struct {
	data      runr.OrgTransfer
	received  []byte
	pollsLeft int
	pkg       []byte
	choices   map[string]any
	resumed   bool
}

func (s *Server) orgTransferState() *orgTransfersState {
	return featureState(s, "org-transfers", func() *orgTransfersState { return &orgTransfersState{} })
}

// OrgTransfers returns the knobs of the fake's transfer endpoints.
func (s *Server) OrgTransfers() *OrgTransferFake {
	s.mu.Lock()
	defer s.mu.Unlock()
	return &s.orgTransferState().opts
}

// SeedOrgTransfer adds a transfer of kind (export / import) with status directly to the
// fake's state; a completed export gets the package (downloadable).
func (s *Server) SeedOrgTransfer(kind, status string) runr.OrgTransfer {
	s.mu.Lock()
	defer s.mu.Unlock()
	t := s.newOrgTransfer(kind, status)
	if kind == "export" {
		t.data.Destination = &runr.OrgTransferDestination{Type: "download"}
		if status == runr.OrgTransferCompleted {
			s.finishExport(t)
		}
	}
	if kind == "import" && status == runr.OrgTransferPlanned {
		plan := s.defaultOrgPlan()
		t.data.Plan = &plan
	}
	return t.data
}

// SeedOrgImportUpload adds an import still uploading its package, with the first `received`
// bytes already there.
func (s *Server) SeedOrgImportUpload(filename string, size int64, sha string, received []byte) runr.OrgTransfer {
	s.mu.Lock()
	defer s.mu.Unlock()
	t := s.newOrgImport(filename, size, sha)
	t.received = append([]byte(nil), received...)
	t.data.Upload.UploadedBytes = int64(len(received))
	return t.data
}

// ReceivedOrgImport returns the bytes an import's upload got so far.
func (s *Server) ReceivedOrgImport(id string) []byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	if t := s.orgTransferByID(id); t != nil {
		return append([]byte(nil), t.received...)
	}
	return nil
}

// OrgTransfer returns the fake's state of a transfer.
func (s *Server) OrgTransfer(id string) (runr.OrgTransfer, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if t := s.orgTransferByID(id); t != nil {
		return t.data, true
	}
	return runr.OrgTransfer{}, false
}

func (s *Server) newOrgTransfer(kind, status string) *fakeOrgTransfer {
	now := nowRFC3339()
	t := &fakeOrgTransfer{data: runr.OrgTransfer{
		ID: s.newID(), Kind: kind, Status: status, Options: map[string]any{},
		CreatedBy: &runr.OrgTransferUser{ID: runr.FlexID(s.User.ID), Name: s.User.Name},
		CreatedAt: now, UpdatedAt: now,
	}}
	st := s.orgTransferState()
	st.transfers = append(st.transfers, t)
	return t
}

// LastOrgTransfer is the transfer the fake created last.
func (s *Server) LastOrgTransfer() (runr.OrgTransfer, bool) {
	ts := s.orgTransferState().transfers
	if len(ts) == 0 {
		return runr.OrgTransfer{}, false
	}
	return ts[len(ts)-1].data, true
}

func (s *Server) newOrgImport(filename string, size int64, sha string) *fakeOrgTransfer {
	chunk := s.orgTransferState().opts.ChunkSize
	if chunk <= 0 {
		chunk = 4
	}
	t := s.newOrgTransfer("import", runr.OrgTransferUploading)
	t.data.Phase = "upload"
	t.data.Upload = &runr.OrgUpload{SizeBytes: size, ChunkSize: chunk, SHA256: sha}
	t.data.Package = &runr.OrgPackage{Filename: filename, SizeBytes: size, SHA256: sha}
	return t
}

func (s *Server) orgTransferByID(id string) *fakeOrgTransfer {
	for _, t := range s.orgTransferState().transfers {
		if t.data.ID == id {
			return t
		}
	}
	return nil
}

func (s *Server) orgTransferOf(q *featureReq) (*fakeOrgTransfer, *apiErr) {
	if len(q.Segs) < 2 {
		return nil, errNotFound()
	}
	if t := s.orgTransferByID(q.Segs[1]); t != nil {
		return t, nil
	}
	return nil, errNotFound()
}

func (s *Server) finishExport(t *fakeOrgTransfer) {
	pkg := s.orgTransferState().opts.Package
	if pkg == nil {
		pkg = DefaultOrgPackage
	}
	sum := sha256.Sum256(pkg)
	t.pkg = pkg
	t.data.Status, t.data.Phase, t.data.Progress, t.data.Message = runr.OrgTransferCompleted, "done", 100, ""
	t.data.Package = &runr.OrgPackage{
		Filename: "runr-org-acme-20261021-120000.tar.zst", SizeBytes: int64(len(pkg)), SHA256: hex.EncodeToString(sum[:]),
		SchemaVersion: 1, RunrVersion: s.Version,
		Source:       runr.OrgPackageSource{Organization: "Acme", Slug: "acme", ExportedAt: nowRFC3339()},
		Downloadable: t.data.Destination == nil || t.data.Destination.Type == "download",
	}
	t.data.Report = &runr.OrgTransferReport{Entries: 12, Bytes: int64(len(pkg))}
	t.data.FinishedAt = nowRFC3339()
}

// defaultOrgPlan is the plan of a verified import: one FaaS project with a domain (DNS is a
// manual step) in the package's region eu-1, mapped onto the fake's first region.
func (s *Server) defaultOrgPlan() runr.OrgImportPlan {
	target := runr.Ref{}
	if len(s.regions) > 0 {
		target = runr.Ref{ID: s.regions[0].ID, Name: s.regions[0].Name}
	}
	options := []runr.Ref{}
	for _, r := range s.regions {
		options = append(options, runr.Ref{ID: r.ID, Name: r.Name})
	}
	plan := runr.OrgImportPlan{
		GeneratedAt: nowRFC3339(),
		Source:      runr.OrgPlanSource{Organization: "Acme", Slug: "acme", RunrVersion: "v0.5.10", SchemaVersion: 1, ExportedAt: nowRFC3339()},
		Contents:    map[string]any{"projects": 1, "versions": 3, "bindings": 0, "secrets": "absent"},
		Regions:     []runr.OrgPlanRegion{{SourceID: "src-r1", SourceName: "eu-1", TargetID: target.ID, TargetName: target.Name, Options: options}},
		Projects: []runr.OrgPlanProject{{
			SourceID: "src-p1", Name: "api", TargetName: "api", Slug: "api", ServiceType: "faas", Runtime: "python",
			RuntimeVersion: "3.12", SourceRegion: "eu-1", TargetRegionID: target.ID, TargetRegion: target.Name, Versions: 3,
			Domains: []string{"api.example.com"}, Status: "create",
			Issues: []runr.OrgPlanIssue{{Level: "manual", Code: "dns", Message: "Point api.example.com at the new region and verify it again."}},
		}},
		Bindings:    []runr.OrgPlanBinding{{SourceID: "src-b1", Name: "cache", Type: "kv", TargetName: "cache", Data: true, Status: "create", Issues: []runr.OrgPlanIssue{}}},
		ManualSteps: []runr.OrgManualStep{{Code: "dns", Message: "Point these domains at the new region and verify them again.", Items: []string{"api.example.com"}}},
		CanApply:    true,
	}
	if s.orgTransferState().opts.UnmappedRegion {
		plan.Regions[0].TargetID, plan.Regions[0].TargetName = "", ""
		plan.Projects[0].TargetRegionID, plan.Projects[0].TargetRegion = "", ""
		plan.Projects[0].Issues = append(plan.Projects[0].Issues, runr.OrgPlanIssue{Level: "error", Code: "region_unmapped", Message: "Region eu-1 of the package is not mapped to a region here."})
		plan.Blocking, plan.CanApply = 1, false
	}
	return plan
}

// replan applies import choices to the default plan: region_map maps the package's region,
// renames give the project a new target name, skip_projects marks it skipped.
func (s *Server) replan(t *fakeOrgTransfer, body map[string]any) {
	plan := s.defaultOrgPlan()
	if m, ok := body["region_map"].(map[string]any); ok {
		for i, r := range plan.Regions {
			id, _ := m[r.SourceID].(string)
			if reg := s.regionByID(id); reg != nil {
				plan.Regions[i].TargetID, plan.Regions[i].TargetName = reg.ID, reg.Name
				for j := range plan.Projects {
					plan.Projects[j].TargetRegionID, plan.Projects[j].TargetRegion = reg.ID, reg.Name
					kept := []runr.OrgPlanIssue{}
					for _, is := range plan.Projects[j].Issues {
						if is.Code != "region_unmapped" {
							kept = append(kept, is)
						}
					}
					plan.Projects[j].Issues = kept
				}
				plan.Blocking, plan.CanApply = 0, true
			}
		}
	}
	if m, ok := body["renames"].(map[string]any); ok {
		for i, p := range plan.Projects {
			if name, _ := m[p.SourceID].(string); name != "" {
				plan.Projects[i].TargetName, plan.Projects[i].Slug = name, slugify(name)
			}
		}
	}
	if skip, ok := body["skip_projects"].([]any); ok {
		for _, id := range skip {
			for i, p := range plan.Projects {
				if p.SourceID == id {
					plan.Projects[i].Status = "skip"
				}
			}
		}
	}
	if pass, _ := body["passphrase"].(string); pass != "" {
		plan.Contents["secrets"] = "unlocked"
	}
	t.data.Plan = &plan
	t.choices = body
}

func (s *Server) advanceOrgTransfer(t *fakeOrgTransfer) {
	opts := s.orgTransferState().opts
	switch t.data.Status {
	case runr.OrgTransferQueued, runr.OrgTransferRunning:
		t.data.Status, t.data.Phase = runr.OrgTransferRunning, "projects"
		if t.pollsLeft--; t.pollsLeft > 0 {
			t.data.Progress = 50
			t.data.Message = "Exporting projects"
			return
		}
		if opts.ExportError != "" {
			t.data.Status, t.data.Message = runr.OrgTransferFailed, opts.ExportError
			return
		}
		s.finishExport(t)
	case runr.OrgTransferFetching:
		if opts.FetchError != "" && !t.resumed {
			t.data.Status, t.data.Phase, t.data.Message = runr.OrgTransferFailed, "fetch", opts.FetchError
			return
		}
		t.data.Upload.UploadedBytes, t.data.Upload.SizeBytes = 10, 10
		t.data.Status, t.data.Phase = runr.OrgTransferVerifying, "verify"
		t.pollsLeft = opts.VerifyPolls + 1
	case runr.OrgTransferVerifying:
		if t.pollsLeft--; t.pollsLeft > 0 {
			return
		}
		t.data.Status, t.data.Phase, t.data.Progress = runr.OrgTransferPlanned, "plan", 100
		t.data.Package.SchemaVersion, t.data.Package.RunrVersion = 1, "v0.5.10"
		t.data.Package.Source = runr.OrgPackageSource{Organization: "Acme", Slug: "acme", ExportedAt: nowRFC3339()}
		s.replan(t, map[string]any{})
	case runr.OrgTransferApplying:
		if t.pollsLeft--; t.pollsLeft > 0 {
			t.data.Progress, t.data.Phase = 60, "apply"
			return
		}
		if opts.ApplyError != "" {
			t.data.Status, t.data.Message = runr.OrgTransferFailed, opts.ApplyError
			return
		}
		name := "api"
		if t.data.Plan != nil && len(t.data.Plan.Projects) > 0 {
			name = t.data.Plan.Projects[0].TargetName
		}
		t.data.Status, t.data.Phase, t.data.Progress = runr.OrgTransferCompleted, "done", 100
		t.data.FinishedAt = nowRFC3339()
		t.data.Report = &runr.OrgTransferReport{
			StartedAt: t.data.UpdatedAt, FinishedAt: t.data.FinishedAt,
			Counts: map[string]int{"created": 5, "exists": 0, "skipped": 0, "failed": 0, "manual": 1},
			Items: []runr.OrgReportItem{
				{Section: "project", Source: "src-p1", Name: name, TargetID: "01JNEWPROJECT", Status: "created"},
				{Section: "domain", Source: "api.example.com", Name: "api.example.com", Status: "manual", Message: "Verify the domain again."},
			},
			ManualSteps: t.data.Plan.ManualSteps,
		}
	}
	t.data.UpdatedAt = nowRFC3339()
}

func init() {
	handle("GET /org-transfers", func(s *Server, q *featureReq) (int, any, *apiErr) {
		list := []runr.OrgTransfer{}
		ts := s.orgTransferState().transfers
		for i := len(ts) - 1; i >= 0; i-- {
			list = append(list, ts[i].data)
		}
		return 200, map[string]any{"data": list}, nil
	})

	handle("POST /org-transfers/export/estimate", func(s *Server, q *featureReq) (int, any, *apiErr) {
		n := len(s.projects)
		if ids, ok := q.Body["projects"].([]any); ok && len(ids) > 0 {
			n = len(ids)
		}
		est := runr.OrgExportEstimate{Projects: n, Versions: 2 * n, CodeBytes: int64(4096 * n), Bindings: []runr.OrgEstimateBinding{}}
		if b, _ := q.Body["include_images"].(bool); b {
			est.Images.Count = n
			est.Warnings = []string{"Image sizes are known only when they are pulled; images can be large."}
		}
		est.TotalBytes = est.CodeBytes + est.BindingDataBytes
		return 200, map[string]any{"data": est}, nil
	})

	handle("POST /org-transfers/export", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if secrets, _ := q.Body["include_secrets"].(bool); secrets && len(q.str("passphrase")) < 12 {
			return 0, nil, errValidation("passphrase", "A passphrase of at least 12 characters is required to export secrets.")
		}
		dest := runr.OrgTransferDestination{Type: "download"}
		if d, ok := q.Body["destination"].(map[string]any); ok {
			dest.Type, _ = d["type"].(string)
			switch dest.Type {
			case "download":
			case "binding":
				dest.BindingID, _ = d["binding_id"].(string)
				if dest.BindingID == "" {
					return 0, nil, errValidation("destination.binding_id", "The destination binding is required.")
				}
			case "s3":
				dest.Endpoint, _ = d["endpoint"].(string)
				dest.Bucket, _ = d["bucket"].(string)
				key, _ := d["access_key_id"].(string)
				secret, _ := d["secret_access_key"].(string)
				if dest.Endpoint == "" || dest.Bucket == "" || key == "" || secret == "" {
					return 0, nil, errValidation("destination", "An S3 destination needs endpoint, bucket and credentials.")
				}
			default:
				return 0, nil, errValidation("destination.type", "The destination type must be download, binding or s3.")
			}
		}
		for _, t := range s.orgTransferState().transfers {
			if t.data.Kind == "export" && (t.data.Status == runr.OrgTransferQueued || t.data.Status == runr.OrgTransferRunning) {
				return 0, nil, &apiErr{status: 429, message: "An export of the organization is already running.", code: "too_many_transfers"}
			}
		}
		t := s.newOrgTransfer("export", runr.OrgTransferQueued)
		for _, k := range []string{"projects", "include_members", "include_images", "binding_data", "include_secrets"} {
			if v, ok := q.Body[k]; ok {
				t.data.Options[k] = v
			}
		}
		t.data.Destination = &dest
		t.pollsLeft = s.orgTransferState().opts.ExportPolls + 1
		return 202, map[string]any{"data": t.data}, nil
	})

	handle("GET /org-transfers/{transfer}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		s.advanceOrgTransfer(t)
		return 200, map[string]any{"data": t.data}, nil
	})

	handle("GET /org-transfers/{transfer}/download", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		if t.data.Status != runr.OrgTransferCompleted || t.data.Package == nil || !t.data.Package.Downloadable {
			return 0, nil, &apiErr{status: 409, message: "The package of this transfer cannot be downloaded.", code: "not_downloadable"}
		}
		data := t.pkg
		status := 200
		if r := q.R.Header.Get("Range"); r != "" && !s.orgTransferState().opts.IgnoreRange {
			from, perr := strconv.ParseInt(strings.TrimSuffix(strings.TrimPrefix(r, "bytes="), "-"), 10, 64)
			if perr != nil || from < 0 || from >= int64(len(data)) {
				return 0, nil, &apiErr{status: 416, message: "Range not satisfiable."}
			}
			data, status = data[from:], 206
		}
		return status, rawAnswer{contentType: "application/zstd", filename: t.data.Package.Filename, data: data}, nil
	})

	handle("DELETE /org-transfers/{transfer}", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		if !t.data.Finished() {
			t.data.Status, t.data.Message = runr.OrgTransferCancelled, "Cancelled."
		}
		if t.data.Package != nil {
			t.data.Package.Downloadable = false
		}
		return 204, nil, nil
	})

	handle("POST /org-transfers/import", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if src, ok := q.Body["source"].(map[string]any); ok {
			source := runr.OrgImportSource{}
			source.Type, _ = src["type"].(string)
			source.Key, _ = src["key"].(string)
			switch source.Type {
			case "binding":
				source.BindingID, _ = src["binding_id"].(string)
				if source.BindingID == "" {
					return 0, nil, errValidation("source.binding_id", "The source binding is required.")
				}
			case "s3":
				source.Endpoint, _ = src["endpoint"].(string)
				source.Bucket, _ = src["bucket"].(string)
				source.Region, _ = src["region"].(string)
				key, _ := src["access_key_id"].(string)
				secret, _ := src["secret_access_key"].(string)
				if source.Endpoint == "" || source.Bucket == "" || key == "" || secret == "" {
					return 0, nil, errValidation("source", "An S3 source needs endpoint, bucket and credentials.")
				}
			default:
				return 0, nil, errValidation("source.type", "The source type must be binding or s3.")
			}
			if source.Key == "" {
				return 0, nil, errValidation("source.key", "The source key is required.")
			}
			name := source.Key[strings.LastIndex(source.Key, "/")+1:]
			t := s.newOrgImport(name, 0, q.str("sha256"))
			t.data.Status, t.data.Phase, t.data.Source = runr.OrgTransferFetching, "fetch", &source
			return 202, map[string]any{"data": t.data}, nil
		}
		size, _ := q.Body["size"].(float64)
		if size < 1 {
			return 0, nil, errValidation("size", "The size field must be at least 1.")
		}
		if q.str("filename") == "" {
			return 0, nil, errValidation("filename", "The filename field is required.")
		}
		t := s.newOrgImport(q.str("filename"), int64(size), q.str("sha256"))
		return 201, map[string]any{"data": t.data}, nil
	})

	handle("POST /org-transfers/{transfer}/fetch", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		if t.data.Source == nil || t.data.Status != runr.OrgTransferFailed || t.data.Phase != "fetch" {
			return 0, nil, &apiErr{status: 409, message: "The import is " + t.data.Status + "; only an import whose package could not be read is resumed.", code: "not_resumable"}
		}
		if t.data.Source.Type == "s3" && (q.str("access_key_id") == "" || q.str("secret_access_key") == "") {
			return 0, nil, &apiErr{status: 422, message: "An S3 source needs an access key and a secret key.", code: "s3_credentials"}
		}
		t.resumed = true
		t.data.Status, t.data.Message = runr.OrgTransferFetching, ""
		return 202, map[string]any{"data": t.data}, nil
	})

	handle("GET /org-transfers/{transfer}/history", func(s *Server, q *featureReq) (int, any, *apiErr) {
		if _, err := s.orgTransferOf(q); err != nil {
			return 0, nil, err
		}
		rows := []runr.OrgHistoryRecord{
			{ID: "h1", Kind: "usage", SourceTable: "usage_records", ProjectID: "01JNEWPROJECT", Subject: "api", PeriodDate: "2026-10-20", Data: map[string]any{"session_count": 12}},
			{ID: "h2", Kind: "bill", SourceTable: "billing_bills", ProjectID: "01JNEWPROJECT", Subject: "api", PeriodDate: "2026-09-01", Data: map[string]any{"total": 10}},
		}
		out := []runr.OrgHistoryRecord{}
		for _, r := range rows {
			if kind := q.query("kind"); kind == "" || kind == r.Kind {
				out = append(out, r)
			}
		}
		return 200, map[string]any{"data": out, "meta": map[string]any{"total": len(out)}}, nil
	})

	handle("PUT /org-transfers/{transfer}/chunk", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		if t.data.Status != runr.OrgTransferUploading || t.data.Upload == nil {
			return 0, nil, &apiErr{status: 409, message: "The import is " + t.data.Status + " and takes no chunks.", code: "not_uploading"}
		}
		up := t.data.Upload
		offset, perr := strconv.ParseInt(q.query("offset"), 10, 64)
		n := int64(len(q.Raw))
		if perr != nil || offset < 0 || n == 0 || n > up.ChunkSize || offset+n > up.SizeBytes {
			return 0, nil, &apiErr{status: 422, message: "The chunk does not fit the upload.", code: "bad_chunk"}
		}
		if offset <= int64(len(t.received)) {
			t.received = append(t.received[:offset], q.Raw...)
			if int64(len(t.received)) > up.UploadedBytes {
				up.UploadedBytes = int64(len(t.received))
			}
		}
		return 200, map[string]any{"uploaded_bytes": up.UploadedBytes, "size_bytes": up.SizeBytes}, nil
	})

	handle("POST /org-transfers/{transfer}/complete", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		if t.data.Status != runr.OrgTransferUploading || t.data.Upload == nil {
			return 0, nil, &apiErr{status: 409, message: "The import is " + t.data.Status + ".", code: "not_uploading"}
		}
		if t.data.Upload.UploadedBytes < t.data.Upload.SizeBytes {
			return 0, nil, &apiErr{status: 422, message: "Send the rest of the package first.", code: "upload_incomplete",
				extra: map[string]any{"uploaded_bytes": t.data.Upload.UploadedBytes}}
		}
		t.data.Status, t.data.Phase = runr.OrgTransferVerifying, "verify"
		t.pollsLeft = s.orgTransferState().opts.VerifyPolls + 1
		return 202, map[string]any{"data": t.data}, nil
	})

	handle("POST /org-transfers/{transfer}/plan", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		if t.data.Status != runr.OrgTransferPlanned && t.data.Status != runr.OrgTransferFailed {
			return 0, nil, &apiErr{status: 409, message: "The import is " + t.data.Status + " and cannot be planned."}
		}
		s.replan(t, q.Body)
		return 200, map[string]any{"data": t.data}, nil
	})

	handle("POST /org-transfers/{transfer}/apply", func(s *Server, q *featureReq) (int, any, *apiErr) {
		t, err := s.orgTransferOf(q)
		if err != nil {
			return 0, nil, err
		}
		if t.data.Status != runr.OrgTransferPlanned && t.data.Status != runr.OrgTransferFailed {
			return 0, nil, &apiErr{status: 409, message: "The import is " + t.data.Status + " and cannot be applied."}
		}
		s.replan(t, q.Body)
		if t.data.Plan.Blocking > 0 {
			return 0, nil, &apiErr{status: 422, message: "The import plan has blocking issues.", code: "plan_blocked",
				extra: map[string]any{"data": t.data}}
		}
		t.data.Status, t.data.Phase, t.data.Message = runr.OrgTransferApplying, "apply", ""
		for _, k := range []string{"restore_data", "images", "deploy"} {
			t.data.Options[k] = q.Body[k]
		}
		t.pollsLeft = s.orgTransferState().opts.ApplyPolls + 1
		return 202, map[string]any{"data": t.data}, nil
	})
}
