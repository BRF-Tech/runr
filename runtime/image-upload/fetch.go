package main

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"github.com/google/go-containerregistry/pkg/name"
	v1 "github.com/google/go-containerregistry/pkg/v1"
	"github.com/google/go-containerregistry/pkg/v1/remote"
	"github.com/google/go-containerregistry/pkg/v1/types"
)

// wasmLayerTypes are the layer media types that carry a WebAssembly binary.
var wasmLayerTypes = map[types.MediaType]bool{
	WasmLayerMediaType:   true,
	SpinLayerMediaType:   true,
	ModuleLayerMediaType: true,
}

// fetchModule writes the WebAssembly binary of an OCI artifact to out: the layer titled
// module.wasm (what RunR's uploads write), else the first WebAssembly layer. For an image index
// the first entry with a WebAssembly layer is used. The blob is checked against its digest while
// it is read; the file must start with the WebAssembly magic. Returns the layer digest.
func fetchModule(ctx context.Context, reference, out string, plainHTTP bool) (string, error) {
	var nameOpts []name.Option
	if plainHTTP {
		nameOpts = append(nameOpts, name.Insecure)
	}
	ref, err := name.ParseReference(reference, nameOpts...)
	if err != nil {
		return "", fmt.Errorf("reference %q: %w", reference, err)
	}
	opts := remoteOptions(ctx)

	desc, err := remote.Get(ref, opts...)
	if err != nil {
		return "", fmt.Errorf("reading %s: %w", reference, err)
	}

	var images []v1.Image
	if desc.MediaType.IsIndex() {
		idx, err := desc.ImageIndex()
		if err != nil {
			return "", err
		}
		im, err := idx.IndexManifest()
		if err != nil {
			return "", err
		}
		for _, d := range im.Manifests {
			if img, err := idx.Image(d.Digest); err == nil {
				images = append(images, img)
			}
		}
	} else {
		img, err := desc.Image()
		if err != nil {
			return "", err
		}
		images = []v1.Image{img}
	}

	for _, img := range images {
		layer, digest, err := moduleLayer(img)
		if err != nil {
			return "", err
		}
		if layer == nil {
			continue
		}
		if err := writeLayer(layer, out); err != nil {
			return "", err
		}
		return digest, nil
	}
	return "", fmt.Errorf("%s has no WebAssembly layer (application/wasm or %s)", reference, SpinLayerMediaType)
}

func moduleLayer(img v1.Image) (v1.Layer, string, error) {
	m, err := img.Manifest()
	if err != nil {
		return nil, "", err
	}
	var pick *v1.Descriptor
	for i, l := range m.Layers {
		if !wasmLayerTypes[l.MediaType] {
			continue
		}
		if pick == nil || l.Annotations[titleAnnotation] == "module.wasm" {
			pick = &m.Layers[i]
		}
	}
	if pick == nil {
		return nil, "", nil
	}
	layer, err := img.LayerByDigest(pick.Digest)
	if err != nil {
		return nil, "", err
	}
	return layer, pick.Digest.String(), nil
}

func writeLayer(layer v1.Layer, out string) error {
	rc, err := layer.Compressed() // the blob as stored: verified against its digest while read
	if err != nil {
		return err
	}
	defer rc.Close()

	tmp, err := os.CreateTemp(filepath.Dir(out), ".module-*.wasm")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())

	if _, err := io.Copy(tmp, rc); err != nil {
		tmp.Close()
		return fmt.Errorf("downloading the module: %w", err)
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	head, err := readHead(tmp.Name(), 4)
	if err != nil {
		return err
	}
	if !bytes.Equal(head, wasmMagic) {
		return errors.New("the layer is not a WebAssembly binary")
	}
	return os.Rename(tmp.Name(), out)
}
