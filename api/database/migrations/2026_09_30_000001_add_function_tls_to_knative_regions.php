<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Region-level "function-domain TLS" setting — separate from the
 * console's own Ingress TLS (helm/templates/tls-secret.yaml). Additive/nullable-only:
 * existing rows are unaffected, and the feature stays inert until an operator calls
 * PUT /knative-regions/{id}/function-tls.
 *
 * The private key is never persisted here — only the Kubernetes Secret
 * (runr-function-tls, applied into the region's own knative-serving namespace)
 * holds it, mirroring how the Helm chart never writes ingress.tls.privateKey
 * anywhere but the Secret it renders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->string('function_tls_mode')->nullable();
            $table->string('function_tls_secret_name')->nullable();
            $table->dateTime('function_tls_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knative_regions', function (Blueprint $table) {
            $table->dropColumn(['function_tls_mode', 'function_tls_secret_name', 'function_tls_expires_at']);
        });
    }
};
