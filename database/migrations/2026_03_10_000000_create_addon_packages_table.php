<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('identity_key');
            $table->string('loader');
            $table->string('provider')->default('local');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('authors')->nullable();
            $table->string('homepage_url')->nullable();
            $table->string('source_url')->nullable();
            $table->string('issues_url')->nullable();
            $table->string('icon_path')->nullable();
            $table->timestamps();

            $table->unique(['identity_key', 'loader'], 'addon_packages_identity_loader_unique');
            $table->index('identity_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_packages');
    }
};
