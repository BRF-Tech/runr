package runr

import (
	"context"
	"fmt"
	"sort"
	"strings"
)

// Environment variables come in two kinds (docs/security.md):
//
//   - plain variables live in the project config (config.env_vars); changing them saves a new
//     version;
//   - secret variables are write-only: the project config names them (config.secret_env_keys,
//     never values) and the API keeps the values apart (the /projects/{project}/secrets
//     endpoints, or config.secret_env_vars of a project update).
//
// Either kind reaches the workload with the next deploy, so callers redeploy afterwards. A name
// is plain or secret, never both. Everything about the two kinds is in this file and secrets.go.

// EnvVar is one variable as the client presents it.
type EnvVar struct {
	Name   string `json:"name"`
	Value  string `json:"value,omitempty"`
	Secret bool   `json:"secret"`
	// Revealed is set when Value of a secret was read back (secrets.view_values).
	Revealed  bool       `json:"revealed,omitempty"`
	UpdatedAt string     `json:"updated_at,omitempty"`
	UpdatedBy *SecretRef `json:"updated_by,omitempty"`
	// Source of a secret: SecretSourceRegion (a value RunR wrote into the region) or
	// SecretSourceExternal (a reference to a secret store, Reference and Sync set). Empty for
	// plain variables and on APIs that keep secret values in their database.
	Source    string           `json:"source,omitempty"`
	StoredIn  string           `json:"stored_in,omitempty"`
	Reference *SecretReference `json:"reference,omitempty"`
	Sync      *SecretSync      `json:"sync,omitempty"`
}

// SupportsSecretEnv reports whether the API stores secret variables write-only for p: its config
// names them in secret_env_keys (null for a caller without env.view, still present).
func SupportsSecretEnv(p *Project) bool {
	return p.Config.Raw.Has("secret_env_keys") || p.Config.Raw.Has("secret_env_vars")
}

// EnvList returns a project's variables sorted by name. Secret values are never returned.
func EnvList(p *Project) []EnvVar {
	out := make([]EnvVar, 0, len(p.Config.EnvVars)+len(p.Config.SecretEnvKeys))
	seen := map[string]bool{}
	for _, k := range p.Config.SecretEnvKeys {
		out = append(out, EnvVar{Name: k, Secret: true})
		seen[k] = true
	}
	for _, k := range p.Config.EnvVars.Keys() {
		if seen[k] {
			continue
		}
		out = append(out, EnvVar{Name: k, Value: p.Config.EnvVars[k]})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out
}

// EnvChange is a set of variable changes applied in one call.
type EnvChange struct {
	Set   map[string]string
	Unset []string
	// Secret stores the values of Set as secret variables.
	Secret bool
}

// EnvResult says how an EnvChange was stored.
type EnvResult struct {
	Project *Project
	// SecretStoredPlain is true when Secret was asked for but the API keeps all variables in
	// config.env_vars (readable through the API).
	SecretStoredPlain bool
	// Changed is false when the change matched the current state (no update was sent).
	Changed bool
	// NewVersion is true when plain variables changed: the project has a new version.
	// Secret-only changes keep the latest version.
	NewVersion bool
	// SecretsSet and SecretsRemoved name the secret variables written and removed.
	SecretsSet     []string
	SecretsRemoved []string
}

// reservedEnv are names the platform sets itself (App\Rules\EnvVars::RESERVED).
var reservedEnv = map[string]bool{
	"PORT": true, "K_SERVICE": true, "K_CONFIGURATION": true, "K_REVISION": true,
	"RUNR_MODE": true, "RUNR_HANDLER": true, "RUNR_FILENAME": true,
}

// ValidateEnvName checks a variable name against the API's rules.
func ValidateEnvName(name string) error {
	if name == "" {
		return fmt.Errorf("empty variable name")
	}
	if len(name) > 255 {
		return fmt.Errorf("invalid variable name %q: at most 255 characters", name[:32]+"…")
	}
	for i, r := range name {
		ok := r == '_' || (r >= 'A' && r <= 'Z') || (r >= 'a' && r <= 'z') || (i > 0 && r >= '0' && r <= '9')
		if !ok {
			return fmt.Errorf("invalid variable name %q: use letters, digits and _ and do not start with a digit", name)
		}
	}
	if reservedEnv[strings.ToUpper(name)] {
		return fmt.Errorf("%s is set by RunR and cannot be changed", name)
	}
	return nil
}

// ErrEnvHidden means the caller cannot read a project's variables (permission env.view),
// so they cannot be listed or merged with a change.
func ErrEnvHidden(p *Project) error {
	return &APIError{StatusCode: 403, Method: "GET", Path: "/projects/" + p.ID,
		Message:     "the environment variables of " + p.Name + " are hidden from this token (requires env.view)",
		Permissions: []string{"env.view"}}
}

// ApplyEnv applies change to the project's variables.
//
//   - Plain changes (and names moving between plain and secret) are one PUT /projects/{project}
//     with config.env_vars and, for the moves, config.secret_env_vars — the documented way to
//     change both kinds atomically. It saves a new version.
//   - Changes of secret variables only use the secrets endpoints (PUT and DELETE
//     /projects/{project}/secrets/{name}); they save no version.
//
// On an API without secret variables, Secret falls back to plain variables
// (EnvResult.SecretStoredPlain).
func (c *Client) ApplyEnv(ctx context.Context, p *Project, change EnvChange, message string) (*EnvResult, error) {
	if p.Config.EnvVarsHidden {
		return nil, ErrEnvHidden(p)
	}
	for k := range change.Set {
		if err := ValidateEnvName(k); err != nil {
			return nil, err
		}
	}
	plain := map[string]string{}
	for k, v := range p.Config.EnvVars {
		plain[k] = v
	}
	secretKeys := map[string]bool{}
	for _, k := range p.Config.SecretEnvKeys {
		secretKeys[k] = true
	}
	supported := SupportsSecretEnv(p)
	useSecret := change.Secret && supported
	res := &EnvResult{Project: p, SecretStoredPlain: change.Secret && !useSecret}

	plainChanged := false
	secretSet := map[string]string{}
	secretDel := map[string]bool{}
	for k, v := range change.Set {
		if useSecret {
			// Write-only: the current value is unknown, so every set is a change.
			secretSet[k] = v
			if _, ok := plain[k]; ok {
				delete(plain, k)
				plainChanged = true
			}
			continue
		}
		if secretKeys[k] {
			// Secret → plain: remove the secret in the same update.
			secretDel[k] = true
			plainChanged = true
		}
		if cur, ok := plain[k]; !ok || cur != v {
			plainChanged = true
		}
		plain[k] = v
	}
	for _, k := range change.Unset {
		if _, ok := plain[k]; ok {
			delete(plain, k)
			plainChanged = true
		}
		if secretKeys[k] {
			secretDel[k] = true
		}
	}
	if !plainChanged && len(secretSet) == 0 && len(secretDel) == 0 {
		return res, nil
	}
	if err := checkSecretLimits(p, secretKeys, secretSet, secretDel); err != nil {
		return nil, err
	}
	res.SecretsSet = envSortedKeys(secretSet)
	res.SecretsRemoved = envSortedSet(secretDel)

	if plainChanged {
		cfg := &ConfigInput{EnvVars: &plain}
		if supported && (len(secretSet) > 0 || len(secretDel) > 0) {
			m := map[string]string{}
			for k, v := range secretSet {
				m[k] = v
			}
			for k := range secretDel {
				m[k] = "" // an empty value removes a secret variable
			}
			cfg.SecretEnvVars = &m
		}
		updated, err := c.UpdateProject(ctx, p.ID, UpdateProjectInput{Config: cfg, ConfigMessage: message})
		if err != nil {
			return nil, err
		}
		res.Project = updated
		res.Changed = true
		res.NewVersion = true
		return res, nil
	}

	for _, k := range res.SecretsSet {
		if _, err := c.SetSecret(ctx, p.ID, k, secretSet[k]); err != nil {
			return nil, fmt.Errorf("set secret %s: %w", k, err)
		}
	}
	for _, k := range res.SecretsRemoved {
		if err := c.DeleteSecret(ctx, p.ID, k); err != nil && !IsNotFound(err) {
			return nil, fmt.Errorf("remove secret %s: %w", k, err)
		}
	}
	updated, err := c.GetProject(ctx, p.ID)
	if err != nil {
		return nil, err
	}
	res.Project = updated
	res.Changed = true
	return res, nil
}

// checkSecretLimits refuses a change the API would refuse half-way (one request per secret):
// values above MaxSecretBytes and more than MaxSecretsPerProject secret variables.
func checkSecretLimits(p *Project, current map[string]bool, set map[string]string, del map[string]bool) error {
	if len(set) == 0 {
		return nil
	}
	errs := map[string][]string{}
	for k, v := range set {
		if len(v) > MaxSecretBytes {
			errs["value."+k] = append(errs["value."+k], fmt.Sprintf("A secret value may be at most %d KiB (%s has %d bytes).", MaxSecretBytes/1024, k, len(v)))
		}
	}
	names := map[string]bool{}
	for k := range current {
		names[k] = true
	}
	for k := range set {
		names[k] = true
	}
	for k := range del {
		delete(names, k)
	}
	if len(names) > MaxSecretsPerProject {
		errs["secrets"] = append(errs["secrets"], fmt.Sprintf("A project may have at most %d secret variables.", MaxSecretsPerProject))
	}
	if len(errs) == 0 {
		return nil
	}
	return &APIError{StatusCode: 422, Method: "PUT", Path: "/projects/" + p.ID + "/secrets",
		Message: "the secret variables cannot be stored", Errors: errs}
}

func envSortedKeys(m map[string]string) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

func envSortedSet(m map[string]bool) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}
