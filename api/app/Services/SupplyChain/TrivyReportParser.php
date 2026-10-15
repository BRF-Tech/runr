<?php

namespace App\Services\SupplyChain;

/**
 * Trivy JSON report (`trivy image --format json`, SchemaVersion 2) → what a report stores:
 * counts per severity (all findings and those with a fixed version), the findings themselves
 * (deduplicated, most severe first, capped) and the image metadata Trivy saw.
 */
class TrivyReportParser
{
    public const SEVERITIES = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'];

    /**
     * @return array{
     *   counts: array{critical: int, high: int, medium: int, low: int, unknown: int},
     *   fixable: array{critical: int, high: int, medium: int, low: int, unknown: int},
     *   total: int,
     *   findings: list<array{id: string, severity: string, package: string, installed: string, fixed: ?string, title: ?string, url: string, target: ?string, type: ?string, status: ?string}>,
     *   digest: ?string,
     *   os: ?string,
     *   artifact: ?string,
     *   created_at: ?string
     * }
     */
    public function parse(string|array $report, ?int $maxFindings = null): array
    {
        $data = is_array($report) ? $report : json_decode($report, true);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('The scan report is not valid JSON');
        }

        $maxFindings ??= (int) config('supply_chain.scan.max_findings', 2000);

        $counts = array_fill_keys(array_map('strtolower', self::SEVERITIES), 0);
        $fixable = $counts;
        $findings = [];
        $seen = [];

        foreach ($data['Results'] ?? [] as $result) {
            foreach ($result['Vulnerabilities'] ?? [] as $vuln) {
                $id = (string) ($vuln['VulnerabilityID'] ?? '');
                $package = (string) ($vuln['PkgName'] ?? $vuln['PkgID'] ?? '');
                $installed = (string) ($vuln['InstalledVersion'] ?? '');
                if ($id === '') {
                    continue;
                }

                // The same package can show up in several layers / targets
                $key = "{$id}|{$package}|{$installed}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $severity = strtoupper((string) ($vuln['Severity'] ?? 'UNKNOWN'));
                if (! in_array($severity, self::SEVERITIES, true)) {
                    $severity = 'UNKNOWN';
                }
                $fixed = trim((string) ($vuln['FixedVersion'] ?? '')) ?: null;

                $counts[strtolower($severity)]++;
                if ($fixed !== null) {
                    $fixable[strtolower($severity)]++;
                }

                $findings[] = [
                    'id' => $id,
                    'severity' => $severity,
                    'package' => $package,
                    'installed' => $installed,
                    'fixed' => $fixed,
                    'title' => isset($vuln['Title']) ? mb_substr((string) $vuln['Title'], 0, 300) : null,
                    'url' => self::advisoryUrl($id, $vuln['PrimaryURL'] ?? null),
                    'target' => isset($result['Target']) ? (string) $result['Target'] : null,
                    'type' => isset($result['Type']) ? (string) $result['Type'] : null,
                    'status' => isset($vuln['Status']) ? (string) $vuln['Status'] : null,
                ];
            }
        }

        $rank = array_flip(self::SEVERITIES);
        usort($findings, fn ($a, $b) => [$rank[$a['severity']], $a['fixed'] === null, $a['package'], $a['id']]
            <=> [$rank[$b['severity']], $b['fixed'] === null, $b['package'], $b['id']]);

        $os = $data['Metadata']['OS'] ?? null;

        return [
            'counts' => $counts,
            'fixable' => $fixable,
            'total' => count($findings),
            'findings' => array_slice($findings, 0, max(0, $maxFindings)),
            'digest' => self::digestFrom($data['Metadata']['RepoDigests'] ?? []),
            'os' => is_array($os) ? trim(($os['Family'] ?? '').' '.($os['Name'] ?? '')) ?: null : null,
            'artifact' => isset($data['ArtifactName']) ? (string) $data['ArtifactName'] : null,
            'created_at' => isset($data['CreatedAt']) ? (string) $data['CreatedAt'] : null,
        ];
    }

    /**
     * Trivy's `version --format json` output → scanner metadata (Trivy version, DB build time).
     *
     * @return array{version: ?string, db_version: ?int, db_updated_at: ?string, db_next_update: ?string}
     */
    public function parseVersion(string|array $version): array
    {
        $data = is_array($version) ? $version : (json_decode($version, true) ?: []);
        $db = $data['VulnerabilityDB'] ?? [];

        return [
            'version' => isset($data['Version']) ? (string) $data['Version'] : null,
            'db_version' => isset($db['Version']) ? (int) $db['Version'] : null,
            'db_updated_at' => isset($db['UpdatedAt']) ? (string) $db['UpdatedAt'] : null,
            'db_next_update' => isset($db['NextUpdate']) ? (string) $db['NextUpdate'] : null,
        ];
    }

    /** Advisory link of a finding: Trivy's primary URL, else NVD (CVE) / GitHub (GHSA) / Aqua's database. */
    public static function advisoryUrl(string $id, ?string $primaryUrl = null): string
    {
        if ($primaryUrl && preg_match('#^https://#i', $primaryUrl)) {
            return $primaryUrl;
        }
        if (preg_match('/^CVE-\d{4}-\d+$/i', $id)) {
            return 'https://nvd.nist.gov/vuln/detail/'.strtoupper($id);
        }
        if (preg_match('/^GHSA-[\w-]+$/i', $id)) {
            return 'https://github.com/advisories/'.$id;
        }

        return 'https://avd.aquasec.com/nvd/'.strtolower(rawurlencode($id));
    }

    /** "repo@sha256:..." entries → the first sha256 digest. */
    private static function digestFrom(array $repoDigests): ?string
    {
        foreach ($repoDigests as $entry) {
            if (is_string($entry) && preg_match('/@(sha256:[a-f0-9]{64})$/', $entry, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
