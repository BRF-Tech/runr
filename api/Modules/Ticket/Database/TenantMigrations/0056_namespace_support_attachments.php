<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Modules\Ticket\Support\TicketAttachments;

/**
 * Ticket ids are per tenant schema, so the attachments of every tenant's ticket 1 shared
 * support-attachments/1/. The tenant's files move into its own directory,
 * support-attachments/<tenant id>/<ticket id>/, and its tickets and replies record the new paths.
 * Only files recorded on this tenant's rows move; a file that is missing keeps its path.
 * Idempotent, and downloads resolve either layout, so a run that stops halfway loses nothing.
 */
return new class extends Migration
{
    /** Each file move is followed by the row update that records it (no transaction to undo one without the other) */
    public $withinTransaction = false;

    public function up(): void
    {
        try {
            $result = TicketAttachments::namespaceExisting();
            if ($result['moved'] > 0 || $result['missing'] > 0) {
                Log::info('Support attachments moved into the tenant directory', $result + ['tenant' => TicketAttachments::tenantId()]);
            }
        } catch (Throwable $e) {
            // Downloads keep resolving the recorded paths: an upgrade never fails on the files
            Log::warning('Support attachments not moved into the tenant directory', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // The files stay where they are: downloads resolve the recorded paths of either layout
    }
};
