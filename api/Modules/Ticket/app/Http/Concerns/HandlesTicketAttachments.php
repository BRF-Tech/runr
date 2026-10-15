<?php

namespace Modules\Ticket\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HandlesTicketAttachments
{
    protected function storeAttachments(Request $request, int $ticketId): array
    {
        $paths = [];
        if (! $request->hasFile('attachments')) {
            return $paths;
        }

        foreach ($request->file('attachments') as $file) {
            $realMime = $file->getMimeType();
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
            if (! in_array($realMime, $allowed, true)) {
                continue;
            }

            $paths[] = $file->store("support-attachments/{$ticketId}");
        }

        return $paths;
    }

    protected function serveAttachment(int $ticketId, string $filename): StreamedResponse
    {
        $filename = basename($filename);
        $path = "support-attachments/{$ticketId}/{$filename}";

        if (! Storage::exists($path)) {
            abort(404);
        }

        return Storage::download($path, $filename);
    }
}
