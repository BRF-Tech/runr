package runr

import (
	"context"
	"fmt"
	"net/http"
	"strings"
)

// ListRegions returns the regions the token can deploy to (and, for admins, all of them).
func (c *Client) ListRegions(ctx context.Context) ([]Region, error) {
	var out struct {
		Data []Region `json:"data"`
	}
	if err := c.do(ctx, request{method: http.MethodGet, path: "/knative-regions"}, &out); err != nil {
		return nil, err
	}
	return out.Data, nil
}

// GetRegion returns one region by id.
func (c *Client) GetRegion(ctx context.Context, id string) (*Region, error) {
	var raw []byte
	if err := c.do(ctx, request{method: http.MethodGet, path: "/knative-regions/" + id}, &raw); err != nil {
		return nil, err
	}
	var r Region
	if err := decodeData(raw, &r); err != nil {
		return nil, fmt.Errorf("decode region: %w", err)
	}
	return &r, nil
}

// FindRegion resolves a region by id or name. An empty ref picks the only active region.
func (c *Client) FindRegion(ctx context.Context, ref string) (*Region, error) {
	regions, err := c.ListRegions(ctx)
	if err != nil {
		return nil, err
	}
	return PickRegion(regions, ref)
}

// PickRegion applies FindRegion's rule to a region list.
func PickRegion(regions []Region, ref string) (*Region, error) {
	ref = strings.TrimSpace(ref)
	if ref == "" {
		var active []*Region
		for i := range regions {
			if regions[i].IsActive {
				active = append(active, &regions[i])
			}
		}
		switch len(active) {
		case 1:
			return active[0], nil
		case 0:
			return nil, fmt.Errorf("no active region is available")
		default:
			names := make([]string, 0, len(active))
			for _, r := range active {
				names = append(names, r.Name)
			}
			return nil, fmt.Errorf("several regions are available (%s): choose one with region in runr.yaml or --region", strings.Join(names, ", "))
		}
	}
	for i := range regions {
		if regions[i].ID == ref || regions[i].Name == ref {
			return &regions[i], nil
		}
	}
	for i := range regions {
		if strings.EqualFold(regions[i].Name, ref) {
			return &regions[i], nil
		}
	}
	return nil, &NotFoundError{Kind: "region", Ref: ref}
}
