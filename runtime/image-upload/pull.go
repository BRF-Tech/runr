package main

import (
	"archive/tar"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"github.com/google/go-containerregistry/pkg/name"
	v1 "github.com/google/go-containerregistry/pkg/v1"
	"github.com/google/go-containerregistry/pkg/v1/empty"
	"github.com/google/go-containerregistry/pkg/v1/layout"
	"github.com/google/go-containerregistry/pkg/v1/remote"
)

// Puller pulls an image into an OCI image-layout tar (pullImage); tests replace it.
type Puller func(ctx context.Context, reference, out string, plainHTTP bool) (*PullResult, error)

// PullResult is what a sending agent reports for a pulled image (the result of GET /status).
type PullResult struct {
	Reference string   `json:"reference"` // the reference pulled, @digest
	Digest    string   `json:"digest"`
	MediaType string   `json:"media_type"`
	Platforms []string `json:"platforms"`
	SizeBytes int64    `json:"size_bytes"` // of the tar
}

// pullImage writes the image (or image index, every platform and attestation it lists) of
// reference into out as a tar of an OCI image layout — what an upload receiver (`serve`) pushes
// unchanged, digest included, into another region's registry. The layout's one entry is named
// after the reference's tag (org.opencontainers.image.ref.name). Blobs are checked against their
// digests while they are read.
func pullImage(ctx context.Context, reference, out string, plainHTTP bool) (*PullResult, error) {
	var nameOpts []name.Option
	if plainHTTP {
		nameOpts = append(nameOpts, name.Insecure)
	}
	ref, err := name.ParseReference(reference, nameOpts...)
	if err != nil {
		return nil, fmt.Errorf("reference %q: %w", reference, err)
	}
	opts := remoteOptions(ctx)

	desc, err := remote.Get(ref, opts...)
	if err != nil {
		return nil, fmt.Errorf("reading %s: %w", reference, err)
	}

	dir := out + ".layout"
	if err := os.RemoveAll(dir); err != nil {
		return nil, err
	}
	defer os.RemoveAll(dir)

	p, err := layout.Write(dir, empty.Index)
	if err != nil {
		return nil, err
	}
	refName := ref.Identifier()
	if tag, ok := ref.(name.Tag); ok {
		refName = tag.TagStr()
	}
	annotate := layout.WithAnnotations(map[string]string{"org.opencontainers.image.ref.name": refName})

	res := &PullResult{Digest: desc.Digest.String(), MediaType: string(desc.MediaType)}
	switch {
	case desc.MediaType.IsIndex():
		idx, err := desc.ImageIndex()
		if err != nil {
			return nil, err
		}
		if err := p.AppendIndex(idx, annotate); err != nil {
			return nil, fmt.Errorf("writing %s: %w", reference, err)
		}
		im, err := idx.IndexManifest()
		if err != nil {
			return nil, err
		}
		res.Platforms = platformList(im.Manifests)
	case desc.MediaType.IsImage():
		img, err := desc.Image()
		if err != nil {
			return nil, err
		}
		if err := p.AppendImage(img, annotate); err != nil {
			return nil, fmt.Errorf("writing %s: %w", reference, err)
		}
		if cf, err := img.ConfigFile(); err == nil && cf.OS != "" {
			res.Platforms = []string{(&v1.Platform{OS: cf.OS, Architecture: cf.Architecture, Variant: cf.Variant}).String()}
		}
	default:
		return nil, fmt.Errorf("%s has the media type %q, which is neither an image nor an image index", reference, desc.MediaType)
	}
	if res.Platforms == nil {
		res.Platforms = []string{}
	}

	size, err := tarLayout(dir, out)
	if err != nil {
		return nil, fmt.Errorf("packing the OCI layout: %w", err)
	}
	res.SizeBytes = size
	res.Reference = ref.Context().Digest(res.Digest).String()
	return res, nil
}

// tarLayout writes the files below dir into the tar out (names relative to dir, slash-separated:
// oci-layout, index.json, blobs/sha256/…); returns its size. Written next to out and renamed, so
// out is either complete or missing.
func tarLayout(dir, out string) (int64, error) {
	tmp := out + ".tmp"
	f, err := os.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o644)
	if err != nil {
		return 0, err
	}
	tw := tar.NewWriter(f)

	walkErr := filepath.Walk(dir, func(p string, info os.FileInfo, err error) error {
		if err != nil || p == dir {
			return err
		}
		rel, err := filepath.Rel(dir, p)
		if err != nil {
			return err
		}
		if !info.Mode().IsRegular() && !info.IsDir() {
			return fmt.Errorf("%s is not a regular file or directory", rel)
		}
		hdr, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return err
		}
		hdr.Name = filepath.ToSlash(rel)
		if info.IsDir() {
			hdr.Name += "/"
		}
		hdr.Uid, hdr.Gid, hdr.Uname, hdr.Gname = 0, 0, "", ""
		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}
		if info.IsDir() {
			return nil
		}
		src, err := os.Open(p)
		if err != nil {
			return err
		}
		defer src.Close()
		_, err = io.Copy(tw, src)
		return err
	})
	closeErr := errors.Join(tw.Close(), f.Close())
	if err := errors.Join(walkErr, closeErr); err != nil {
		os.Remove(tmp)
		return 0, err
	}

	info, err := os.Stat(tmp)
	if err != nil {
		return 0, err
	}
	if err := os.Rename(tmp, out); err != nil {
		os.Remove(tmp)
		return 0, err
	}
	return info.Size(), nil
}
