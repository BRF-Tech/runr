package runrtest

// The audit log's retention (AuditEventController::settings / updateSettings) of the fake.

type auditSettingsState struct {
	retention int
	// readOnly: the caller may not change the retention (a tenant's user on a multi-tenant
	// installation); can_update is false and PUT answers 403.
	readOnly bool
}

func (s *Server) auditSettings() *auditSettingsState {
	return featureState(s, "audit-settings", func() *auditSettingsState { return &auditSettingsState{retention: 365} })
}

// SetAuditSettingsReadOnly makes the audit retention one the caller may not change.
func (s *Server) SetAuditSettingsReadOnly() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.auditSettings().readOnly = true
}

// AuditRetentionDays returns the fake's audit retention.
func (s *Server) AuditRetentionDays() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.auditSettings().retention
}

func (s *Server) auditSettingsJSON() map[string]any {
	st := s.auditSettings()
	return map[string]any{"retention_days": st.retention, "max_days": 3650, "forward_enabled": false, "can_update": !st.readOnly}
}

func init() {
	handle("GET /audit-settings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		return 200, s.auditSettingsJSON(), nil
	})
	handle("PUT /audit-settings", func(s *Server, q *featureReq) (int, any, *apiErr) {
		st := s.auditSettings()
		if st.readOnly {
			return 0, nil, &apiErr{status: 403, message: "The audit retention is changed by platform administrators outside an organization."}
		}
		v, ok := q.Body["retention_days"].(float64)
		if !ok || v != float64(int(v)) || v < 0 || v > 3650 {
			return 0, nil, errValidation("retention_days", "The retention days field must be an integer between 0 and 3650.")
		}
		st.retention = int(v)
		return 200, s.auditSettingsJSON(), nil
	})
}
