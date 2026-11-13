<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI provider credentials (spec §2.15.8 F-2.15.8-001/014, WP-37).
 *
 * ONE table holds both sides of the D-48 credential chain:
 *   customer_id NULL  → the operator's shared key ("admin supplies keys");
 *   customer_id set   → that tenant's own key ("BYO-key mode"), which WINS.
 *
 * `api_key` is an encrypted cast on the model — the ciphertext is what lands
 * in the column, never the key itself. `options` carries the per-vendor
 * extras (Azure api-version, organisation id…) exactly as delivery_servers
 * does, and never a secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_credentials', function (Blueprint $table): void {
            $table->id();

            // NULL = operator-supplied shared credential.
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();

            // Engine key from the AiEngineRegistry: openai, anthropic, …
            $table->string('provider', 64);

            // Encrypted at rest (model cast). Null for keyless engines (Ollama).
            $table->text('api_key')->nullable();

            $table->string('base_url')->nullable();

            // Model name, or an Azure DEPLOYMENT name.
            $table->string('model', 190)->nullable();

            $table->json('options')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One row per provider per owner; the operator row is the NULL one.
            $table->unique(['customer_id', 'provider']);
            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_credentials');
    }
};
