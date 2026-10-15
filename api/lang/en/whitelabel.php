<?php

/*
| White-label: branding, self-service signup, plans (docs/white-label.md, docs/billing.md)
*/
return [
    'tenant_required' => 'This action needs an active tenant.',

    'signup' => [
        'registration_disabled' => 'Registration is disabled.',
        'invite_only' => 'Accounts are created by invitation only. Ask your administrator for an invitation.',
        'domain_not_allowed' => 'Sign-up is not open for this e-mail domain.',
        'captcha_failed' => 'The CAPTCHA check failed. Please try again.',
        'verification_sent' => 'Check your inbox: we sent you a link to confirm your e-mail address.',
        'verification_resent' => 'If a sign-up is waiting for this address, a new confirmation link is on its way.',
        'verification_invalid' => 'This confirmation link is not valid. Request a new one.',
        'verification_expired' => 'This confirmation link has expired. Request a new one.',
        'email_taken' => 'An account with this e-mail address already exists.',
        'username_taken' => 'This username is already taken. Please sign up again with another one.',
    ],

    'branding' => [
        'unknown_asset' => 'Unknown branding asset.',
        'invalid_url' => 'Use an http(s) URL or a path on this host.',
        'unsafe_svg' => 'The SVG contains scripts or embedded content and cannot be used.',
        'tenant_overrides_disabled' => 'Tenant branding is switched off by the platform administrator.',
    ],

    'mail' => [
        'verify_subject' => 'Confirm your e-mail address for :product',
        'verify_heading' => 'Confirm your e-mail address',
        'verify_greeting' => 'Hello :name,',
        'verify_intro' => 'thanks for signing up for :product. Confirm your e-mail address to activate your account.',
        'verify_button' => 'Confirm e-mail address',
        'verify_expiry' => 'The link is valid for :hours hours.',
        'verify_ignore' => 'If you did not sign up, you can ignore this e-mail.',
        'support' => 'Support:',
        'rights' => 'All rights reserved.',
        'powered_by' => 'Powered by RunR',
    ],

    'onboarding' => [
        'default_tenant_name' => ":name's workspace",
        'region_not_offered' => 'This region is not available for new organizations.',
        'not_allowed' => 'Only the owner or an admin of the organization can complete the setup.',
    ],

    'plans' => [
        'disabled' => 'Plans are not enabled on this platform.',
        'not_available' => 'This plan is not available.',
        'interval_not_available' => 'This plan has no price for the selected billing interval.',
        'unknown_limit' => 'Unknown plan limit: :keys',
        'unknown_feature' => 'Unknown plan feature: :keys',

        'limit_max_projects' => 'Your plan allows :max projects (:used in use). Upgrade your plan to create more.',
        'limit_gpu_quota' => 'Your plan allows :max GPUs (:used in use). Upgrade your plan for more.',
        'limit_invocations_per_month' => 'Your plan\'s :max invocations for this month are used up (:used). New deployments are possible again next month or after an upgrade.',
        'limit_storage_quota_mb' => 'Your plan\'s storage of :max MB is full (:used MB). Upgrade your plan or remove old projects.',
        'feature_not_included' => ':feature is not included in your plan.',

        'feature_custom_domains' => 'Custom domains',
        'feature_private_connections' => 'Private database connections',
        'feature_gpu' => 'GPU workloads',
        'feature_api_tokens' => 'API tokens',
        'feature_sso' => 'Single sign-on',
        'feature_metered_usage' => 'Metered usage billing',
        'feature_priority_support' => 'Priority support',
    ],
];
