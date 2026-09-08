<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Encrypted credentials for installed extensions.
 *
 * A separate table rather than extension_configs.settings, because that column
 * is a plain JSON blob the catalog API returns — a token placed there is
 * readable by anyone who can list extensions.
 *
 * context_hash binds a ciphertext to the extension, key and version it was
 * written for. Without it, a row copied between extensions (or between keys of
 * the same extension) would still decrypt, so an operator with database access
 * could hand one extension another's credential by moving a row. With it, the
 * copy is inert.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('extension_secrets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('extension_id');
            $table->string('key', 64);
            $table->text('value');
            // Bumped on every rotation, and part of the context binding, so a
            // restored backup row cannot resurrect a rotated-out credential
            // under the current version.
            $table->unsignedInteger('key_version')->default(1);
            $table->char('context_hash', 64);
            $table->timestamp('rotated_at')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['extension_id', 'key']);
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_secrets');
    }
};
