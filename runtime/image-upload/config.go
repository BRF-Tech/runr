package main

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Formats of an upload: a `docker save` tar, an OCI image-layout tar (both optionally gzip'ed),
// or a WebAssembly module / component (.wasm). "auto" detects it from the content.
const (
	FormatAuto   = "auto"
	FormatDocker = "docker"
	FormatOCI    = "oci"
	FormatWasm   = "wasm"
)

// Config of one upload (the receiver Job's environment).
//
//	PORT                      listen port (8080)
//	RUNR_UPLOAD_DIR           working directory, an emptyDir (/work)
//	RUNR_UPLOAD_SIZE          declared size of the file in bytes (required)
//	RUNR_UPLOAD_SHA256        declared SHA-256 of the file (hex, optional)
//	RUNR_UPLOAD_TARGET        registry/repository:tag the upload is pushed to (required)
//	RUNR_UPLOAD_FORMAT        auto | docker | oci | wasm (auto)
//	RUNR_UPLOAD_MAX_CHUNK     largest accepted chunk in bytes (64 MiB)
//	RUNR_UPLOAD_PUSH_TIMEOUT  seconds the push may take (3600)
//	RUNR_REGISTRY_PLAIN_HTTP  "false" pushes over HTTPS; RunR's internal registry is plain HTTP
type Config struct {
	Port        int
	Dir         string
	Size        int64
	SHA256      string
	Target      string
	Format      string
	MaxChunk    int64
	PushTimeout time.Duration
	PlainHTTP   bool
}

var hexDigest = regexp.MustCompile(`^[a-f0-9]{64}$`)

func configFromEnv(getenv func(string) string) (Config, error) {
	cfg := Config{
		Port:        8080,
		Dir:         "/work",
		Format:      FormatAuto,
		MaxChunk:    64 << 20,
		PushTimeout: time.Hour,
		PlainHTTP:   getenv("RUNR_REGISTRY_PLAIN_HTTP") != "false",
	}

	if v := getenv("PORT"); v != "" {
		p, err := strconv.Atoi(v)
		if err != nil || p < 1 || p > 65535 {
			return cfg, fmt.Errorf("PORT %q is not a port", v)
		}
		cfg.Port = p
	}
	if v := getenv("RUNR_UPLOAD_DIR"); v != "" {
		cfg.Dir = v
	}

	size, err := strconv.ParseInt(getenv("RUNR_UPLOAD_SIZE"), 10, 64)
	if err != nil || size < 1 {
		return cfg, fmt.Errorf("RUNR_UPLOAD_SIZE must be a positive number of bytes")
	}
	cfg.Size = size

	if v := strings.ToLower(strings.TrimSpace(getenv("RUNR_UPLOAD_SHA256"))); v != "" {
		if !hexDigest.MatchString(v) {
			return cfg, fmt.Errorf("RUNR_UPLOAD_SHA256 is not a hex SHA-256")
		}
		cfg.SHA256 = v
	}

	cfg.Target = strings.TrimSpace(getenv("RUNR_UPLOAD_TARGET"))
	if cfg.Target == "" || !strings.Contains(cfg.Target, "/") {
		return cfg, fmt.Errorf("RUNR_UPLOAD_TARGET must be registry/repository:tag")
	}

	if v := getenv("RUNR_UPLOAD_FORMAT"); v != "" {
		switch v {
		case FormatAuto, FormatDocker, FormatOCI, FormatWasm:
			cfg.Format = v
		default:
			return cfg, fmt.Errorf("RUNR_UPLOAD_FORMAT %q is not auto, docker, oci or wasm", v)
		}
	}
	if v := getenv("RUNR_UPLOAD_MAX_CHUNK"); v != "" {
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil || n < 1 {
			return cfg, fmt.Errorf("RUNR_UPLOAD_MAX_CHUNK %q is not a positive number", v)
		}
		cfg.MaxChunk = n
	}
	if v := getenv("RUNR_UPLOAD_PUSH_TIMEOUT"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 {
			return cfg, fmt.Errorf("RUNR_UPLOAD_PUSH_TIMEOUT %q is not a positive number of seconds", v)
		}
		cfg.PushTimeout = time.Duration(n) * time.Second
	}

	return cfg, nil
}

// Modes of a transfer agent (transfer.go).
const (
	ModeSend    = "send"    // files a tool container produces (or a pulled image) are downloaded
	ModeReceive = "receive" // files are uploaded in chunks, then a tool container restores them
)

// TransferFile is one file a receiving agent expects (RUNR_TRANSFER_FILES), or one file a sending
// agent offers once the tool is done.
type TransferFile struct {
	Name   string `json:"name"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256,omitempty"`
}

// TransferConfig of one transfer agent (the transfer Job's environment): the region side of an
// organization export or import (docs/org-export.md).
//
//	PORT                         listen port (8080)
//	RUNR_TRANSFER_DIR            working directory, an emptyDir shared with the tool container (/work)
//	RUNR_TRANSFER_MODE           send | receive (required)
//	RUNR_TRANSFER_FILES          receive: JSON [{"name","size","sha256"}] of the files to expect (required)
//	RUNR_TRANSFER_PULL           send: image reference the agent pulls into out/image.tar itself
//	                             (an OCI image-layout tar, every platform of an index) instead of
//	                             waiting for a tool container
//	RUNR_TRANSFER_PULL_TIMEOUT   seconds the pull may take (3600)
//	RUNR_TRANSFER_MAX_CHUNK      largest accepted chunk in bytes (64 MiB)
//	RUNR_TRANSFER_TOKEN          when set, every request but /healthz must carry it in the
//	                             X-Runr-Transfer-Token header (the agent's Service is reachable
//	                             from the cluster while the transfer runs)
//	RUNR_REGISTRY_PLAIN_HTTP     "false" pulls over HTTPS; RunR's internal registry is plain HTTP
type TransferConfig struct {
	Port        int
	Dir         string
	Mode        string
	Files       []TransferFile
	Pull        string
	PullTimeout time.Duration
	MaxChunk    int64
	PlainHTTP   bool
	Token       string
	// Poll is how often the agent looks for the tool container's markers (tests shorten it).
	Poll time.Duration
}

// transferName: a plain file name in the working directory — no path, no dotfile (the markers).
var transferName = regexp.MustCompile(`^[A-Za-z0-9_-][A-Za-z0-9._-]{0,199}$`)

func transferConfigFromEnv(getenv func(string) string) (TransferConfig, error) {
	cfg := TransferConfig{
		Port:        8080,
		Dir:         "/work",
		MaxChunk:    64 << 20,
		PullTimeout: time.Hour,
		PlainHTTP:   getenv("RUNR_REGISTRY_PLAIN_HTTP") != "false",
		Poll:        time.Second,
	}

	if v := getenv("PORT"); v != "" {
		p, err := strconv.Atoi(v)
		if err != nil || p < 1 || p > 65535 {
			return cfg, fmt.Errorf("PORT %q is not a port", v)
		}
		cfg.Port = p
	}
	if v := getenv("RUNR_TRANSFER_DIR"); v != "" {
		cfg.Dir = v
	}

	switch cfg.Mode = getenv("RUNR_TRANSFER_MODE"); cfg.Mode {
	case ModeSend, ModeReceive:
	default:
		return cfg, fmt.Errorf("RUNR_TRANSFER_MODE %q is not send or receive", cfg.Mode)
	}

	if cfg.Mode == ModeReceive {
		if err := json.Unmarshal([]byte(getenv("RUNR_TRANSFER_FILES")), &cfg.Files); err != nil {
			return cfg, fmt.Errorf("RUNR_TRANSFER_FILES must be a JSON list of {name, size, sha256}: %v", err)
		}
		if len(cfg.Files) == 0 {
			return cfg, fmt.Errorf("RUNR_TRANSFER_FILES names no file")
		}
		seen := map[string]bool{}
		for i, f := range cfg.Files {
			if !transferName.MatchString(f.Name) || seen[f.Name] {
				return cfg, fmt.Errorf("RUNR_TRANSFER_FILES: %q is not a plain file name (letters, digits, . _ -, no leading dot) or is listed twice", f.Name)
			}
			seen[f.Name] = true
			if f.Size < 0 {
				return cfg, fmt.Errorf("RUNR_TRANSFER_FILES: %s has a negative size", f.Name)
			}
			cfg.Files[i].SHA256 = strings.ToLower(strings.TrimSpace(f.SHA256))
			if cfg.Files[i].SHA256 != "" && !hexDigest.MatchString(cfg.Files[i].SHA256) {
				return cfg, fmt.Errorf("RUNR_TRANSFER_FILES: the sha256 of %s is not a hex SHA-256", f.Name)
			}
		}
	}

	if v := strings.TrimSpace(getenv("RUNR_TRANSFER_PULL")); v != "" {
		if cfg.Mode != ModeSend {
			return cfg, fmt.Errorf("RUNR_TRANSFER_PULL needs RUNR_TRANSFER_MODE=send")
		}
		cfg.Pull = v
	}
	if v := getenv("RUNR_TRANSFER_PULL_TIMEOUT"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 {
			return cfg, fmt.Errorf("RUNR_TRANSFER_PULL_TIMEOUT %q is not a positive number of seconds", v)
		}
		cfg.PullTimeout = time.Duration(n) * time.Second
	}
	if v := getenv("RUNR_TRANSFER_MAX_CHUNK"); v != "" {
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil || n < 1 {
			return cfg, fmt.Errorf("RUNR_TRANSFER_MAX_CHUNK %q is not a positive number", v)
		}
		cfg.MaxChunk = n
	}
	cfg.Token = strings.TrimSpace(getenv("RUNR_TRANSFER_TOKEN"))

	return cfg, nil
}
