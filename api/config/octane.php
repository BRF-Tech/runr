<?php

// The imports come first: an import only applies to the code after it, so a short name above
// them would resolve to the global namespace — `class_exists(Octane::class)` was then always
// false and every Octane worker ran with the stub below, without the per-request listeners
// (stale request(), auth guards and tenancy state across requests: every Bearer token 401).
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

// Octane config is only loaded when the package is installed.
// In dev/migrator containers without Octane, return a minimal stub.
if (! class_exists(Octane::class)) {
    return [
        'server' => env('OCTANE_SERVER', 'frankenphp'),
        'https' => env('OCTANE_HTTPS', false),
        'listeners' => [],
        'warm' => [],
        'flush' => [],
        'tables' => [],
        'cache' => ['rows' => 1000, 'bytes' => 10000],
        'watch' => [],
        'garbage' => 50,
        'max_execution_time' => 30,
    ];
}

return [

    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'state_file' => storage_path('logs/octane-server-state.json'),

    'https' => env('OCTANE_HTTPS', false),

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
            //
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            // FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // DisconnectFromDatabases::class,
            // CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    'flush' => [
        //
    ],

    'tables' => [
        'example:1000' => [
            'name' => 'string:1000',
            'votes' => 'int',
        ],
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

    'garbage' => 50,

    'max_execution_time' => 30,

];
