<?php

use App\Providers\AiServiceProvider;
use App\Providers\ApiProductServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BindingServiceProvider;
use App\Providers\EnvironmentServiceProvider;
use App\Providers\GitDeployServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ImageServiceProvider;
use App\Providers\McpServiceProvider;
use App\Providers\ObservabilityServiceProvider;
use App\Providers\OrgTransferServiceProvider;
use App\Providers\RbacServiceProvider;
use App\Providers\RuntimeServiceProvider;
use App\Providers\SandboxServiceProvider;
use App\Providers\SecurityServiceProvider;
use App\Providers\SupplyChainServiceProvider;
use App\Providers\TriggerServiceProvider;
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
    EnvironmentServiceProvider::class,
    ImageServiceProvider::class,
    OrgTransferServiceProvider::class,
    McpServiceProvider::class,
    TriggerServiceProvider::class,
    ApiProductServiceProvider::class,
];
