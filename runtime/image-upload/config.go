package main

import (
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
