<?php

namespace Everest\Console\Commands\Extensions;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Everest\Services\Extensions\ExtensionSecretStore;

/**
 * Re-encrypt every extension credential under the current APP_KEY.
 *
 * Rotating APP_KEY makes every stored credential undecryptable, and the store
 * refuses to guess: the alternative to this command is re-entering every token
 * by hand, from memory, for every installed extension. Run it with the previous
 * key while the new one is already in place.
 */
class RewrapExtensionSecretsCommand extends Command
{
    protected $signature = 'p:ext:secrets:rewrap
                            {--previous-key= : The APP_KEY the credentials were encrypted under}';

    protected $description = 'Re-encrypt stored extension credentials after an APP_KEY rotation.';

    public function handle(ExtensionSecretStore $store): int
    {
        $previous = (string) $this->option('previous-key');

        if ($previous === '') {
            $this->error('Pass the previous APP_KEY with --previous-key.');

            return 1;
        }

        try {
            $encrypter = new Encrypter(
                $this->parseKey($previous),
                (string) config('app.cipher')
            );
        } catch (\Throwable $exception) {
            $this->error('The previous key could not be used: ' . $exception->getMessage());

            return 1;
        }

        $result = $store->rewrap(static fn (string $ciphertext): string => $encrypter->decryptString($ciphertext));

        $this->info(sprintf('Re-encrypted %d credential(s).', $result['rewrapped']));

        if ($result['failed'] !== []) {
            // Named, not dumped: a value that will not decrypt has to be
            // re-entered, and knowing which ones is the whole output an
            // operator needs.
            $this->warn('These could not be decrypted with the previous key and must be re-entered:');
            foreach ($result['failed'] as $identifier) {
                $this->line('  - ' . $identifier);
            }

            return 1;
        }

        return 0;
    }

    private function parseKey(#[\SensitiveParameter] string $key): string
    {
        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }
}
