<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Hook delivery: tombstones and per-hook health.
 *
 * A tombstone is what makes a queued pre-delete handler possible at all.
 * Extension tables reference servers with cascadeOnDelete, so their rows are
 * gone the instant the server row is deleted; a handler that runs a second
 * later has nothing to read. The tombstone is written before the deletion
 * transaction opens and carries the payload the handler would have read, so it
 * survives the cascade.
 *
 * Health accumulates per (extension, hook) rather than per extension. A hook
 * that keeps failing is quarantined on its own — never the extension it belongs
 * to, and never another extension's subscription to the same event.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('extension_hook_tombstones', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('correlation_id')->index();
            $table->string('extension_id');
            $table->string('event', 64);
            $table->string('handler');
            // The whole envelope, exactly as a synchronous handler would have
            // received it. Scalars and arrays only — HookEvent forbids models.
            $table->json('envelope');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['extension_id', 'event']);
            // Named explicitly: the generated name would be 68 characters and
            // MySQL caps identifiers at 64. One tombstone per handler per
            // dispatch, so a redelivery cannot duplicate the payload.
            $table->unique(
                ['correlation_id', 'extension_id', 'handler'],
                'ext_hook_tombstones_delivery_unique'
            );
        });

        Schema::create('extension_hook_health', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('extension_id');
            $table->string('event', 64);
            $table->string('handler');
            $table->unsignedBigInteger('invocations')->default(0);
            $table->unsignedBigInteger('failures')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('total_duration_ms')->default(0);
            $table->timestamp('last_invoked_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            // Breaker: open until this time, then one probe is let through.
            $table->timestamp('breaker_open_until')->nullable();
            $table->unsignedInteger('breaker_trips')->default(0);
            // Set after repeated trips. Skips this hook only.
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();

            $table->unique(['extension_id', 'event', 'handler']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_hook_health');
        Schema::dropIfExists('extension_hook_tombstones');
    }
};
