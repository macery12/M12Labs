<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_addon_files', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('path');
            $table->string('type', 20);
            $table->boolean('disabled')->default(false);
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('modified_at')->nullable();
            $table->string('jar_hash', 64)->nullable();
            $table->string('package_version')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'path']);
            $table->index(['server_id', 'type']);
            $table->index('jar_hash');
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->foreign('package_id')->references('id')->on('addon_packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_addon_files');
    }
};
