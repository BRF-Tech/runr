<?php

namespace Database\Seeders\Concerns;

use App\Services\ProjectService;

trait DemoVersionsTrait
{
    private function seedXaaSVersions(ProjectService $svc, $project, string $userId): void
    {
        $svc->saveXaaSCode($project, <<<'PY'
def handler(event, context):
    return {"statusCode": 200, "body": "Hello, World!!"}
PY, $userId, 'Add extra exclamation mark');

        $svc->saveXaaSCode($project, <<<'PY'
def handler(event, context):
    message = "Hello, World!!"
    return {"statusCode": 200, "body": message}
PY, $userId, 'Extract message into variable');

        $svc->saveXaaSCode($project, <<<'PY'
def handler(event, context):
    message = "Hello, World!!!"
    print(f"Returning: {message}")
    return {"statusCode": 200, "body": message}
PY, $userId, 'Add logging and third exclamation mark');
    }

    private function seedFaaSVersions(ProjectService $svc, $project, string $userId): void
    {
        $ext = match ($project->runtime) {
            'python' => 'py', 'nodejs' => 'js', 'php' => 'php', default => 'js'
        };
        $mainFile = "main.{$ext}";
        $depFile = $this->demoDependencyFile($project->runtime);

        $codeV2 = $this->demoFaaSCode($project->runtime, 2);
        $codeV3 = $this->demoFaaSCode($project->runtime, 3);
        $codeV4 = $this->demoFaaSCode($project->runtime, 4);

        $files2 = [$mainFile => $codeV2];
        $files3 = [$mainFile => $codeV3];
        $files4 = [$mainFile => $codeV4];

        if ($depFile) {
            $depContent = $this->demoDependencyContent($project->runtime);
            $files3[$depFile] = $depContent;
            $files4[$depFile] = $depContent;
        }

        $svc->saveFaaSFiles($project, $files2, $userId, 'Add extra exclamation mark');
        $svc->saveFaaSFiles($project, $files3, $userId, 'Add dependencies');
        $svc->saveFaaSFiles($project, $files4, $userId, 'Add logging');
    }

    private function demoFaaSCode(string $runtime, int $variant): string
    {
        return match ($runtime) {
            'nodejs' => match ($variant) {
                2 => "exports.handler = async (event, context) => {\n  return { statusCode: 200, body: 'Hello, World!!' };\n};",
                3 => "exports.handler = async (event, context) => {\n  const message = 'Hello, World!!';\n  return { statusCode: 200, body: message };\n};",
                default => "exports.handler = async (event, context) => {\n  const message = 'Hello, World!!!';\n  console.log('Returning:', message);\n  return { statusCode: 200, body: message };\n};",
            },
            'python' => match ($variant) {
                2 => "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"Hello, World!!\"}",
                3 => "def handler(event, context):\n    message = \"Hello, World!!\"\n    return {\"statusCode\": 200, \"body\": message}",
                default => "def handler(event, context):\n    message = \"Hello, World!!!\"\n    print(f\"Returning: {message}\")\n    return {\"statusCode\": 200, \"body\": message}",
            },
            'php' => match ($variant) {
                2 => "<?php\n\nfunction handler(\$event, \$context): array\n{\n    return ['statusCode' => 200, 'body' => 'Hello, World!!'];\n}",
                3 => "<?php\n\nfunction handler(\$event, \$context): array\n{\n    \$message = 'Hello, World!!';\n    return ['statusCode' => 200, 'body' => \$message];\n}",
                default => "<?php\n\nfunction handler(\$event, \$context): array\n{\n    \$message = 'Hello, World!!!';\n    error_log(\"Returning: {\$message}\");\n    return ['statusCode' => 200, 'body' => \$message];\n}",
            },
            default => "// variant {$variant}",
        };
    }

    private function demoDependencyFile(string $runtime): ?string
    {
        return match ($runtime) {
            'python' => 'requirements.txt',
            'nodejs' => 'package.json',
            'php' => 'composer.json',
            default => null,
        };
    }

    private function demoDependencyContent(string $runtime): string
    {
        return match ($runtime) {
            'python' => "redis>=5.0\n",
            'nodejs' => '{"dependencies":{"ioredis":"^5"}}',
            'php' => '{"require":{"predis/predis":"^2.0"}}',
            default => '',
        };
    }

    private function seedCaaSVersions(ProjectService $svc, $project, string $userId): void
    {
        $cc = $project->containerConfig;

        $svc->updateCaaSConfig(
            $project->load('containerConfig'),
            [
                'image' => $cc->image, 'command' => $cc->command, 'args' => $cc->args,
                'env_vars' => $cc->env_vars, 'ports' => $cc->ports, 'cpu' => $cc->cpu,
                'memory_mb' => $cc->memory_mb * 2, 'timeout_seconds' => $cc->timeout_seconds,
                'image_pull_policy' => $cc->image_pull_policy ?? 'IfNotPresent',
                'image_pull_secret' => $cc->image_pull_secret,
                'trigger_type' => $cc->trigger_type, 'schedule' => $cc->schedule,
            ],
            $userId, 'Double memory limit',
        );

        $cc->refresh();
        $svc->updateCaaSConfig(
            $project->load('containerConfig'),
            [
                'image' => $cc->image, 'command' => $cc->command, 'args' => $cc->args,
                'env_vars' => $cc->env_vars, 'ports' => $cc->ports, 'cpu' => $cc->cpu,
                'memory_mb' => $cc->memory_mb, 'timeout_seconds' => 60,
                'image_pull_policy' => $cc->image_pull_policy ?? 'IfNotPresent',
                'image_pull_secret' => $cc->image_pull_secret,
                'trigger_type' => $cc->trigger_type, 'schedule' => $cc->schedule,
            ],
            $userId, 'Extend timeout to 60 seconds',
        );

        $cc->refresh();
        $svc->updateCaaSConfig(
            $project->load('containerConfig'),
            [
                'image' => $cc->image, 'command' => $cc->command, 'args' => $cc->args,
                'env_vars' => $cc->env_vars, 'ports' => $cc->ports, 'cpu' => '500m',
                'memory_mb' => $cc->memory_mb, 'timeout_seconds' => $cc->timeout_seconds,
                'image_pull_policy' => $cc->image_pull_policy ?? 'IfNotPresent',
                'image_pull_secret' => $cc->image_pull_secret,
                'trigger_type' => $cc->trigger_type, 'schedule' => $cc->schedule,
            ],
            $userId, 'Increase CPU to 500m',
        );
    }
}
