<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Everest\Services\Security\SecretEncryptionService;

/*
 * The two settings keys are inlined rather than read from the service that used
 * to define them. Custom Domains has since been extracted into an extension
 * package and that class is gone from core, so a migration referencing it
 * stopped being runnable and broke `migrate` on a fresh install. A migration is
 * a historical record: it must not depend on application classes that can be
 * deleted underneath it.
 *
 * The row this writes is dropped again by
 * 2026_09_08_000009_drop_core_custom_domain_tables. This file stays because an
 * install that already ran it keeps a migrations row, and deleting the file
 * would make that row unknown to `p:migrate:adopt`.
 */
return new class () extends Migration {
    private const KEY = 'settings::modules:custom_domains:cloudflare:encrypted_token';

    private const LEGACY_KEY = 'settings::modules:custom_domains:cloudflare:token';

    public function up(): void
    {
        DB::transaction(function () {
            $existing = DB::table('settings')->where('key', self::KEY)->exists();
            if (!$existing) {
                $legacy = DB::table('settings')->where('key', self::LEGACY_KEY)->value('value');
                $token = $legacy ?? env('CUSTOM_DOMAINS_CLOUDFLARE_TOKEN', '');
                if (!empty($token) && blank(config('app.key'))) {
                    throw new Illuminate\Encryption\MissingAppKeyException();
                }
                $secrets = app(SecretEncryptionService::class);
                $token = $secrets->decryptFromStorage($token);
                DB::table('settings')->insert([
                    'key' => self::KEY,
                    'value' => $secrets->encryptForStorage($token) ?? '',
                ]);
            }

            DB::table('settings')->where('key', self::LEGACY_KEY)->delete();
        });
    }

    public function down(): void
    {
        // Preserve the encrypted credential; rollback must never restore plaintext.
    }
};
