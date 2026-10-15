<?php

namespace App\Services\SupplyChain;

/**
 * Summary of the SBOM documents syft wrote (CycloneDX JSON and SPDX JSON): package count,
 * packages per ecosystem (purl type), the most frequent licenses and the generator.
 */
class SbomSummarizer
{
    private const TOP_LICENSES = 10;

    /**
     * @return array{packages: int, by_type: array<string, int>, licenses: array<string, int>, generator: ?string, spdx_version: ?string, cyclonedx_spec: ?string, formats: list<string>}
     */
    public function summarize(?string $spdxJson, ?string $cyclonedxJson): array
    {
        $spdx = $spdxJson !== null ? json_decode($spdxJson, true) : null;
        $cdx = $cyclonedxJson !== null ? json_decode($cyclonedxJson, true) : null;
        $spdx = is_array($spdx) ? $spdx : null;
        $cdx = is_array($cdx) ? $cdx : null;

        $byType = [];
        $licenses = [];
        $packages = 0;

        if ($cdx) {
            foreach ($cdx['components'] ?? [] as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $packages++;
                $type = self::purlType($component['purl'] ?? null) ?? (string) ($component['type'] ?? 'unknown');
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                foreach (self::cdxLicenses($component['licenses'] ?? []) as $license) {
                    $licenses[$license] = ($licenses[$license] ?? 0) + 1;
                }
            }
        } elseif ($spdx) {
            foreach ($spdx['packages'] ?? [] as $package) {
                if (! is_array($package) || self::isSpdxImageRoot($package)) {
                    continue;
                }
                $packages++;
                $type = self::purlType(self::spdxPurl($package)) ?? 'unknown';
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                // syft leaves licenseConcluded NOASSERTION and fills licenseDeclared
                foreach ([$package['licenseConcluded'] ?? null, $package['licenseDeclared'] ?? null] as $license) {
                    if (is_string($license) && ! in_array($license, ['NOASSERTION', 'NONE', ''], true)) {
                        $licenses[$license] = ($licenses[$license] ?? 0) + 1;
                        break;
                    }
                }
            }
        }

        arsort($byType);
        arsort($licenses);

        return [
            'packages' => $packages,
            'by_type' => $byType,
            'licenses' => array_slice($licenses, 0, self::TOP_LICENSES, true),
            'generator' => self::generator($spdx, $cdx),
            'spdx_version' => isset($spdx['spdxVersion']) ? (string) $spdx['spdxVersion'] : null,
            'cyclonedx_spec' => isset($cdx['specVersion']) ? (string) $cdx['specVersion'] : null,
            'formats' => array_values(array_filter([$spdx ? 'spdx-json' : null, $cdx ? 'cyclonedx-json' : null])),
        ];
    }

    /** "pkg:apk/alpine/musl@1.2.5" → "apk" */
    private static function purlType(?string $purl): ?string
    {
        if (! is_string($purl) || ! preg_match('#^pkg:([a-z0-9.+-]+)/#i', $purl, $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    /** @return list<string> */
    private static function cdxLicenses(mixed $entries): array
    {
        $out = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $value = $entry['license']['id'] ?? $entry['license']['name'] ?? $entry['expression'] ?? null;
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function spdxPurl(array $package): ?string
    {
        foreach ($package['externalRefs'] ?? [] as $ref) {
            if (($ref['referenceType'] ?? '') === 'purl') {
                return $ref['referenceLocator'] ?? null;
            }
        }

        return null;
    }

    /** syft describes the scanned image itself as a package without a purl of an ecosystem */
    private static function isSpdxImageRoot(array $package): bool
    {
        return str_starts_with((string) ($package['SPDXID'] ?? ''), 'SPDXRef-DocumentRoot');
    }

    private static function generator(?array $spdx, ?array $cdx): ?string
    {
        foreach ($spdx['creationInfo']['creators'] ?? [] as $creator) {
            if (is_string($creator) && str_starts_with($creator, 'Tool: ')) {
                return substr($creator, 6);
            }
        }

        $tools = $cdx['metadata']['tools'] ?? null;
        $tools = is_array($tools) ? ($tools['components'] ?? $tools) : [];
        foreach ($tools as $tool) {
            if (is_array($tool) && ! empty($tool['name'])) {
                return trim($tool['name'].'-'.($tool['version'] ?? ''), '-');
            }
        }

        return null;
    }
}
