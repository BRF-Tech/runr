<?php

// Regions: what region:reconcile left (App\Services\RegionReconcile\ReconcileRecord, docs/upgrade.md)
return [
    'reconcile' => [
        'unfinished_title' => 'Regions are not at RunR :version yet',
        'unfinished_body' => 'region:reconcile did not finish (run :run, attempt :attempt): :regions region(s) were left behind. The region status (Platform → Knative Regions → the region → General) names the steps and the log of the Job runr-region-reconcile-* of the upgrade has the details; fix what they name and run php artisan region:reconcile.',
        'unreachable_title' => 'Some region clusters did not answer',
        'unreachable_body' => 'region:reconcile could not reach :regions region(s) (run :run). They keep what they had; run php artisan region:reconcile once their cluster answers.',
    ],

    // The kubeconfig of an organization's own region (App\Services\Kubeconfigs\KubeconfigPolicy,
    // docs/security.md#tenant-region-kubeconfigs)
    'kubeconfig' => [
        'invalid' => 'The kubeconfig is not valid YAML or JSON.',
        'too_large' => 'The kubeconfig is larger than :kb KB.',
        'not_a_config' => 'The kubeconfig is not a kubectl configuration (apiVersion v1, kind Config).',
        'exec' => ':field runs a program on RunR\'s servers (a credential plugin). An organization\'s region connects with a ServiceAccount token or a client certificate in the kubeconfig.',
        'file' => ':field reads a file on RunR\'s servers. Put the data itself into the kubeconfig (the *-data fields): kubectl config view --minify --flatten writes them.',
        'proxy' => ':field sends RunR\'s connection through a proxy. An organization\'s region is reached directly.',
        'impersonation' => ':field (impersonation) is not accepted. Use the credentials of the account itself.',
        'field' => ':field is not accepted in the kubeconfig of an organization\'s region. A cluster has server, certificate-authority-data, tls-server-name and insecure-skip-tls-verify; a user token, client-certificate-data and client-key-data, or username and password; a context cluster, user and namespace.',
        'unused' => 'The kubeconfig may hold only its current context with its cluster and user; :entry is not used. kubectl config view --minify --flatten writes such a kubeconfig.',
        'duplicate' => ':entry appears more than once.',
        'no_context' => 'current-context does not name a context of the kubeconfig.',
        'missing' => 'The kubeconfig has no :entry for its current context.',
        'value' => ':field has an invalid value.',
        'key_pair' => 'client-certificate-data and client-key-data go together.',
        'server' => 'The cluster server must be the https:// address of the API server (host, port and path only).',
        'server_blocked' => 'Cluster server :host: :reason An organization\'s region uses a public address of its API server.',
        'none' => 'The region has no kubeconfig RunR uses.',
        'unusable' => 'RunR does not use the kubeconfig of this region: :reason Replace the kubeconfig (Platform → Knative Regions → the region → General).',
        // Connection errors an organization's users see (platform administrators see the details)
        'unreachable' => 'The cluster\'s API server could not be reached (no connection or no TLS handshake).',
        'rejected' => 'The cluster\'s API server refused the credentials of the kubeconfig (HTTP :status).',
        'unexpected' => 'The cluster\'s API server did not answer as a Kubernetes API server (HTTP :status).',
        'failed' => 'The request to the cluster failed.',
    ],
];
