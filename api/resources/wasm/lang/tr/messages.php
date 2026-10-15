<?php

// WebAssembly desteği (App\Services\Wasm, WasmController) — WasmServiceProvider "wasm" namespace'i
// olarak yükler: __('wasm::messages.<anahtar>')
return [
    'runtime_not_in_region' => ':name runtime\'ı :region bölgesinde :shim shim\'li WebAssembly desteği ister. Ayarlar → Bölgeler → Sandbox → WebAssembly altından kurun.',
    'serving_required' => 'Önce bu bölgeye Knative Serving kurun: WebAssembly fonksiyonları Knative Service olarak çalışır.',
    'cluster_unreachable' => 'Cluster\'ın node\'ları okunamadı: :error',
    'no_eligible_nodes' => 'Bu bölgede WebAssembly desteği alabilecek node yok: projelerin çalışabildiği, containerd (düz, k3s veya RKE2) kullanan Linux node\'ları (amd64 / arm64) gerekir.',
    'node_unknown' => ':node node\'u bu cluster\'da yok.',
    'node_not_eligible' => ':node node\'u WebAssembly desteği alamaz (:reason).',
    'install_queued' => 'WebAssembly desteği kurulumu sıraya alındı.',
    'uninstall_queued' => 'WebAssembly desteğinin kaldırılması sıraya alındı.',
    'not_installed' => 'Bu bölgede WebAssembly desteği kurulu değil.',
    'reasons' => [
        'not_linux' => 'Linux node\'u değil',
        'not_dedicated' => 'projeler yalnız ayrılmış worker\'larda çalışır',
        'tainted' => 'taint\'li veya cordon\'lu: projeler orada çalışamaz',
        'runtime' => 'container runtime containerd değil',
        'arch' => 'shim\'ler amd64 ve arm64 için yayınlanır',
    ],
];
