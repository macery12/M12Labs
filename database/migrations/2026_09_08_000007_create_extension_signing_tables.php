<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Package signing: the release keys the panel trusts, and every verdict it
 * reached.
 *
 * The panel pins only the offline root key, in config. Release keys are
 * short-lived and rotate, so they arrive with the registry and are admitted
 * here only after the pinned root's signature over the key record verifies —
 * which is what stops a compromised registry from simply listing its own key.
 *
 * The audit table exists because a signature verdict is not derivable after the
 * fact: the release key may since have been revoked, the registry may have been
 * republished, the artifact may have been re-uploaded. It also carries the
 * highest version seen per extension, which is the rollback guard.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('extension_trusted_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key_id')->unique();
            // Raw Ed25519 public key, base64. 32 bytes before encoding.
            $table->string('public_key');
            $table->char('fingerprint', 64)->index();
            // Which repository presented this key, for the audit trail; trust
            // comes from the root signature, never from the source.
            $table->unsignedInteger('repository_id')->nullable();
            $table->string('label')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('repository_id')->references('id')->on('extension_repositories')->nullOnDelete();
        });

        Schema::create('extension_signature_audit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('extension_id')->index();
            $table->string('version');
            $table->string('verdict', 24);
            $table->string('key_id')->nullable();
            $table->char('archive_sha256', 64)->nullable();
            $table->char('canonical_manifest_sha256', 64)->nullable();
            $table->string('reason')->nullable();
            $table->string('initiator')->nullable();
            $table->timestamps();

            $table->index(['extension_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_signature_audit');
        Schema::dropIfExists('extension_trusted_keys');
    }
};
