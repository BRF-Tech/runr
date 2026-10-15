<?php

// WebAssembly support (App\Services\Wasm, WasmController) — loaded as the "wasm" namespace by
// WasmServiceProvider: __('wasm::messages.<key>')
return [
    'runtime_not_in_region' => 'The :name runtime needs WebAssembly support with the :shim shim in region :region. Install it under Settings → Regions → Sandbox → WebAssembly.',
    'serving_required' => 'Install Knative Serving on this region first: WebAssembly functions run as Knative Services.',
    'cluster_unreachable' => 'Cannot read the cluster\'s nodes: :error',
    'no_eligible_nodes' => 'No node of this region can get WebAssembly support: it needs Linux nodes (amd64 / arm64) with containerd (plain, k3s or RKE2) that projects can run on.',
    'node_unknown' => 'Node :node is not part of this cluster.',
    'node_not_eligible' => 'Node :node cannot get WebAssembly support (:reason).',
    'install_queued' => 'WebAssembly support installation queued.',
    'uninstall_queued' => 'WebAssembly support removal queued.',
    'not_installed' => 'WebAssembly support is not installed on this region.',
    'reasons' => [
        'not_linux' => 'not a Linux node',
        'not_dedicated' => 'projects run on the dedicated workers only',
        'tainted' => 'tainted or cordoned: projects cannot run there',
        'runtime' => 'the container runtime is not containerd',
        'arch' => 'the shims are published for amd64 and arm64',
    ],
];
