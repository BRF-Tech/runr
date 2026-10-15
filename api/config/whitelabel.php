<?php

/**
 * White-label: branding, self-service signup (docs/white-label.md).
 *
 * Every value here is the install default (env / Helm values). Platform admins override them at
 * runtime (Settings → Branding / Signup); the stored value wins over the default. Plans and
 * billing live in the Tenancy module (config tenancy.billing / tenancy.plans, docs/billing.md).
 */
return [

    /*
    | The default brand is RunR by BRF Teknoloji. Helm values branding.* set these env vars (a logo
    | may come from a ConfigMap / Secret key); empty = the RunR default.
    | Logos / favicon: https URL, path on this host, data:image/…;base64,… URI or raw <svg> markup.
    */
    'branding' => [
        'product_name' => env('BRANDING_PRODUCT_NAME', 'RunR'),
        'company_name' => env('BRANDING_COMPANY_NAME', 'BRF Teknoloji'),
        'logo_light' => env('BRANDING_LOGO_LIGHT', ''),
        'logo_dark' => env('BRANDING_LOGO_DARK', ''),
        'logo_mark' => env('BRANDING_LOGO_MARK', ''),
        'favicon' => env('BRANDING_FAVICON', ''),
        // Base colour (shade 500) of the primary scale; the scale is expanded from it unless
        // BRANDING_PRIMARY_SCALE gives the shades: {"300":"#…","400":"…",…,"900":"…"} (hex or "r g b")
        'primary_color' => env('BRANDING_PRIMARY_COLOR', '#f97316'),
        'primary_scale' => env('BRANDING_PRIMARY_SCALE', ''),
        'accent_color' => env('BRANDING_ACCENT_COLOR', '#e63946'),
        // E-mail template: sender name (default: product name) and logo (default: light logo)
        'email_from_name' => env('BRANDING_EMAIL_FROM_NAME', ''),
        'email_logo' => env('BRANDING_EMAIL_LOGO', ''),
        'login_headline' => env('BRANDING_LOGIN_HEADLINE', ''),
        'login_text' => env('BRANDING_LOGIN_TEXT', ''),
        'support_email' => env('BRANDING_SUPPORT_EMAIL', ''),
        'support_url' => env('BRANDING_SUPPORT_URL', ''),
        // JSON list: [{"label":"Status","url":"https://status.example.com"}]
        'footer_links' => env('BRANDING_FOOTER_LINKS', '[]'),
        'powered_by' => env('BRANDING_POWERED_BY', true),

        // Multi-tenant: tenant owners/admins may override the branding their users see
        'tenant_overrides' => env('BRANDING_TENANT_OVERRIDES', true),

        // Uploaded logos / favicon (public disk, served under /storage)
        'asset_disk' => env('BRANDING_ASSET_DISK', 'public'),
        'asset_max_kb' => (int) env('BRANDING_ASSET_MAX_KB', 1024),
    ],

    'signup' => [
        // registration_enabled itself is the existing setting (default on)
        'email_verification' => env('SIGNUP_EMAIL_VERIFICATION', false),
        'verification_ttl_hours' => (int) (env('SIGNUP_VERIFICATION_TTL_HOURS') ?: 48),
        // Comma-separated e-mail domains allowed to sign up; empty = any domain
        'allowed_domains' => env('SIGNUP_ALLOWED_DOMAINS', ''),
        // Only invited users join (tenant invitations / admin-created accounts)
        'invite_only' => env('SIGNUP_INVITE_ONLY', false),

        // none | turnstile | hcaptcha
        'captcha_provider' => env('SIGNUP_CAPTCHA_PROVIDER', 'none'),
        'captcha_site_key' => env('SIGNUP_CAPTCHA_SITE_KEY', ''),
        'captcha_secret' => env('SIGNUP_CAPTCHA_SECRET', ''),
        'captcha_timeout' => (int) env('SIGNUP_CAPTCHA_TIMEOUT', 10),

        'terms_url' => env('SIGNUP_TERMS_URL', ''),
        'privacy_url' => env('SIGNUP_PRIVACY_URL', ''),

        // Multi-tenant: every self-service signup gets its own tenant (organization)
        'tenant_auto_create' => env('SIGNUP_TENANT_AUTO_CREATE', true),
        // Quota per region the onboarding wizard assigns (plans off); 0 is never used (0 = unlimited)
        'default_cpu_quota_m' => (int) (env('SIGNUP_DEFAULT_CPU_QUOTA_M') ?: 2000),
        'default_memory_quota_mb' => (int) (env('SIGNUP_DEFAULT_MEMORY_QUOTA_MB') ?: 4096),
        // Comma-separated platform region ids offered in the onboarding wizard; empty = all active
        'regions' => env('SIGNUP_REGIONS', ''),
    ],
];
