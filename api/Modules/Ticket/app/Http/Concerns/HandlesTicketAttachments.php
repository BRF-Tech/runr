<?php

namespace Modules\Ticket\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Ticket\Models\SupportTicket;
use Modules\Ticket\Support\TicketAttachments;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HandlesTicketAttachments
{
    /** @return list<string> stored paths (support-attachments/[<tenant id>/]<ticket id>/…) */
    protected function storeAttachments(Request $request, int $ticketId): array
    {
        return TicketAttachments::store($request, $ticketId);
    }

    /**
     * A file recorded on $ticket (its attachments or a reply's; internal notes only with
     * $includeInternal) — 404 for any other name, whatever lies on the disk.
     */
    protected function serveAttachment(SupportTicket $ticket, string $filename, bool $includeInternal = false): StreamedResponse
    {
        $path = TicketAttachments::resolve($ticket, $filename, $includeInternal);

        if ($path === null || ! Storage::exists($path)) {
            abort(404);
        }

        return Storage::download($path, basename($path));
    }
}
