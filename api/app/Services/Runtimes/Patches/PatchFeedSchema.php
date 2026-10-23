<?php

namespace App\Services\Runtimes\Patches;

use App\Models\RuntimePatch;
use Illuminate\Support\Facades\Validator;

/**
 * Schema v1 of the runtime patch feed (docs/security.md#runtime-patch-feed):
 *
 *   {"schema": 1, "generated_at": "…",
 *    "patches": [{"id", "runtime", "version", "tag", "image", "digest", "level",
 *                 "reason": {"en", "tr"}, "cves": [], "url", "upstream", "published_at", "deadline_at"}],
 *    "platform": [{"version", "level", "reason": {"en", "tr"}, "url", "published_at"}]}
 *
 * A feed with any error is refused as a whole (the last good one stays).
 */
final class PatchFeedSchema
{
    public const VERSION = 1;

    /** @return list<string> what is wrong with $data, empty when it is a valid v1 feed */
    public static function errors(mixed $data): array
    {
        if (! is_array($data)) {
            return ['The feed is not a JSON object'];
        }

        $validator = Validator::make($data, [
            'schema' => ['required', 'integer', 'in:'.self::VERSION],
            'generated_at' => ['required', 'date'],
            'patches' => ['present', 'array'],
            'patches.*' => ['array'],
            'patches.*.id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', 'distinct'],
            'patches.*.runtime' => ['required', 'string', 'max:64'],
            'patches.*.version' => ['required', 'string', 'max:64'],
            'patches.*.tag' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_][A-Za-z0-9._-]*$/'],
            'patches.*.image' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.\-:]+(\/[a-z0-9._\-]+)+$/'],
            'patches.*.digest' => ['required', 'string', 'regex:/^sha256:[a-f0-9]{64}$/'],
            'patches.*.level' => ['required', 'string', 'in:'.implode(',', RuntimePatch::LEVELS)],
            'patches.*.reason' => ['required', 'array'],
            'patches.*.reason.en' => ['required', 'string', 'max:2000'],
            'patches.*.reason.tr' => ['nullable', 'string', 'max:2000'],
            'patches.*.cves' => ['nullable', 'array'],
            'patches.*.cves.*' => ['string', 'max:64'],
            'patches.*.url' => ['nullable', 'string', 'url', 'max:500'],
            'patches.*.upstream' => ['nullable', 'string', 'max:255'],
            'patches.*.published_at' => ['required', 'date'],
            'patches.*.deadline_at' => ['nullable', 'date'],
            'platform' => ['nullable', 'array'],
            'platform.*' => ['array'],
            'platform.*.version' => ['required', 'string', 'max:64', 'regex:/^v?\d+\.\d+(\.\d+)?([.-][A-Za-z0-9.]+)?$/', 'distinct'],
            'platform.*.level' => ['required', 'string', 'in:'.implode(',', RuntimePatch::LEVELS)],
            'platform.*.reason' => ['required', 'array'],
            'platform.*.reason.en' => ['required', 'string', 'max:2000'],
            'platform.*.reason.tr' => ['nullable', 'string', 'max:2000'],
            'platform.*.url' => ['nullable', 'string', 'url', 'max:500'],
            'platform.*.published_at' => ['nullable', 'date'],
        ]);

        $errors = $validator->fails() ? array_values($validator->errors()->all()) : [];

        // One tag per runtime version, once
        $seen = [];
        foreach ((array) ($data['patches'] ?? []) as $i => $patch) {
            if (! is_array($patch)) {
                continue;
            }
            $key = ($patch['runtime'] ?? '').':'.($patch['version'] ?? '').':'.($patch['tag'] ?? '');
            if (isset($seen[$key])) {
                $errors[] = "patches.{$i}: the tag {$key} is listed twice";
            }
            $seen[$key] = true;
        }

        return $errors;
    }
}
