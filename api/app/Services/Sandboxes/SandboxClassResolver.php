<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\SandboxRegionSetting;
use App\Services\Helpers\KataRuntime;
use App\Services\Runtimes\RuntimeManifest;

/**
 * Which RuntimeClass ("sandbox class") a sandbox pod runs with.
 *
 * Installed classes come from the region's sandbox installs (Settings → Regions → Sandbox): the
 * Kata hypervisors (kata-fc, kata-clh, kata-dragonball, kata-qemu) and gVisor. runc is never
 * installed-and-allowed by default: it is offered only where the region's sandbox settings allow it
 * explicitly. The allowed set is installed ∩ the region's allowed_classes ∩ the organization's
 * allowed_classes (null = no restriction) ∩ what the runtime's manifest supports.
 *
 * Strength, strongest first: Kata with Firecracker, Cloud Hypervisor, Dragonball, QEMU (a VM per
 * sandbox, its own kernel) > gVisor (user-space kernel) > runc (shared host kernel). A sandbox gets
 * the region's default class when it is allowed, otherwise the strongest allowed one.
 */
final class SandboxClassResolver
{
    /** Kata hypervisors, strongest isolation / smallest VMM first */
    public const KATA_ORDER = ['fc', 'clh', 'dragonball', 'qemu'];

    public const RUNC = 'runc';

    /** Sandbox classes installed on the region, strongest first (runc not included). */
    public function installed(KnativeRegion $region): array
    {
        $classes = [];

        if ($region->has_sandbox_kata) {
            $hypervisors = array_values((array) ($region->sandbox_kata_hypervisor ?: ['clh']));
            foreach (self::KATA_ORDER as $hv) {
                if (in_array($hv, $hypervisors, true)) {
                    $classes[] = KataRuntime::runtimeClass($hv);
                }
            }
        }

        if ($region->has_sandbox_gvisor) {
            $classes[] = 'gvisor';
        }

        return $classes;
    }

    /**
     * Classes a sandbox of the organization may use in the region, strongest first.
     *
     * @param  list<string>|null  $tenantAllowed  the organization's allowed_classes (null: no restriction)
     */
    public function allowed(KnativeRegion $region, SandboxRegionSetting $settings, ?array $tenantAllowed = null, ?RuntimeManifest $manifest = null): array
    {
        $classes = $this->installed($region);

        if (is_array($settings->allowed_classes)) {
            $classes = array_values(array_filter($classes, fn ($c) => $this->listed($c, $settings->allowed_classes)));
        }

        // runc: only on the region's explicit opt-in (allowed_classes lists installed sandboxes)
        if ($settings->allow_runc) {
            $classes[] = self::RUNC;
        }

        if (is_array($tenantAllowed)) {
            $classes = array_values(array_filter($classes, fn ($c) => $this->listed($c, $tenantAllowed)));
        }

        if ($manifest) {
            $classes = array_values(array_filter($classes, fn ($c) => $manifest->supportsRuntimeClass($c === self::RUNC ? null : $c)));
        }

        return $classes;
    }

    /**
     * The class of a new sandbox: $requested (null / "auto": the default; "kata": the strongest
     * allowed Kata hypervisor; or an exact class name).
     *
     * @throws SandboxException class_unavailable
     */
    public function resolve(?string $requested, KnativeRegion $region, SandboxRegionSetting $settings, ?array $tenantAllowed = null, ?RuntimeManifest $manifest = null): string
    {
        $allowed = $this->allowed($region, $settings, $tenantAllowed, $manifest);

        if ($allowed === []) {
            throw new SandboxException(__('sandboxes.no_class', ['region' => $region->name]), 422, 'class_unavailable', [
                'errors' => ['sandbox_class' => [__('sandboxes.no_class', ['region' => $region->name])]],
            ]);
        }

        $requested = $requested === null || $requested === '' ? 'auto' : strtolower($requested);

        if ($requested === 'auto') {
            $default = $settings->default_class;
            if ($default && in_array($default, $allowed, true)) {
                return $default;
            }
            if ($default === 'kata' && ($kata = $this->strongestKata($allowed))) {
                return $kata;
            }

            return $allowed[0];
        }

        if ($requested === 'kata') {
            if ($kata = $this->strongestKata($allowed)) {
                return $kata;
            }
        } elseif (in_array($requested, $allowed, true)) {
            return $requested;
        }

        $message = __('sandboxes.class_not_allowed', ['class' => $requested, 'allowed' => implode(', ', $allowed)]);
        throw new SandboxException($message, 422, 'class_unavailable', [
            'errors' => ['sandbox_class' => [$message]],
            'allowed_classes' => $allowed,
        ]);
    }

    /** Default class of the region for the organization (null when nothing is allowed). */
    public function effectiveDefault(KnativeRegion $region, SandboxRegionSetting $settings, ?array $tenantAllowed = null): ?string
    {
        try {
            return $this->resolve(null, $region, $settings, $tenantAllowed);
        } catch (SandboxException) {
            return null;
        }
    }

    /** Rank for sorting: lower = stronger isolation. */
    public static function rank(string $class): int
    {
        $hv = KataRuntime::hypervisorOf($class);
        if ($hv !== null) {
            return (int) array_search($hv, self::KATA_ORDER, true);
        }

        return $class === 'gvisor' ? 10 : 20;
    }

    /** Whether the classes a request can name include $class ("kata" names every Kata class). */
    private function listed(string $class, array $list): bool
    {
        return in_array($class, $list, true)
            || (KataRuntime::hypervisorOf($class) !== null && in_array('kata', $list, true));
    }

    private function strongestKata(array $allowed): ?string
    {
        foreach ($allowed as $class) {
            if (KataRuntime::hypervisorOf($class) !== null) {
                return $class;
            }
        }

        return null;
    }
}
