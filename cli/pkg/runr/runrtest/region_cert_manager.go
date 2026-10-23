package runrtest

import (
	"strings"

	"github.com/brf-tech/runr/cli/pkg/runr"
)

// cert-manager of a region in the fake (CertManagerController): an install ends after
// RegionAdminConfig.InstallPolls reads of the status or of the region, ready with RunR's
// cert-manager and the region CA issuer runr-ca (the fake region is not reachable from the
// internet); a removal ends not_installed. SetRegionCertManagerInUse makes removals refused.

const fakeCertManagerNotPublic = "the region's external address 10.0.0.20 is not reachable from the internet (a private or reserved address)"

const fakeCertManagerCA = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIRAJ3fakeregionca\n-----END CERTIFICATE-----\n"

// SetRegionCertManagerInUse sets how many custom-domain certificates of the region come from
// cert-manager (a removal is refused while there are any).
func (s *Server) SetRegionCertManagerInUse(regionName string, certificates int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if reg := s.regionByName(regionName); reg != nil {
		s.rlcRegionOf(reg).certManagerInUse = certificates
	}
}

// SetRegionCertManagerReady makes a region's cert-manager ready with the region CA issuer
// runr-ca, as a finished install leaves it: new custom domains of the region's projects then
// start in TLS mode cert-manager (CertManagerService::defaultTlsMode).
func (s *Server) SetRegionCertManagerReady(regionName string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if reg := s.regionByName(regionName); reg != nil {
		r := s.rlcRegionOf(reg)
		r.certManagerStatus, r.certManagerIssuer = runr.KnativeReady, "runr-ca"
	}
}

// defaultTLSMode is the TLS mode a new custom domain of the region starts in: cert-manager while
// the region's cert-manager is ready with an issuer, none elsewhere.
func (s *Server) defaultTLSMode(reg *runr.Region) string {
	if reg == nil {
		return runr.TLSNone
	}
	r := s.rlcRegionOf(reg)
	if r.certManagerStatus == runr.KnativeReady && r.certManagerIssuer != "" {
		return runr.TLSCertManager
	}
	return runr.TLSNone
}

func fakeCertManagerStatus(r *rlcRegion) string {
	if r.certManagerStatus == "" {
		return runr.KnativeNotInstalled
	}
	return r.certManagerStatus
}

func fakeIssuerKind(issuer string) any {
	switch issuer {
	case "":
		return nil
	case "runr-acme":
		return "acme"
	case "runr-ca":
		return "ca"
	}
	return "own"
}

func (s *Server) fakeCertManagerState(reg *runr.Region, r *rlcRegion, cluster bool) map[string]any {
	status := fakeCertManagerStatus(r)
	ready := status == runr.KnativeReady
	var managed, version, namespace, issuer, source any
	if ready {
		managed, version, namespace, issuer, source = true, "v1.21.2", "cert-manager", r.certManagerIssuer, "runr"
	}
	reason := any(nil)
	switch status {
	case runr.KnativeNotInstalled:
		reason = "cert-manager is not installed in this region."
	case runr.KnativeInstalling:
		reason = "cert-manager is being installed in this region."
	case runr.KnativeError:
		reason = "cert-manager could not be set up in this region (" + lastLineOf(r.certManagerMessage) + ")."
	case runr.KnativeUninstalling:
		reason = "cert-manager is being removed from this region."
	}
	out := map[string]any{
		"status": status, "message": nullStr(r.certManagerMessage), "managed": managed, "version": version, "namespace": namespace,
		"chart_version": "v1.21.2", "install_enabled": true,
		"issuer":      map[string]any{"name": issuer, "kind": fakeIssuerKind(r.certManagerIssuer), "source": source},
		"issuer_mode": "auto",
		"plan":        map[string]any{"kind": "ca", "reason": fakeCertManagerNotPublic},
		"acme": map[string]any{"email": nil, "email_source": nil, "server": "https://acme-v02.api.letsencrypt.org/directory",
			"public": false, "public_reason": fakeCertManagerNotPublic},
		"domains": map[string]any{"available": ready, "status": status, "issuer": issuer, "kind": fakeIssuerKind(r.certManagerIssuer),
			"reason": reason, "ca": ready && r.certManagerIssuer == "runr-ca"},
		"can_manage": s.holds("regions.manage"), "cluster": nil, "cluster_error": nil,
	}
	if cluster && ready && r.kubeconfig != "" && !reg.IsDemo {
		out["cluster"] = map[string]any{"present": true, "runr": true, "namespace": "cert-manager", "version": "v1.21.2",
			"available": true, "drift": []string{}, "issuer_exists": true, "issuer_ready": true, "issuer_message": nil,
			"ca_available": r.certManagerIssuer == "runr-ca", "domain_certificates": r.certManagerInUse}
	}
	return out
}

func lastLineOf(message string) string {
	line := ""
	for _, l := range strings.Split(message, "\n") {
		if strings.TrimSpace(l) != "" {
			line = strings.TrimSpace(l)
		}
	}
	return line
}

func init() {
	handle("GET /knative-regions/{knativeRegion}/cert-manager", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		s.rlcAdvance(r)
		return 200, s.fakeCertManagerState(reg, r, q.query("cluster") != "0"), nil
	})
	handle("POST /knative-regions/{knativeRegion}/cert-manager", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		if r.certManagerStatus == runr.KnativeUninstalling {
			return 0, nil, &apiErr{status: 422, message: "Another operation is in progress on this region."}
		}
		if !r.d.HasServing {
			return 0, nil, &apiErr{status: 422, message: "Install Knative Serving first: the region's Traefik answers the ACME HTTP-01 checks and routes the custom domains."}
		}
		r.certManagerStatus, r.certManagerMessage = runr.KnativeInstalling, "Installation queued..."
		s.rlcStart(r, "cert-manager", func(failed bool, message string) {
			if failed {
				r.certManagerStatus, r.certManagerMessage = runr.KnativeError, message
				return
			}
			r.certManagerStatus, r.certManagerIssuer = runr.KnativeReady, "runr-ca"
			r.certManagerMessage = "cert-manager is ready; custom domains in TLS mode cert-manager are issued by ClusterIssuer runr-ca."
		})
		return 202, s.rlcOperation(reg, r, "cert-manager installation queued."), nil
	})
	handle("DELETE /knative-regions/{knativeRegion}/cert-manager", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if err := s.rlcMutable(reg, r); err != nil {
			return 0, nil, err
		}
		switch fakeCertManagerStatus(r) {
		case runr.KnativeNotInstalled:
			return 0, nil, &apiErr{status: 422, message: "This region does not use cert-manager."}
		case runr.KnativeInstalling, runr.KnativeUninstalling:
			return 0, nil, &apiErr{status: 422, message: "Another operation is in progress on this region."}
		}
		if r.certManagerInUse > 0 {
			return 0, nil, &apiErr{status: 422, message: "Custom-domain certificates of this region come from cert-manager. Switch those domains to another TLS mode (or remove them) first."}
		}
		r.certManagerStatus = runr.KnativeUninstalling
		s.rlcStart(r, "cert-manager", func(failed bool, message string) {
			if failed {
				r.certManagerStatus, r.certManagerMessage = runr.KnativeReady, message
				return
			}
			r.certManagerStatus, r.certManagerMessage, r.certManagerIssuer = runr.KnativeNotInstalled, "", ""
		})
		return 202, s.rlcOperation(reg, r, "cert-manager removal queued."), nil
	})
	handle("GET /knative-regions/{knativeRegion}/cert-manager/ca-certificate", func(s *Server, q *featureReq) (int, any, *apiErr) {
		reg, r, err := s.rlcFind(q)
		if err != nil {
			return 0, nil, err
		}
		if fakeCertManagerStatus(r) != runr.KnativeReady || r.certManagerIssuer != "runr-ca" {
			return 0, nil, &apiErr{status: 404, message: "This region has no region CA."}
		}
		return 200, rawAnswer{contentType: "application/x-pem-file", filename: reg.Name + "-runr-ca.crt", data: []byte(fakeCertManagerCA)}, nil
	})
	// The CA of a project's region (the fake's projects all have one)
	handle("GET /projects/{project}/domains/ca-certificate", func(s *Server, q *featureReq) (int, any, *apiErr) {
		p, err := s.projectOf(q)
		if err != nil {
			return 0, nil, err
		}
		region := s.regionName(p)
		// ?environment=: the CA of the region that environment runs in
		if ref := q.query("environment"); ref != "" && s.Environments {
			e, err := s.environmentRef(p, ref)
			if err != nil {
				return 0, nil, err
			}
			if r := s.regionByID(e.data.RegionID); r != nil {
				region = r.Name
			}
		}
		return 200, rawAnswer{contentType: "application/x-pem-file", filename: region + "-runr-ca.crt", data: []byte(fakeCertManagerCA)}, nil
	})
}
