<?php

namespace Modules\Ticket\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Ticket\Models\SupportTicket;

/**
 * Where ticket attachments live and who gets them (docs/modules.md, Ticket).
 *
 * Files are stored on the default disk under support-attachments/<ticket id>/ in a single-tenant
 * install and under support-attachments/<tenant id>/<ticket id>/ inside a tenant: ticket ids are
 * per tenant schema, so every tenant has a ticket 1. A download is served only for a file recorded
 * on that ticket (its own attachments or a reply's) — the ticket row lives in the tenant's schema,
 * so the path a download resolves to always belongs to the requesting tenant. Paths are kept as
 * stored: files of either layout keep working.
 */
final class TicketAttachments
{
    public const ROOT = 'support-attachments';

    /** MIME types accepted (checked on the content, not the client's name). */
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    /** Id of the tenant whose context is active; null in a single-tenant install or outside one. */
    public static function tenantId(): ?string
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized || ! tenancy()->tenant) {
            return null;
        }

        return (string) tenancy()->tenant->getTenantKey();
    }

    /** Directory of a ticket's files: support-attachments/[<tenant id>/]<ticket id>. */
    public static function directory(int $ticketId): string
    {
        $tenantId = self::tenantId();

        return $tenantId !== null
            ? self::ROOT.'/'.$tenantId.'/'.$ticketId
            : self::ROOT.'/'.$ticketId;
    }

    /**
     * Stores the request's attachments (field attachments[]) of the allowed types in the ticket's
     * directory.
     *
     * @return list<string> stored paths
     */
    public static function store(Request $request, int $ticketId): array
    {
        $paths = [];
        if (! $request->hasFile('attachments')) {
            return $paths;
        }

        foreach ((array) $request->file('attachments') as $file) {
            if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
                continue;
            }

            $stored = $file->store(self::directory($ticketId));
            if (is_string($stored) && $stored !== '') {
                $paths[] = $stored;
            }
        }

        return $paths;
    }

    /**
     * The stored path of $filename on $ticket: the ticket's own attachments and its replies' —
     * internal notes only for support staff ($includeInternal). Null when the ticket has no such
     * file, which a download answers with 404.
     */
    public static function resolve(SupportTicket $ticket, string $filename, bool $includeInternal = false): ?string
    {
        $filename = basename($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        $replies = $ticket->replies()
            ->when(! $includeInternal, fn ($q) => $q->where('is_internal', false))
            ->whereNotNull('attachments')
            ->pluck('attachments');

        $recorded = (array) ($ticket->attachments ?? []);
        foreach ($replies as $attachments) {
            $recorded = array_merge($recorded, self::decode($attachments));
        }

        foreach ($recorded as $path) {
            if (is_string($path) && self::isAttachmentPath($path) && basename($path) === $filename) {
                return self::locate($path);
            }
        }

        return null;
    }

    /**
     * Moves the current tenant's files still stored at the single-tenant path
     * (support-attachments/<ticket id>/<file>) into the tenant's directory and records the new
     * paths. Only files recorded on the tenant's own tickets and replies are touched; a missing
     * file keeps its recorded path. Idempotent: run again it finds nothing left to move. Call in a
     * tenant's context (the Ticket tenant migration, tenancy:setup); outside one it does nothing.
     *
     * @return array{moved: int, missing: int, rows: int}
     */
    public static function namespaceExisting(): array
    {
        $result = ['moved' => 0, 'missing' => 0, 'rows' => 0];
        $tenantId = self::tenantId();
        if ($tenantId === null) {
            return $result;
        }

        foreach (['support_tickets', 'support_ticket_replies'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table) || ! DB::getSchemaBuilder()->hasColumn($table, 'attachments')) {
                continue;
            }

            DB::table($table)->whereNotNull('attachments')
                ->chunkById(200, function ($rows) use ($table, $tenantId, &$result) {
                    foreach ($rows as $row) {
                        $paths = self::decode($row->attachments);
                        $changed = false;

                        foreach ($paths as $i => $path) {
                            $target = is_string($path) ? self::namespaced($path, $tenantId) : null;
                            if ($target === null) {
                                continue;
                            }

                            if (self::moveFile($path, $target)) {
                                $paths[$i] = $target;
                                $changed = true;
                                $result['moved']++;
                            } else {
                                $result['missing']++;
                            }
                        }

                        if ($changed) {
                            DB::table($table)->where('id', $row->id)->update(['attachments' => json_encode(array_values($paths))]);
                            $result['rows']++;
                        }
                    }
                });
        }

        return $result;
    }

    /** Deletes a tenant's attachment directory (support-attachments/<tenant id>). */
    public static function deleteTenantFiles(string $tenantId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $tenantId) !== 1) {
            return;
        }

        Storage::deleteDirectory(self::ROOT.'/'.$tenantId);
    }

    /**
     * The tenant-directory path of a single-tenant path (support-attachments/<number>/<file>);
     * null for a path in any other form (already in a tenant directory, foreign, malformed).
     */
    private static function namespaced(string $path, string $tenantId): ?string
    {
        if (preg_match('#^'.self::ROOT.'/(\d+)/([^/]+)$#', $path, $m) !== 1 || $m[2] === '.' || $m[2] === '..') {
            return null;
        }

        return self::ROOT.'/'.$tenantId.'/'.$m[1].'/'.$m[2];
    }

    /**
     * Moves $from to $to; true when the file is at $to afterwards (also when an earlier run moved
     * it already but did not record the new path).
     */
    private static function moveFile(string $from, string $to): bool
    {
        try {
            if (Storage::exists($from)) {
                if (Storage::exists($to)) {
                    // An earlier run copied it there: keep the tenant's copy
                    Storage::delete($from);

                    return true;
                }

                return Storage::move($from, $to);
            }

            return Storage::exists($to);
        } catch (\Throwable $e) {
            Log::warning('Support attachment not moved into the tenant directory', ['from' => $from, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * A recorded path whose file was moved into the tenant directory after it was recorded (a run
     * of namespaceExisting() interrupted between the move and the update) is found there.
     */
    private static function locate(string $path): string
    {
        if (Storage::exists($path)) {
            return $path;
        }

        $tenantId = self::tenantId();
        $moved = $tenantId !== null ? self::namespaced($path, $tenantId) : null;

        return $moved !== null && Storage::exists($moved) ? $moved : $path;
    }

    private static function isAttachmentPath(string $path): bool
    {
        return str_starts_with($path, self::ROOT.'/') && ! str_contains($path, '..');
    }

    /** @return array<int, mixed> */
    private static function decode(mixed $attachments): array
    {
        if (is_array($attachments)) {
            return $attachments;
        }
        if (is_string($attachments) && $attachments !== '') {
            $decoded = json_decode($attachments, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
