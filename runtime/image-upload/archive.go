package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"strings"
)

var (
	gzipMagic = []byte{0x1f, 0x8b}
	wasmMagic = []byte{0x00, 'a', 's', 'm'}
)

// detect tells the format of an uploaded file: a WebAssembly binary by its magic, a tar by its
// entries — oci-layout + index.json is an OCI image layout (also what `docker save` writes since
// Docker 25, next to its manifest.json), manifest.json alone a classic `docker save` archive.
// A gzip'ed tar is decompressed next to the file first; the returned path is the tar to read.
func detect(file string) (format, tarPath string, err error) {
	head, err := readHead(file, 8)
	if err != nil {
		return "", "", err
	}
	if bytes.HasPrefix(head, wasmMagic) {
		return FormatWasm, file, nil
	}

	tarPath = file
	if bytes.HasPrefix(head, gzipMagic) {
		tarPath = file + ".tar"
		if err := gunzip(file, tarPath); err != nil {
			return "", "", fmt.Errorf("the file is gzip-compressed but could not be decompressed: %w", err)
		}
	}

	names, err := tarNames(tarPath)
	if err != nil {
		return "", "", fmt.Errorf("the file is not a tar archive, a gzip'ed tar or a WebAssembly module: %w", err)
	}
	switch {
	case names["oci-layout"] && names["index.json"]:
		return FormatOCI, tarPath, nil
	case names["manifest.json"]:
		return FormatDocker, tarPath, nil
	default:
		return "", "", errors.New("the tar archive is neither an OCI image layout (oci-layout, index.json) nor a `docker save` archive (manifest.json)")
	}
}

func readHead(file string, n int) ([]byte, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	buf := make([]byte, n)
	m, err := io.ReadFull(f, buf)
	if err != nil && !errors.Is(err, io.ErrUnexpectedEOF) {
		return nil, err
	}
	return buf[:m], nil
}

func gunzip(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	zr, err := gzip.NewReader(in)
	if err != nil {
		return err
	}
	defer zr.Close()
	out, err := os.Create(dst)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, zr); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}

// tarNames lists the cleaned entry names of a tar (headers only; the file seeks over contents).
func tarNames(file string) (map[string]bool, error) {
	f, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	names := map[string]bool{}
	tr := tar.NewReader(f)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, err
		}
		if name, ok := cleanEntry(hdr.Name); ok {
			names[name] = true
		}
	}
	if len(names) == 0 {
		return nil, errors.New("the archive is empty")
	}
	return names, nil
}

// cleanEntry normalizes a tar entry name ("./blobs/sha256/…" → "blobs/sha256/…"); false for
// names that would leave the extraction directory.
func cleanEntry(name string) (string, bool) {
	name = strings.TrimPrefix(strings.ReplaceAll(name, "\\", "/"), "./")
	if name == "" || strings.HasPrefix(name, "/") || strings.Contains(name, "\x00") {
		return "", false
	}
	clean := path.Clean(name)
	if clean == "." || clean == ".." || strings.HasPrefix(clean, "../") {
		return "", false
	}
	return clean, true
}

// extractLayout writes the OCI layout entries of a tar (oci-layout, index.json, blobs/…) into
// dir. Links, devices and names that leave dir are refused; other entries (docker save's
// manifest.json, repositories) are skipped.
func extractLayout(tarPath, dir string) error {
	f, err := os.Open(tarPath)
	if err != nil {
		return err
	}
	defer f.Close()

	tr := tar.NewReader(f)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		name, ok := cleanEntry(hdr.Name)
		if !ok {
			return fmt.Errorf("the archive entry %q leaves the archive", hdr.Name)
		}
		if name != "oci-layout" && name != "index.json" && !strings.HasPrefix(name, "blobs/") {
			continue
		}

		target := filepath.Join(dir, filepath.FromSlash(name))
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			out, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o644)
			if err != nil {
				return err
			}
			if _, err := io.Copy(out, tr); err != nil {
				out.Close()
				return err
			}
			if err := out.Close(); err != nil {
				return err
			}
		default:
			return fmt.Errorf("the archive entry %q is not a regular file or directory", hdr.Name)
		}
	}
}
