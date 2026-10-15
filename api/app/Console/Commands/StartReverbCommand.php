<?php

namespace App\Console\Commands;

use App\Services\Shell\ShellAwareFactory;
use Laravel\Reverb\Contracts\Logger;
use Laravel\Reverb\Loggers\CliLogger;
use Laravel\Reverb\Servers\Reverb\Console\Commands\StartServer;
use React\EventLoop\Loop;

class StartReverbCommand extends StartServer
{
    protected $signature = 'reverb:start-shell
                {--host= : The IP address the server should bind to}
                {--port= : The port the server should listen on}
                {--path= : The path the server should prefix to all routes}
                {--hostname= : The hostname the server is accessible from}
                {--debug : Indicates whether debug messages should be displayed in the terminal}';

    protected $description = 'Start Reverb server with shell proxy support';

    public function handle(): void
    {
        if ($this->option('debug')) {
            $this->laravel->instance(Logger::class, new CliLogger($this->output));
        }

        $config = $this->laravel['config']['reverb.servers.reverb'];

        $loop = Loop::get();

        // Use ShellAwareFactory instead of Factory
        $server = ShellAwareFactory::make(
            $host = $this->option('host') ?: $config['host'],
            $port = $this->option('port') ?: $config['port'],
            $path = $this->option('path') ?: $config['path'] ?? '',
            $hostname = $this->option('hostname') ?: $config['hostname'],
            $config['max_request_size'] ?? 10_000,
            $config['options'] ?? [],
            loop: $loop
        );

        $this->ensureHorizontalScalability($loop);
        $this->ensureStaleConnectionsAreCleaned($loop);
        $this->ensureRestartCommandIsRespected($server, $loop, $host, $port);
        $this->ensurePulseEventsAreCollected($loop, $config['pulse_ingest_interval']);
        $this->ensureTelescopeEntriesAreCollected($loop, $config['telescope_ingest_interval'] ?? 15);

        $this->components->info("Starting server on {$host}:{$port}{$path} (with shell proxy)");

        $server->start();
    }
}
