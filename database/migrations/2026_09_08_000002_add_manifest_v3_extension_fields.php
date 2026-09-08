<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Persisted capability projection, integrity metadata and an operation journal.
 *
 * The capability columns are denormalized from the manifest deliberately: the
 * runtime plan runs on every request and must not decode a full manifest per
 * installed package to answer "may this load?". capability_hash guards the
 * duplication — a projection that no longer matches its manifest marks the
 * package failed rather than being trusted.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('extension_packages', function (Blueprint $table): void {
            $table->json('capabilities')->nullable()->after('manifest_version');
            $table->char('capability_hash', 64)->nullable()->after('capabilities');
            // The capability set an administrator last approved. An update that
            // asks for more than this must be approved again before it runs.
            $table->char('approved_capability_hash', 64)->nullable()->after('capability_hash');
            $table->char('manifest_hash', 64)->nullable()->after('approved_capability_hash');
            $table->string('publisher')->nullable()->after('manifest_hash');
            $table->string('signature_state', 24)->default('unsigned')->after('publisher');
            $table->string('signature_key_id')->nullable()->after('signature_state');
            $table->timestamp('signature_verified_at')->nullable()->after('signature_key_id');
            $table->string('previous_version')->nullable()->after('installed_version');
            $table->uuid('last_operation_id')->nullable()->after('signature_verified_at');
        });

        // Rollback journal and audit trail for every lifecycle operation. Kept
        // separate from the package row so a failed install that never created
        // one still leaves a record of what was attempted and how to recover.
        Schema::create('extension_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('correlation_id')->unique();
            $table->string('extension_id')->index();
            $table->string('operation', 24);
            $table->string('from_version')->nullable();
            $table->string('to_version')->nullable();
            $table->string('stage', 32)->nullable();
            $table->string('status', 16)->default('running');
            $table->unsignedInteger('initiator_user_id')->nullable();
            $table->string('initiator_label')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('affected_paths')->nullable();
            $table->json('affected_tables')->nullable();
            $table->string('migration_batch')->nullable();
            $table->json('capability_diff')->nullable();
            $table->string('error_code', 64)->nullable();
            // Redacted at write time: an operation report is shown to admins and
            // may be exported, so it must never carry a secret or a raw trace.
            $table->text('error_summary')->nullable();
            $table->text('recovery_instructions')->nullable();
            $table->timestamps();

            $table->index(['extension_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_operations');

        Schema::table('extension_packages', function (Blueprint $table): void {
            $table->dropColumn([
                'capabilities',
                'capability_hash',
                'approved_capability_hash',
                'manifest_hash',
                'publisher',
                'signature_state',
                'signature_key_id',
                'signature_verified_at',
                'previous_version',
                'last_operation_id',
            ]);
        });
    }
};
