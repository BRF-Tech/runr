<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BindingServiceProvider;
use App\Providers\GitDeployServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ImageServiceProvider;
use App\Providers\McpServiceProvider;
use App\Providers\ObservabilityServiceProvider;
use App\Providers\RbacServiceProvider;
use App\Providers\RuntimeServiceProvider;
use App\Providers\SandboxServiceProvider;
use App\Providers\SecurityServiceProvider;
use App\Providers\SupplyChainServiceProvider;
use App\Providers\WasmServiceProvider;
use App\Providers\WhiteLabelServiceProvider;
use App\Providers\WorkflowServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    SecurityServiceProvider::class,
    RuntimeServiceProvider::class,
    WasmServiceProvider::class,
    SandboxServiceProvider::class,
    RbacServiceProvider::class,
    ObservabilityServiceProvider::class,
    WorkflowServiceProvider::class,
    WhiteLabelServiceProvider::class,
    AiServiceProvider::class,
    BindingServiceProvider::class,
    SupplyChainServiceProvider::class,
    GitDeployServiceProvider::class,
    ImageServiceProvider::class,
    McpServiceProvider::class,
];
