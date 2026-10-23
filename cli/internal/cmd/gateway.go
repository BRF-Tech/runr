package cmd

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/brf-tech/runr/cli/internal/output"
	"github.com/brf-tech/runr/cli/pkg/runr"
)

func init() { registerCommand(newGatewayCmd) }

// API product of the gateways (docs/api-product.md): `runr gateway` lists the caller's gateways
// and manages route authentication, consumers, keys, plans, the custom domain and reads usage.

func newGatewayCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "gateway",
		Aliases: []string{"gateways", "gw"},
		Short:   "API gateways: route authentication, consumers, API keys, plans and usage",
		Long: "A gateway serves projects under paths of https://<user>.<region>.<domain>. Its routes are\n" +
			"public or ask for an API key or a JWT; consumers get keys and plans (rate limits, quotas),\n" +
			"and RunR measures their calls for your invoicing system (docs/api-product.md).\n" +
			"A GATEWAY argument is the gateway's id, its region's name or its host.",
	}
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List your gateways",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			gateways, err := c.ListGateways(cmd.Context())
			if err != nil {
				return err
			}
			return app.Printer().Print(gateways, func(t *output.TableWriter) {
				t.Header("ID", "REGION", "HOST", "ROUTES", "PROTECTED", "STATUS")
				for _, g := range gateways {
					protected := 0
					for _, r := range g.Routes {
						if r.AuthMode == runr.RouteAuthKey || r.AuthMode == runr.RouteAuthJWT {
							protected++
						}
					}
					t.Row(g.ID, gatewayRegion(g), gatewayHost(g), strconv.Itoa(len(g.Routes)), strconv.Itoa(protected), g.Status)
				}
				if len(gateways) == 0 {
					t.Note("No gateways: a project created with the gateway endpoint gets one.")
				}
			})
		},
	}
	cmd.RunE = ls.RunE
	cmd.AddCommand(ls, newGatewayCreateCmd(app), newGatewayGetCmd(app), newGatewayRoutesCmd(app), newGatewayConsumersCmd(app),
		newGatewayKeysCmd(app), newGatewayPlansCmd(app), newGatewayUsageCmd(app), newGatewayDomainCmd(app),
		newGatewayPageCmd(app))
	return cmd
}

func gatewayRegion(g runr.Gateway) string {
	if g.Region != nil {
		return g.Region.Name
	}
	return g.RegionID
}

func gatewayHost(g runr.Gateway) string {
	if g.CustomDomain != "" {
		return g.CustomDomain
	}
	return g.Host
}

// resolveGateway finds a gateway by id, region name, host or custom domain.
func resolveGateway(ctx context.Context, c *runr.Client, ref string) (*runr.Gateway, error) {
	gateways, err := c.ListGateways(ctx)
	if err != nil {
		return nil, err
	}
	ref = strings.TrimSpace(ref)
	for _, g := range gateways {
		if g.ID == ref || gatewayRegion(g) == ref || g.Host == ref || (g.CustomDomain != "" && g.CustomDomain == ref) {
			return &g, nil
		}
	}
	return nil, usageErrorf("no gateway %q (runr gateway ls lists them)", ref)
}

// resolveRoute finds a route of g by id or path.
func resolveRoute(g *runr.Gateway, ref string) (*runr.GatewayRoute, error) {
	for _, r := range g.Routes {
		if r.ID == ref || r.Path == ref || r.Path == "/"+strings.TrimPrefix(ref, "/") {
			return &r, nil
		}
	}
	return nil, usageErrorf("gateway %s has no route %q", gatewayHost(*g), ref)
}

func routeAuthLabel(r runr.GatewayRoute) string {
	mode := r.AuthMode
	if mode == "" {
		mode = runr.RouteAuthPublic
	}
	if r.GatewayOnly {
		mode += ", gateway only"
	}
	return mode
}

func newGatewayGetCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "get GATEWAY",
		Short: "Show a gateway with its routes, domain and developer page",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(g, func(t *output.TableWriter) {
				t.KV("ID", g.ID)
				t.KV("Region", gatewayRegion(*g))
				t.KV("Host", g.Host)
				if g.CustomDomain != "" {
					t.KV("Custom domain", g.CustomDomain)
				}
				if p := g.CustomDomainPending; p != nil {
					t.KV("Pending domain", fmt.Sprintf("%s (TXT %s = %s)", p.Domain, p.Verification.Name, p.Verification.Value))
				}
				if g.RateLimit != nil {
					t.KV("IP rate limit", fmt.Sprintf("%d/s (burst %d)", g.RateLimit.Average, g.RateLimit.Burst))
				}
				if g.DeveloperPage {
					t.KV("Developer page", strings.TrimRight(c.ConsoleURL(), "/")+"/developers/"+g.DeveloperPageKey)
				}
				t.KV("Status", g.Status)
				if g.StatusMessage != "" {
					t.KV("Why", g.StatusMessage)
				}
				t.Header("ROUTE", "PATH", "PROJECT", "METHODS", "AUTH")
				for _, r := range g.Routes {
					project := ""
					if r.Project != nil {
						project = r.Project.Name
					}
					// a route to an environment of the project (docs/environments.md)
					if r.Environment != "" {
						project += " (" + r.Environment + ")"
					}
					t.Row(r.ID, r.Path, project, joinOrDash(r.Methods), routeAuthLabel(r))
				}
			})
		},
	}
}

func newGatewayCreateCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:   "create REGION",
		Short: "Your gateway in a region, created when you have none there",
		Long: "Every member has at most one gateway per region. A route leads to a workload in the\n" +
			"gateway's region: an environment that runs in another region than the project needs a\n" +
			"gateway there (docs/environments.md#gateway-routes). Answers the existing gateway when\n" +
			"there is one.",
		Example: "  runr gateway create eu-2\n" +
			"  runr gateway routes add eu-2 /orders-staging -p orders --env staging",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			region, err := c.FindRegion(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			g, err := c.EnsureGateway(cmd.Context(), region.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(g, func(t *output.TableWriter) {
				t.KV("ID", g.ID)
				t.KV("Region", gatewayRegion(*g))
				t.KV("Host", g.Host)
				t.KV("Routes", strconv.Itoa(len(g.Routes)))
				t.KV("Status", g.Status)
			})
		},
	}
}

func newGatewayRoutesCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "routes",
		Short: "Routes of a gateway: add, remove, list, and how callers authenticate",
	}
	cmd.AddCommand(newGatewayRoutesLsCmd(app), newGatewayRoutesAddCmd(app), newGatewayRoutesRmCmd(app))
	var flags routeAuthFlags
	set := &cobra.Command{
		Use:   "set GATEWAY ROUTE",
		Short: "Make a route public, or ask for an API key or a JWT; gateway-only",
		Long: "ROUTE is the route's id or path. --auth key: callers send a consumer's key (X-Api-Key or\n" +
			"Authorization: Bearer). --auth jwt: callers send a token the issuer's JWKS verifies\n" +
			"(--jwks-url, optional --issuer / --audience; --subject-claim names the consumer).\n" +
			"--gateway-only: the project's own URL stops serving, only the gateway reaches it.",
		Example: "  runr gateway routes set eu-1 /orders --auth key\n" +
			"  runr gateway routes set eu-1 /partners --auth jwt --jwks-url https://id.example.com/jwks --audience partners\n" +
			"  runr gateway routes set eu-1 /orders --gateway-only",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			in, err := flags.input(cmd)
			if err != nil {
				return err
			}
			if in.AuthMode == "" && in.JWT == nil && in.GatewayOnly == nil {
				return usageErrorf("nothing to change: pass --auth, --jwks-url or --gateway-only")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			r, err := resolveRoute(g, args[1])
			if err != nil {
				return err
			}
			updated, err := c.UpdateRouteAuth(cmd.Context(), g.ID, r.ID, in)
			if err != nil {
				return err
			}
			return app.Printer().Print(updated, func(t *output.TableWriter) {
				t.Note("Route %s: %s. The region applies it within seconds.", updated.Path, routeAuthLabel(*updated))
			})
		},
	}
	flags.bind(set, "public, key or jwt", "the project's own URL stops serving (--gateway-only=false opens it again)")
	cmd.AddCommand(set)
	return cmd
}

// routeAuthFlags are the flags of a route's authentication and gateway-only setting, the same
// for runr gateway routes add and set.
type routeAuthFlags struct {
	auth, jwksURL, issuer, audience, subjectClaim string
	requireConsumer, gatewayOnly                  bool
}

func (f *routeAuthFlags) bind(cmd *cobra.Command, authUsage, gatewayOnlyUsage string) {
	fs := cmd.Flags()
	fs.StringVar(&f.auth, "auth", "", authUsage)
	fs.StringVar(&f.jwksURL, "jwks-url", "", "jwt: the JWKS URL of the token issuer")
	fs.StringVar(&f.issuer, "issuer", "", "jwt: expected iss claim")
	fs.StringVar(&f.audience, "audience", "", "jwt: expected aud claim")
	fs.StringVar(&f.subjectClaim, "subject-claim", "", "jwt: claim that names the consumer (default sub)")
	fs.BoolVar(&f.requireConsumer, "require-consumer", false, "jwt: refuse tokens whose subject is no consumer")
	fs.BoolVar(&f.gatewayOnly, "gateway-only", false, gatewayOnlyUsage)
}

// input is what the flags ask for; fields the flags leave out stay empty / nil.
func (f *routeAuthFlags) input(cmd *cobra.Command) (runr.RouteAuthInput, error) {
	in := runr.RouteAuthInput{}
	switch f.auth {
	case "", runr.RouteAuthPublic, runr.RouteAuthKey, runr.RouteAuthJWT:
		in.AuthMode = f.auth
	default:
		return in, usageErrorf("--auth must be public, key or jwt")
	}
	if f.jwksURL != "" {
		in.JWT = &runr.RouteJWT{JWKSURL: f.jwksURL, Issuer: f.issuer, Audience: f.audience, SubjectClaim: f.subjectClaim, RequireConsumer: f.requireConsumer}
	} else if f.issuer != "" || f.audience != "" || f.subjectClaim != "" || f.requireConsumer {
		return in, usageErrorf("--issuer, --audience, --subject-claim and --require-consumer need --jwks-url")
	}
	if cmd.Flags().Changed("gateway-only") {
		gatewayOnly := f.gatewayOnly
		in.GatewayOnly = &gatewayOnly
	}
	return in, nil
}

// routeTarget names where a route leads: "shop", "shop (staging)".
func routeTarget(r runr.GatewayRoute) string {
	project := r.ProjectID
	if r.Project != nil {
		project = r.Project.Name
	}
	if r.Environment != "" {
		project += " (" + r.Environment + ")"
	}
	return project
}

func newGatewayRoutesLsCmd(app *App) *cobra.Command {
	return &cobra.Command{
		Use:     "ls GATEWAY",
		Aliases: []string{"list"},
		Short:   "List the routes of a gateway and where they lead",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(g.Routes, func(t *output.TableWriter) {
				t.Header("ROUTE", "PATH", "PROJECT", "METHODS", "AUTH", "URL")
				for _, r := range g.Routes {
					t.Row(r.ID, r.Path, routeTarget(r), joinOrDash(r.Methods), routeAuthLabel(r), output.Dash(r.URL))
				}
				if len(g.Routes) == 0 {
					t.Note("No routes: runr gateway routes add %s /path -p PROJECT", gatewayRegion(*g))
				}
			})
		},
	}
}

func newGatewayRoutesAddCmd(app *App) *cobra.Command {
	var envName string
	var methods []string
	var flags routeAuthFlags
	cmd := &cobra.Command{
		Use:   "add GATEWAY PATH",
		Short: "Route a path of the gateway to the project, or to one of its environments",
		Long: "The route leads to the project's own workload (-p, $RUNR_PROJECT or runr.yaml), or with\n" +
			"--env to one of its environments (docs/environments.md#gateway-routes). The gateway must be\n" +
			"in the region the workload runs in (runr gateway create REGION gives you one there); a\n" +
			"project's own workload and each environment have one route. --method limits the route to\n" +
			"methods (repeatable). --auth key or jwt (with --jwks-url) creates the route with its\n" +
			"authentication: it is never public, not even for a moment; --gateway-only closes the\n" +
			"workload's own URL. runr gateway routes set changes them later.",
		Example: "  runr gateway routes add eu-1 /orders -p orders --auth key\n" +
			"  runr gateway routes add eu-1 /partners -p partners --auth jwt --jwks-url https://id.example.com/jwks --audience partners\n" +
			"  runr gateway routes add eu-1 /orders-staging -p orders --env staging --method GET --method POST",
		Args: cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			for i, m := range methods {
				methods[i] = strings.ToUpper(m)
				switch methods[i] {
				case "GET", "POST", "PUT", "PATCH", "DELETE":
				default:
					return usageErrorf("--method must be GET, POST, PUT, PATCH or DELETE")
				}
			}
			auth, err := flags.input(cmd)
			if err != nil {
				return err
			}
			if auth.AuthMode == runr.RouteAuthJWT && auth.JWT == nil {
				return usageErrorf("--auth jwt needs --jwks-url")
			}
			ctx := cmd.Context()
			c, p, err := app.Project(ctx)
			if err != nil {
				return err
			}
			env, err := environmentScope(ctx, c, p, envName)
			if err != nil {
				return err
			}
			g, err := resolveGateway(ctx, c, args[0])
			if err != nil {
				return err
			}
			path := "/" + strings.TrimLeft(args[1], "/")
			r, err := c.AddGatewayRoute(ctx, g.ID, runr.GatewayRouteInput{ProjectID: p.ID, Environment: env, Path: path, Methods: methods,
				AuthMode: auth.AuthMode, JWT: auth.JWT, GatewayOnly: auth.GatewayOnly})
			if err != nil {
				return err
			}
			app.Progress("Route %s of %s leads to %s.", r.Path, gatewayHost(*g), projectIn(p, r.Environment))
			return app.Printer().Print(r, func(t *output.TableWriter) {
				t.KV("Route", r.ID)
				t.KV("Path", r.Path)
				t.KV("Project", routeTarget(*r))
				t.KV("Methods", joinOrDash(r.Methods))
				t.KV("Auth", routeAuthLabel(*r))
				t.KV("URL", output.Dash(r.URL))
			})
		},
	}
	addEnvFlag(cmd, &envName)
	cmd.Flags().StringArrayVar(&methods, "method", nil, "allowed HTTP method (repeatable; default all)")
	flags.bind(cmd, "public (default), key or jwt", "the workload's own URL stops serving; only the gateway reaches it")
	return cmd
}

func newGatewayRoutesRmCmd(app *App) *cobra.Command {
	var yes bool
	cmd := &cobra.Command{
		Use:     "rm GATEWAY ROUTE",
		Aliases: []string{"remove", "delete"},
		Short:   "Remove a route; the project's own workload goes back to its own URL",
		Long: "ROUTE is the route's id or path. Removing the route of a project's own workload makes\n" +
			"its own URL its endpoint again; an environment's route leaves the environment its own URL.",
		Example: "  runr gateway routes rm eu-1 /orders-staging --yes",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			r, err := resolveRoute(g, args[1])
			if err != nil {
				return err
			}
			if err := app.confirm("Remove route "+r.Path+" ("+routeTarget(*r)+") from "+gatewayHost(*g), yes); err != nil {
				return err
			}
			if err := c.RemoveGatewayRoute(cmd.Context(), g.ID, r.ID); err != nil {
				return err
			}
			app.Progress("Removed route %s of %s.", r.Path, gatewayHost(*g))
			return app.Printer().Print(map[string]any{"removed": true, "route": r.ID, "path": r.Path}, func(*output.TableWriter) {})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask for confirmation")
	return cmd
}

func newGatewayConsumersCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "consumers",
		Aliases: []string{"consumer"},
		Short:   "Consumers of the API: who gets keys and plans",
	}

	var gateway string
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List consumers (--gateway: the gateway's and the organization's)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			gatewayID := ""
			if gateway != "" {
				g, err := resolveGateway(cmd.Context(), c, gateway)
				if err != nil {
					return err
				}
				gatewayID = g.ID
			}
			consumers, err := c.ListAPIConsumers(cmd.Context(), gatewayID)
			if err != nil {
				return err
			}
			return app.Printer().Print(consumers, func(t *output.TableWriter) {
				t.Header("ID", "NAME", "SCOPE", "EXTERNAL ID", "PLAN", "KEYS", "STATUS")
				for _, cs := range consumers {
					plan, keys := "-", "-"
					if cs.Plan != nil {
						plan = cs.Plan.Name
					}
					if cs.Keys != nil {
						keys = fmt.Sprintf("%d/%d", cs.Keys.Active, cs.Keys.Total)
					}
					t.Row(cs.ID, cs.Name, cs.Scope, dashIfEmpty(cs.ExternalID), plan, keys, cs.Status)
				}
			})
		},
	}
	ls.Flags().StringVar(&gateway, "gateway", "", "gateway (id, region or host)")
	cmd.RunE = ls.RunE
	copyFlags(cmd, ls)

	var in runr.APIConsumerInput
	var createGateway string
	create := &cobra.Command{
		Use:   "create NAME",
		Short: "Create a consumer of a gateway (or, without --gateway, of the organization)",
		Example: "  runr gateway consumers create 'Acme Ltd' --gateway eu-1 --external-id CUST-1042 --plan 01JP…\n" +
			"  runr gateway consumers create Partner --jwt-subject partner-42",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			in.Name = args[0]
			if createGateway != "" {
				g, err := resolveGateway(cmd.Context(), c, createGateway)
				if err != nil {
					return err
				}
				in.GatewayID = g.ID
			}
			cs, err := c.CreateAPIConsumer(cmd.Context(), in)
			if err != nil {
				return err
			}
			return app.Printer().Print(cs, func(t *output.TableWriter) {
				t.Note("Consumer %s (%s) created. `runr gateway keys create %s --name …` gives it a key.", cs.Name, cs.ID, cs.ID)
			})
		},
	}
	cf := create.Flags()
	cf.StringVar(&createGateway, "gateway", "", "gateway (id, region or host); none: an organization consumer")
	cf.StringVar(&in.Email, "email", "", "contact e-mail")
	cf.StringVar(&in.ExternalID, "external-id", "", "customer number of your invoicing system")
	cf.StringVar(&in.JWTSubject, "jwt-subject", "", "JWT routes: the claim value that names this consumer")
	cf.StringVar(&in.PlanID, "plan", "", "plan id")

	var name, email, externalID, subject, plan, status string
	update := &cobra.Command{
		Use:   "update CONSUMER",
		Short: "Change a consumer: name, contact, external id, JWT subject, plan, status",
		Example: "  runr gateway consumers update 01JC… --plan 01JP…\n" +
			"  runr gateway consumers update 01JC… --status suspended",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			fields := map[string]any{}
			for flag, key := range map[string]string{"name": "name", "email": "email", "external-id": "external_id",
				"jwt-subject": "jwt_subject", "plan": "plan_id", "status": "status"} {
				if cmd.Flags().Changed(flag) {
					v, _ := cmd.Flags().GetString(flag)
					if v == "" && key != "name" && key != "status" {
						fields[key] = nil
					} else {
						fields[key] = v
					}
				}
			}
			if len(fields) == 0 {
				return usageErrorf("nothing to change")
			}
			if s, ok := fields["status"].(string); ok && s != "active" && s != "suspended" {
				return usageErrorf("--status must be active or suspended")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			cs, err := c.UpdateAPIConsumer(cmd.Context(), args[0], fields)
			if err != nil {
				return err
			}
			return app.Printer().Print(cs, func(t *output.TableWriter) {
				t.Note("Consumer %s updated (%s).", cs.Name, cs.Status)
			})
		},
	}
	uf := update.Flags()
	uf.StringVar(&name, "name", "", "name")
	uf.StringVar(&email, "email", "", "contact e-mail (\"\" clears it)")
	uf.StringVar(&externalID, "external-id", "", "customer number (\"\" clears it)")
	uf.StringVar(&subject, "jwt-subject", "", "JWT subject (\"\" clears it)")
	uf.StringVar(&plan, "plan", "", "plan id (\"\" removes the plan)")
	uf.StringVar(&status, "status", "", "active or suspended (suspended: every key answers 403)")

	var yes bool
	rm := &cobra.Command{
		Use:     "rm CONSUMER",
		Aliases: []string{"delete"},
		Short:   "Delete a consumer and its keys (its usage stays in the reports)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm(fmt.Sprintf("Delete consumer %s and all its keys?", args[0]), yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.DeleteAPIConsumer(cmd.Context(), args[0]); err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"deleted": args[0]}, func(t *output.TableWriter) {
				t.Note("Consumer %s deleted.", args[0])
			})
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask")

	cmd.AddCommand(ls, create, update, rm)
	return cmd
}

// parseScopes turns --route ROUTE[:GET,POST] values into key scopes (route ids or paths of g).
func parseScopes(g *runr.Gateway, values []string) ([]runr.KeyScope, error) {
	var out []runr.KeyScope
	for _, v := range values {
		ref, methods, _ := strings.Cut(v, ":")
		routeID := ref
		if g != nil {
			r, err := resolveRoute(g, ref)
			if err != nil {
				return nil, err
			}
			routeID = r.ID
		}
		scope := runr.KeyScope{RouteID: routeID}
		for _, m := range strings.Split(methods, ",") {
			if m = strings.ToUpper(strings.TrimSpace(m)); m != "" {
				scope.Methods = append(scope.Methods, m)
			}
		}
		out = append(out, scope)
	}
	return out, nil
}

// expiryArg accepts RFC 3339, a date or a duration from now (30d, 12h).
func expiryArg(v string) (string, error) {
	v = strings.TrimSpace(v)
	if v == "" {
		return "", nil
	}
	if t, err := time.Parse(time.RFC3339, v); err == nil {
		return t.UTC().Format(time.RFC3339), nil
	}
	if t, err := time.Parse("2006-01-02", v); err == nil {
		return t.UTC().Format(time.RFC3339), nil
	}
	d, err := parseDays(v)
	if err != nil {
		return "", usageErrorf("--expires %q is neither a time (2027-01-01) nor a duration (90d)", v)
	}
	return time.Now().Add(d).UTC().Truncate(time.Second).Format(time.RFC3339), nil
}

func newGatewayKeysCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "keys",
		Aliases: []string{"key"},
		Short:   "API keys of consumers: create (shown once), rotate, revoke",
	}

	ls := &cobra.Command{
		Use:     "ls CONSUMER",
		Aliases: []string{"list"},
		Short:   "List a consumer's keys (never the keys themselves)",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			keys, err := c.ListAPIKeys(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(keys, func(t *output.TableWriter) {
				t.Header("ID", "NAME", "PREFIX", "ROUTES", "STATUS", "EXPIRES", "LAST USED")
				for _, k := range keys {
					routes := "all"
					if len(k.Scopes) > 0 {
						routes = strconv.Itoa(len(k.Scopes))
					}
					t.Row(k.ID, k.Name, k.Prefix+"…", routes, k.Status, dashIfEmpty(k.ExpiresAt), dashIfEmpty(k.LastUsedAt))
				}
			})
		},
	}

	var name, plan, expires, gateway string
	var routes []string
	create := &cobra.Command{
		Use:   "create CONSUMER",
		Short: "Create a key; it is printed once — hand it to the consumer",
		Long: "--route limits the key to a route (id, or path with --gateway), optionally to methods:\n" +
			"--route /orders:GET,POST. Without --route the key opens every route the consumer may call.",
		Example: "  runr gateway keys create 01JC… --name production\n" +
			"  runr gateway keys create 01JC… --name reporting --gateway eu-1 --route /orders:GET --expires 90d",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if name == "" {
				return usageErrorf("--name is required")
			}
			exp, err := expiryArg(expires)
			if err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			var g *runr.Gateway
			if gateway != "" {
				if g, err = resolveGateway(cmd.Context(), c, gateway); err != nil {
					return err
				}
			}
			scopes, err := parseScopes(g, routes)
			if err != nil {
				return err
			}
			k, err := c.CreateAPIKey(cmd.Context(), args[0], runr.APIKeyInput{Name: name, Scopes: scopes, PlanID: plan, ExpiresAt: exp})
			if err != nil {
				return err
			}
			return app.Printer().Print(k, func(t *output.TableWriter) {
				t.Note("%s", k.Key)
				t.Note("")
				t.Note("Key %s (%s) created. It is shown this once: give it to the consumer now.", k.Name, k.ID)
			})
		},
	}
	cf := create.Flags()
	cf.StringVar(&name, "name", "", "name of the key, e.g. production")
	cf.StringArrayVar(&routes, "route", nil, "only this route: ROUTE[:METHOD,…] (repeatable)")
	cf.StringVar(&gateway, "gateway", "", "gateway the --route paths belong to (id, region or host)")
	cf.StringVar(&plan, "plan", "", "a plan of its own for this key (plan id)")
	cf.StringVar(&expires, "expires", "", "expiry: RFC 3339, a date or a duration (90d)")

	var overlap string
	rotate := &cobra.Command{
		Use:   "rotate KEY",
		Short: "Replace a key; the old one keeps working for --overlap",
		Example: "  runr gateway keys rotate 01JK…\n" +
			"  runr gateway keys rotate 01JK… --overlap 0   # the old key stops at once",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			var minutes *int
			if overlap != "" {
				n := 0
				if overlap != "0" {
					d, err := parseDays(overlap)
					if err != nil {
						return usageErrorf("--overlap %q is not a duration (30m, 24h, 7d or 0)", overlap)
					}
					n = int(d / time.Minute)
				}
				minutes = &n
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			k, err := c.RotateAPIKey(cmd.Context(), args[0], minutes)
			if err != nil {
				return err
			}
			return app.Printer().Print(k, func(t *output.TableWriter) {
				t.Note("%s", k.Key)
				t.Note("")
				t.Note("New key %s replaces %s. It is shown this once: give it to the consumer now.", k.ID, k.RotatedFrom)
			})
		},
	}
	rotate.Flags().StringVar(&overlap, "overlap", "", "how long the old key keeps working (default 24h; 0: revoked now)")

	var yes bool
	revoke := &cobra.Command{
		Use:   "revoke KEY",
		Short: "Revoke a key: the gateway refuses it within seconds (cannot be undone)",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm(fmt.Sprintf("Revoke key %s? It cannot be undone", args[0]), yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			k, err := c.RevokeAPIKey(cmd.Context(), args[0])
			if err != nil {
				return err
			}
			return app.Printer().Print(k, func(t *output.TableWriter) {
				t.Note("Key %s… (%s) revoked.", k.Prefix, k.ID)
			})
		},
	}
	revoke.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask")

	cmd.AddCommand(ls, create, rotate, revoke)
	return cmd
}

// limitFlags are the limits of `plans create` (a flag not given sets no limit).
type limitFlags struct {
	perSecond, burst, perMinute, perDay, perMonth int64
}

func newGatewayPlansCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:     "plans",
		Aliases: []string{"plan"},
		Short:   "Plans: rate limits and quotas of consumers",
	}

	var gateway string
	ls := &cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List plans (--gateway: the gateway's and the organization's)",
		Args:    cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			gatewayID := ""
			if gateway != "" {
				g, err := resolveGateway(cmd.Context(), c, gateway)
				if err != nil {
					return err
				}
				gatewayID = g.ID
			}
			plans, err := c.ListAPIPlans(cmd.Context(), gatewayID)
			if err != nil {
				return err
			}
			return app.Printer().Print(plans, func(t *output.TableWriter) {
				t.Header("ID", "NAME", "SCOPE", "LIMITS")
				for _, p := range plans {
					t.Row(p.ID, p.Name, p.Scope, p.String())
				}
			})
		},
	}
	ls.Flags().StringVar(&gateway, "gateway", "", "gateway (id, region or host)")
	cmd.RunE = ls.RunE
	copyFlags(cmd, ls)

	var lf limitFlags
	var description, createGateway string
	create := &cobra.Command{
		Use:   "create NAME",
		Short: "Create a plan of a gateway (or, without --gateway, of the organization)",
		Example: "  runr gateway plans create Free --gateway eu-1 --per-minute 60 --per-day 1000\n" +
			"  runr gateway plans create Pro --gateway eu-1 --per-second 20 --burst 50 --per-month 5000000",
		Args: cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			in := runr.APIPlanInput{Name: args[0], Description: description}
			set := func(flag string, v int64) *int64 {
				if !cmd.Flags().Changed(flag) {
					return nil
				}
				return &v
			}
			in.RatePerSecond = set("per-second", lf.perSecond)
			in.Burst = set("burst", lf.burst)
			in.RatePerMinute = set("per-minute", lf.perMinute)
			in.QuotaPerDay = set("per-day", lf.perDay)
			in.QuotaPerMonth = set("per-month", lf.perMonth)
			for _, v := range []*int64{in.RatePerSecond, in.Burst, in.RatePerMinute, in.QuotaPerDay, in.QuotaPerMonth} {
				if v != nil && *v < 1 {
					return usageErrorf("limits are at least 1")
				}
			}
			if in.Burst != nil && in.RatePerSecond == nil {
				return usageErrorf("--burst needs --per-second")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if createGateway != "" {
				g, err := resolveGateway(cmd.Context(), c, createGateway)
				if err != nil {
					return err
				}
				in.GatewayID = g.ID
			}
			p, err := c.CreateAPIPlan(cmd.Context(), in)
			if err != nil {
				return err
			}
			return app.Printer().Print(p, func(t *output.TableWriter) {
				t.Note("Plan %s (%s): %s.", p.Name, p.ID, p.String())
			})
		},
	}
	f := create.Flags()
	f.StringVar(&createGateway, "gateway", "", "gateway (id, region or host); none: an organization plan")
	f.StringVar(&description, "description", "", "what the plan offers")
	f.Int64Var(&lf.perSecond, "per-second", 0, "requests per second")
	f.Int64Var(&lf.burst, "burst", 0, "requests at once above the per-second rate (default: the rate)")
	f.Int64Var(&lf.perMinute, "per-minute", 0, "requests per minute (sliding window)")
	f.Int64Var(&lf.perDay, "per-day", 0, "requests per day (UTC)")
	f.Int64Var(&lf.perMonth, "per-month", 0, "requests per calendar month (UTC)")

	var yes bool
	rm := &cobra.Command{
		Use:     "rm PLAN",
		Aliases: []string{"delete"},
		Short:   "Delete a plan no consumer or key uses",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm(fmt.Sprintf("Delete plan %s?", args[0]), yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if err := c.DeleteAPIPlan(cmd.Context(), args[0]); err != nil {
				return err
			}
			return app.Printer().Print(map[string]any{"deleted": args[0]}, func(t *output.TableWriter) {
				t.Note("Plan %s deleted.", args[0])
			})
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask")

	cmd.AddCommand(ls, create, rm)
	return cmd
}

func newGatewayUsageCmd(app *App) *cobra.Command {
	var gateway, consumer, key, route, since, until, by string
	cmd := &cobra.Command{
		Use:   "usage",
		Short: "Calls let through and refused, answers and latency, per hour / day / month or per consumer / key / route",
		Long: "Usage of your gateways' API (UTC; the current hour grows until it ends). REFUSED counts\n" +
			"401, 403 and 429 answers of the gateway. ERRORS counts 5xx answers of the function and calls\n" +
			"that got no answer; P50 / P95 / P99 are the latencies (ms) until the function's answer\n" +
			"started. -o json gives the API's answer for your invoicing, with the status classes and bytes.",
		Example: "  runr gateway usage --gateway eu-1 --by consumer --since 30d\n" +
			"  runr gateway usage --consumer 01JC… --by day --since 2026-10-01 --until 2026-11-01",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			f := runr.APIUsageFilter{ConsumerID: consumer, KeyID: key, RouteID: route, GroupBy: by}
			var err error
			if f.From, err = auditTime("--since", since); err != nil {
				return err
			}
			if f.To, err = auditTime("--until", until); err != nil {
				return err
			}
			switch by {
			case "hour", "day", "month", "consumer", "key", "route":
			default:
				return usageErrorf("--by must be hour, day, month, consumer, key or route")
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			if gateway != "" {
				g, err := resolveGateway(cmd.Context(), c, gateway)
				if err != nil {
					return err
				}
				f.GatewayID = g.ID
				if route != "" {
					if r, err := resolveRoute(g, route); err == nil {
						f.RouteID = r.ID
					}
				}
			}
			u, err := c.APIUsage(cmd.Context(), f)
			if err != nil {
				return err
			}
			return app.Printer().Print(u, func(t *output.TableWriter) {
				t.Header(strings.ToUpper(u.GroupBy), "REQUESTS", "REFUSED", "RATE LIMITED", "OVER QUOTA", "ERRORS", "P50", "P95", "P99")
				for _, r := range u.Data {
					t.Row(usageLabel(u.GroupBy, r), strconv.FormatInt(r.Requests, 10), strconv.FormatInt(r.Refused(), 10),
						strconv.FormatInt(r.RateLimited, 10), strconv.FormatInt(r.QuotaExceeded, 10),
						strconv.FormatInt(r.Errors, 10), millis(r.LatencyP50MS), millis(r.LatencyP95MS), millis(r.LatencyP99MS))
				}
				t.Note("%s to %s: %d let through, %d refused.", u.From, u.To, u.Totals.Requests, u.Totals.Refused())
				if u.Totals.Responses > 0 {
					t.Note("The functions answered %d: %d error(s), p50 %s ms, p95 %s ms, p99 %s ms.", u.Totals.Responses, u.Totals.Errors,
						millis(u.Totals.LatencyP50MS), millis(u.Totals.LatencyP95MS), millis(u.Totals.LatencyP99MS))
				}
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&gateway, "gateway", "", "gateway (id, region or host)")
	f.StringVar(&consumer, "consumer", "", "consumer id")
	f.StringVar(&key, "key", "", "key id")
	f.StringVar(&route, "route", "", "route id (or path with --gateway)")
	f.StringVar(&since, "since", "", "from: RFC 3339, a date or a duration back (30d; default 7d)")
	f.StringVar(&until, "until", "", "to: RFC 3339, a date or a duration back")
	f.StringVar(&by, "by", "day", "hour, day, month, consumer, key or route")
	return cmd
}

// millis prints a latency in milliseconds, "-" without a measured answer.
func millis(v *float64) string {
	if v == nil {
		return "-"
	}
	return strconv.FormatFloat(*v, 'f', -1, 64)
}

func usageLabel(groupBy string, r runr.UsageRow) string {
	switch groupBy {
	case "consumer":
		if r.Consumer != nil {
			if r.Consumer.ExternalID != "" {
				return r.Consumer.Name + " (" + r.Consumer.ExternalID + ")"
			}
			return r.Consumer.Name
		}
		if r.Subject != "" {
			return "subject " + r.Subject
		}
		if r.ConsumerID != "" {
			return r.ConsumerID + " (deleted)"
		}
		return "(no consumer)"
	case "key":
		if r.Key != nil {
			return r.Key.Name + " " + r.Key.Prefix + "…"
		}
		return dashIfEmpty(r.KeyID)
	case "route":
		return dashIfEmpty(r.Path)
	}
	return r.Period
}

func dashIfEmpty(s string) string {
	if s == "" {
		return "-"
	}
	return s
}

func newGatewayDomainCmd(app *App) *cobra.Command {
	cmd := &cobra.Command{
		Use:   "domain",
		Short: "The gateway's own domain (TXT verified before it is served)",
	}
	set := &cobra.Command{
		Use:     "set GATEWAY DOMAIN",
		Short:   "Request a domain; prints the DNS records to create",
		Example: "  runr gateway domain set eu-1 api.example.com",
		Args:    cobra.ExactArgs(2),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			updated, err := c.SetGatewayDomain(cmd.Context(), g.ID, args[1])
			if err != nil {
				return err
			}
			return app.Printer().Print(updated, func(t *output.TableWriter) { pendingDomainNote(t, updated) })
		},
	}
	verify := &cobra.Command{
		Use:   "verify GATEWAY",
		Short: "Check the DNS records; the domain is served once they are found",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			updated, err := c.VerifyGatewayDomain(cmd.Context(), g.ID)
			var de *runr.GatewayDomainError
			if errors.As(err, &de) {
				_ = app.Printer().Print(de.Gateway, func(t *output.TableWriter) { pendingDomainNote(t, de.Gateway) })
				return err
			}
			if err != nil {
				return err
			}
			return app.Printer().Print(updated, func(t *output.TableWriter) {
				t.Note("%s is verified and serves the gateway.", updated.CustomDomain)
			})
		},
	}
	var yes bool
	rm := &cobra.Command{
		Use:   "rm GATEWAY",
		Short: "Stop serving the gateway's domain",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if err := app.confirm("Stop serving the gateway's custom domain?", yes); err != nil {
				return err
			}
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			updated, err := c.RemoveGatewayDomain(cmd.Context(), g.ID)
			if err != nil {
				return err
			}
			return app.Printer().Print(updated, func(t *output.TableWriter) { t.Note("Custom domain removed.") })
		},
	}
	rm.Flags().BoolVarP(&yes, "yes", "y", false, "do not ask")
	cmd.AddCommand(set, verify, rm)
	return cmd
}

func pendingDomainNote(t *output.TableWriter, g *runr.Gateway) {
	p := g.CustomDomainPending
	if p == nil {
		t.Note("No domain is waiting for verification.")
		return
	}
	t.Header("TYPE", "NAME", "VALUE")
	t.Row(p.Verification.Type, p.Verification.Name, p.Verification.Value)
	for _, d := range p.DNSTargets {
		t.Row(d.Type, d.Name, d.Value)
	}
	t.Note("Create the TXT record and one of the other records, then: runr gateway domain verify %s", g.ID)
}

func newGatewayPageCmd(app *App) *cobra.Command {
	var description string
	var off, metrics bool
	cmd := &cobra.Command{
		Use:   "page GATEWAY",
		Short: "Publish (or with --off withdraw) the gateway's public developer page",
		Long: "Publish (or with --off withdraw) the gateway's public developer page. --metrics shows each\n" +
			"key / JWT route's success rate and latency (p50, p95, p99) of the last 7 days on it —\n" +
			"never call counts or consumers; --metrics=false hides them again.",
		Example: "  runr gateway page eu-1 --description 'Orders API' --metrics",
		Args:    cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			c, err := app.Client()
			if err != nil {
				return err
			}
			g, err := resolveGateway(cmd.Context(), c, args[0])
			if err != nil {
				return err
			}
			var desc *string
			if cmd.Flags().Changed("description") {
				desc = &description
			}
			var showMetrics *bool
			if cmd.Flags().Changed("metrics") {
				showMetrics = &metrics
			}
			updated, err := c.UpdateGatewayPage(cmd.Context(), g.ID, !off, desc, showMetrics)
			if err != nil {
				return err
			}
			return app.Printer().Print(updated, func(t *output.TableWriter) {
				if !updated.DeveloperPage {
					t.Note("Developer page withdrawn.")
					return
				}
				t.Note("Developer page: %s/developers/%s", strings.TrimRight(c.ConsoleURL(), "/"), updated.DeveloperPageKey)
				if updated.DeveloperPageMetrics {
					t.Note("It shows the routes' success rate and latency of the last 7 days.")
				}
			})
		},
	}
	cmd.Flags().StringVar(&description, "description", "", "what the API does (shown on the page)")
	cmd.Flags().BoolVar(&off, "off", false, "withdraw the page")
	cmd.Flags().BoolVar(&metrics, "metrics", false, "show the routes' success rate and latency on the page (--metrics=false: hide them)")
	return cmd
}
