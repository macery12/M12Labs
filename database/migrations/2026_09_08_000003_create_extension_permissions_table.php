<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Admin permissions contributed by installed extensions.
 *
 * These rows are the only source of ext.<id>.admin.<action> capabilities. They
 * exist so the capability catalog can name a permission an extension defined
 * without core hardcoding it, and so an operator's grant survives the extension
 * being disabled — the row is suspended, not deleted, and the grant is restored
 * when the extension comes back rather than being silently dropped from every
 * role the next time somebody saves one.
 *
 * approved_at is the escalation guard: an update that introduces a permission
 * lands it unapproved, which keeps it out of the catalog and therefore
 * unassignable until the capability diff is approved.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('extension_permissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('extension_id');
            $table->string('action', 64);
            // The flattened capability identifier the rest of the panel sees.
            // Derived by the panel from extension_id + action, never supplied
            // by the package, and unique across every extension.
            $table->string('identifier')->unique();
            $table->string('label_key');
            $table->string('description_key')->nullable();
            $table->boolean('dangerous')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique(['extension_id', 'action']);
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_permissions');
    }
};
