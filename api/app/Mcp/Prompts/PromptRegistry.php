<?php

namespace App\Mcp\Prompts;

use App\Mcp\Protocol\McpError;

/**
 * Prompts of the RunR MCP server (docs/mcp.md, "Prompts"): task recipes that tell the model which
 * tools to use in which order.
 */
final class PromptRegistry
{
    /** prompts/list */
    public function list(): array
    {
        return array_map(fn (array $p) => array_diff_key($p, ['build' => true]), array_values($this->prompts()));
    }

    /**
     * prompts/get
     *
     * @throws McpError
     */
    public function get(string $name, array $arguments): array
    {
        $prompt = $this->prompts()[$name] ?? null;
        if (! $prompt) {
            throw McpError::invalidParams("Unknown prompt: {$name}");
        }

        $values = [];
        foreach ($prompt['arguments'] as $argument) {
            $value = $arguments[$argument['name']] ?? null;
            if (($argument['required'] ?? false) && (! is_string($value) || trim($value) === '')) {
                throw McpError::invalidParams("Required argument not given: {$argument['name']}");
            }
            $values[$argument['name']] = is_string($value) ? trim(mb_substr($value, 0, 4000)) : null;
        }

        return [
            'description' => $prompt['description'],
            'messages' => [[
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => ($prompt['build'])($values)],
            ]],
        ];
    }

    /** @return list<string> prompt argument names (completion) */
    public function argumentNames(string $prompt): array
    {
        return array_column($this->prompts()[$prompt]['arguments'] ?? [], 'name');
    }

    private function prompts(): array
    {
        return [
            'deploy_function' => [
                'name' => 'deploy_function',
                'title' => 'Deploy a function',
                'description' => 'Write a function for a description, deploy it on RunR and check that it answers.',
                'arguments' => [
                    ['name' => 'description', 'description' => 'What the function does.', 'required' => true],
                    ['name' => 'runtime', 'description' => 'Runtime id (python, nodejs, go, php, …); default: pick one that fits.', 'required' => false],
                    ['name' => 'project', 'description' => 'An existing project to deploy into (id, slug or name); default: a new project.', 'required' => false],
                ],
                'build' => fn (array $v) => implode("\n", array_filter([
                    'Build and deploy this function on RunR: '.$v['description'],
                    '',
                    'Steps:',
                    '1. Call whoami to see which permissions this connection has.',
                    $v['project']
                        ? "2. Use the project \"{$v['project']}\" (project_get shows its type, runtime and trigger)."
                        : '2. Call runtimes_list and regions_list, then project_create (a faas project for several files, xaas for one file) with the http trigger'.($v['runtime'] ? " on the runtime {$v['runtime']}" : '').'.',
                    '3. Call runtime_get for the runtime\'s handler contract and starter template, then write the code with code_write_files (include the dependency file the runtime names, e.g. requirements.txt or package.json).',
                    '4. Call deploy and wait for it. When it fails, read version_get (build errors) and logs_tail, fix the code and deploy again.',
                    '5. Report the URL the deploy returned and how to call it.',
                ], fn ($line) => $line !== null)),
            ],
            'debug_deployment' => [
                'name' => 'debug_deployment',
                'title' => 'Debug a failing deployment',
                'description' => 'Find out why a deployment of a project fails or misbehaves and propose (or apply) a fix.',
                'arguments' => [
                    ['name' => 'project', 'description' => 'The project (id, slug or name).', 'required' => true],
                    ['name' => 'deployment', 'description' => 'A deployment id; default: the current deployment.', 'required' => false],
                ],
                'build' => fn (array $v) => implode("\n", [
                    "Debug the deployment of the RunR project \"{$v['project']}\"".($v['deployment'] ? " (deployment {$v['deployment']})" : '').'.',
                    '',
                    'Steps:',
                    '1. deployment_status for its state and the Knative message.',
                    '2. version_get for the deployed version: a failed build shows build_error.',
                    '3. logs_tail with errors_only, then without it around the first error.',
                    '4. metrics_summary for restarts, memory or CPU pressure; project_get for the configured size, timeout and scaling.',
                    '5. When the deploy was refused by the supply-chain policy gate, security_report shows the findings.',
                    '6. Explain the cause. If the fix is in the code or the configuration and you may change it, apply it (code_write_files / env_set / project_update), deploy again and confirm with deployment_status and logs_tail.',
                ]),
            ],
            'create_workflow' => [
                'name' => 'create_workflow',
                'title' => 'Create a durable workflow',
                'description' => 'Create a durable workflow (steps with retries, sleeps, events) as a RunR project, deploy it and start a run.',
                'arguments' => [
                    ['name' => 'description', 'description' => 'What the workflow does, its trigger (event name, cron) and its steps.', 'required' => true],
                    ['name' => 'runtime', 'description' => 'python or nodejs (default python).', 'required' => false],
                    ['name' => 'project', 'description' => 'An existing workflow project (id, slug or name); default: a new one.', 'required' => false],
                ],
                'build' => fn (array $v) => implode("\n", [
                    'Create this durable workflow on RunR: '.$v['description'],
                    '',
                    'Read the resources runr://docs/workflows (concepts, SDK, triggers) and runr://docs/workflows-protocol first.',
                    'Steps:',
                    $v['project']
                        ? "1. Use the project \"{$v['project']}\"."
                        : '1. project_create: a faas project on '.($v['runtime'] ?: 'python').' with the trigger "workflow".',
                    '2. code_write_files with the workflow code on the RunR workflows SDK ('.(($v['runtime'] ?? '') === 'nodejs' ? '@runr/workflows in package.json' : 'runr-workflows in requirements.txt').'): side effects inside step.run, deterministic code between steps, stable step ids.',
                    '3. deploy, then workflows_list to see the synced definitions.',
                    '4. Start a run with workflow_run_start (or event_send for an event trigger) and follow it with workflow_run_status until it completes; on a failure read the failed step\'s error and fix the code.',
                ]),
            ],
        ];
    }
}
