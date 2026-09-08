<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Remove core's Custom Domains storage. The feature now ships as an extension
 * package which owns ext_custom_domains_* tables of its own.
 *
 * Dropped without export, by decision: the panel is pre-release and the
 * replacement asks for its Cloudflare credential and domain catalogue again.
 * That includes settings::modules:custom_domains:cloudflare:encrypted_token —
 * the token is re-entered into the extension's encrypted secret store rather
 * than migrated, so a credential never moves between two places that both
 * believe they own it.
 *
 * Appended rather than deleting 2026_07_20_000019, for the same reason B1's
 * column drop was appended. The migration chain is history: an install that has
 * already run a migration keeps a row for it, and SchemaAdoptService::
 * unknownMigrations() reports any applied row present in neither the legacy nor
 * the current chain. Deleting the creating migration would make every existing
 * install look like it had run something this panel has never heard of.
 *
 * database/migrations_legacy/ and database/schema/legacy-migrations.txt keep
 * their custom-domain entries for the same reason.
 */
return new class () extends Migration {
    private const SETTINGS_PREFIX = 'settings::modules:custom_domains:';

    public function up(): void
    {
        // Child tables first: server_custom_domains and custom_domain_dns_logs
        // hold foreign keys into custom_domains and custom_domain_api_keys.
        Schema::dropIfExists('custom_domain_dns_logs');
        Schema::dropIfExists('server_custom_domains');
        Schema::dropIfExists('custom_domains');
        Schema::dropIfExists('custom_domain_api_keys');

        DB::table('settings')->where('key', 'like', self::SETTINGS_PREFIX . '%')->delete();
    }

    public function down(): void
    {
        // The tables come back empty and the settings do not come back at all.
        // Their contents described a feature core no longer implements; a
        // rollback that invented rows would be worse than one that admits the
        // limit.
        Schema::create('custom_domain_api_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->text('token');
            $table->boolean('enabled')->default(1);
            $table->timestamps();
        });

        Schema::create('custom_domains', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain')->unique();
            $table->string('cloudflare_zone_id')->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->json('allowed_nest_ids')->nullable();
            $table->json('allowed_egg_ids')->nullable();
            $table->string('service_tag')->nullable();
            $table->json('egg_service_tags')->nullable();
            $table->boolean('wildcard_enabled')->default(0);
            $table->boolean('enabled')->default(1);
            $table->timestamps();

            $table->foreign('api_key_id')->references('id')->on('custom_domain_api_keys')->onDelete('set null');
        });

        Schema::create('server_custom_domains', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('allocation_id')->nullable()->index();
            $table->unsignedBigInteger('custom_domain_id');
            $table->string('subdomain');
            $table->string('full_domain');
            $table->unsignedInteger('port');
            $table->enum('protocol', ['tcp', 'udp', 'both'])->default('both');
            $table->enum('record_type', ['srv', 'cname'])->nullable();
            $table->string('service_tag')->nullable();
            $table->enum('status', ['pending', 'active', 'failed'])->default('pending');
            $table->json('dns_records')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['full_domain', 'port', 'protocol'], 'server_custom_domains_unique_target');
            $table->index(['server_id', 'status']);
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->foreign('allocation_id')->references('id')->on('allocations')->onDelete('set null');
            $table->foreign('custom_domain_id')->references('id')->on('custom_domains')->onDelete('cascade');
        });

        Schema::create('custom_domain_dns_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id')->nullable();
            $table->unsignedBigInteger('server_custom_domain_id')->nullable();
            $table->enum('action', ['create', 'update', 'delete', 'sync', 'ssl']);
            $table->enum('status', ['success', 'failed']);
            $table->json('payload')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
            $table->foreign('server_id')->references('id')->on('servers')->onDelete('set null');
            $table->foreign('server_custom_domain_id')->references('id')->on('server_custom_domains')->onDelete('set null');
        });
    }
};
