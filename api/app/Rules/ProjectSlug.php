<?php

namespace App\Rules;

use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A project name must yield a slug Kubernetes accepts: the slug (Project::slugFor, incl. the
 * tenant suffix) names the Knative Service, the CronJob `<slug>-trigger`, the build Job and the
 * code ConfigMap. Checks DNS-1035 format, Project::MAX_SLUG_LENGTH and that no other project
 * already has the slug (two names like "My App" and "my-app" would share one Knative Service).
 */
class ProjectSlug implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreProjectId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $slug = Project::slugFor($value);
        $max = Project::MAX_SLUG_LENGTH;

        if (! preg_match(Project::SLUG_PATTERN, $slug)) {
            $fail(__('api.project.slug_invalid', ['slug' => $slug === '' ? '—' : $slug]));

            return;
        }

        if (strlen($slug) > $max) {
            $fail(__('api.project.slug_too_long', ['slug' => $slug, 'length' => strlen($slug), 'max' => $max]));

            return;
        }

        $taken = Project::where('slug', $slug)
            ->when($this->ignoreProjectId, fn ($q) => $q->where('id', '!=', $this->ignoreProjectId))
            ->exists();

        if ($taken) {
            $fail(__('api.project.slug_taken', ['slug' => $slug]));
        }
    }
}
