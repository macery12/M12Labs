<?php

namespace Everest\Services\Email;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Everest\Exceptions\Service\Email\EmailNotConfiguredException;

/**
 * Turns the admin Email settings into Laravel mailer config.
 *
 * Workers are long-lived and settings change underneath them, so this runs
 * before every send. It only rebuilds the mailers when the settings actually
 * changed: the old SMTP path called Mail::forgetMailers() on every message,
 * which opened a fresh SMTP connection each time and threw away the failover
 * transport's memory of which provider is down.
 */
class PanelMailerConfigurator
{
    public const MAILER = 'panel';

    /** Settings last written into config by this process. */
    private ?string $fingerprint = null;

    public function __construct(private EmailSettingsReader $settings)
    {
    }

    public static function mailerFor(string $provider): string
    {
        return self::MAILER . '_' . $provider;
    }

    /**
     * Point the `panel` mailer at the primary provider, with the backup behind
     * it when one is set and usable.
     *
     * @return list<string> the providers, in the order they will be tried
     *
     * @throws EmailNotConfiguredException when the sender or the primary is incomplete
     */
    public function configure(): array
    {
        $state = $this->sync();
        $primary = $this->settings->primary();

        if (isset($state['errors'][$primary])) {
            throw new EmailNotConfiguredException($state['errors'][$primary], $primary);
        }

        $backup = $this->settings->backup();

        // A half-configured backup must not stop mail the primary can deliver.
        if ($backup !== null && isset($state['errors'][$backup])) {
            Log::warning('Email backup provider is not usable; sending through the primary only.', [
                'backup' => $backup,
                'reason' => $state['errors'][$backup],
            ]);

            return [$primary];
        }

        return $backup === null ? [$primary] : [$primary, $backup];
    }

    /**
     * Configure one provider on its own, for its connection check.
     *
     * @return string the mailer name to send through
     *
     * @throws EmailNotConfiguredException
     */
    public function configureProvider(string $provider): string
    {
        if (!in_array($provider, EmailSettingsReader::PROVIDERS, true)) {
            throw new EmailNotConfiguredException("Unsupported email provider: {$provider}.");
        }

        $state = $this->sync();

        if (isset($state['errors'][$provider])) {
            throw new EmailNotConfiguredException($state['errors'][$provider], $provider);
        }

        return self::mailerFor($provider);
    }

    /**
     * Write the current settings into mail config, purging the resolved
     * mailers only when something changed.
     *
     * @return array{errors: array<string, string>}
     */
    private function sync(): array
    {
        $state = $this->resolve();
        $fingerprint = hash('sha256', serialize($state));

        if ($fingerprint === $this->fingerprint) {
            return $state;
        }

        config([
            'mail.from.address' => $state['from']['address'],
            'mail.from.name' => $state['from']['name'],
            'mail.reply_to' => $state['reply_to'],
        ]);

        foreach ($state['mailers'] as $name => $config) {
            config(["mail.mailers.{$name}" => $config]);
        }

        // Cached mailers hold the old transports; the failover one also holds
        // the SMTP connection and its list of providers marked down.
        foreach ([self::MAILER, ...array_map(self::mailerFor(...), EmailSettingsReader::PROVIDERS)] as $name) {
            Mail::purge($name);
        }

        $this->fingerprint = $fingerprint;

        return $state;
    }

    /**
     * @return array{
     *     from: array{address: string, name: string|null},
     *     reply_to: array{address: string, name: null}|null,
     *     mailers: array<string, array<string, mixed>>,
     *     errors: array<string, string>,
     * }
     */
    private function resolve(): array
    {
        $from = $this->settings->fromEmail();
        $senderError = null;

        if ($from === '') {
            $senderError = 'No From address is set. Add one in Admin → Email.';
        } elseif (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $senderError = "The From address \"{$from}\" is not a valid email address. Fix it in Admin → Email.";
        }

        $mailers = [];
        $errors = [];

        foreach (EmailSettingsReader::PROVIDERS as $provider) {
            $result = $senderError ?? ($provider === 'smtp' ? $this->smtp() : $this->resend());

            if (is_string($result)) {
                $errors[$provider] = $result;
            } else {
                $mailers[self::mailerFor($provider)] = $result;
            }
        }

        $primary = $this->settings->primary();
        $backup = $this->settings->backup();

        if (isset($mailers[self::mailerFor($primary)])) {
            // Without a usable backup `panel` is the primary itself, not a
            // one-member failover: failover rethrows every error as "All
            // transports failed.", which would hide the SMTP code or the
            // Resend status the job uses to tell a bad password from an outage.
            $mailers[self::MAILER] = $backup !== null && isset($mailers[self::mailerFor($backup)])
                ? [
                    'transport' => 'failover',
                    'mailers' => [self::mailerFor($primary), self::mailerFor($backup)],
                    // How long a provider that failed is skipped before the
                    // failover tries it first again.
                    'retry_after' => 60,
                ]
                : $mailers[self::mailerFor($primary)];
        }

        $replyTo = $this->settings->replyTo();

        return [
            'from' => ['address' => $from, 'name' => $this->settings->fromName() ?: null],
            'reply_to' => $replyTo !== '' && $replyTo !== $from ? ['address' => $replyTo, 'name' => null] : null,
            'mailers' => $mailers,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>|string the mailer config, or what is missing
     */
    private function smtp(): array|string
    {
        $host = trim((string) $this->settings->get('settings::modules:email:smtp:host', ''));
        $port = trim((string) $this->settings->get('settings::modules:email:smtp:port', ''));
        $username = trim((string) $this->settings->get('settings::modules:email:smtp:username', ''));
        $password = (string) $this->settings->get('settings::modules:email:smtp:password', '');
        $encryption = strtolower(trim((string) $this->settings->get('settings::modules:email:smtp:encryption', '')));

        $missing = [];
        if ($host === '') {
            $missing[] = 'host';
        }
        if ($port === '' || !ctype_digit($port)) {
            $missing[] = 'port';
        }
        if ($username !== '' && $password === '') {
            $missing[] = 'password';
        }

        if ($missing !== []) {
            return 'SMTP is missing: ' . implode(', ', $missing) . '. Complete it in Admin → Email → SMTP.';
        }

        $port = (int) $port;

        return [
            'transport' => 'smtp',
            // "ssl", and "tls" on 465, are TLS from the first byte. "tls" on
            // any other port is STARTTLS, and required: without require_tls a
            // server that stops offering it would get the password in clear.
            'scheme' => $encryption === 'ssl' || ($encryption === 'tls' && $port === 465) ? 'smtps' : 'smtp',
            'require_tls' => $encryption === 'tls' && $port !== 465,
            'host' => $host,
            'port' => $port,
            'username' => $username !== '' ? $username : null,
            'password' => $username !== '' ? $password : null,
            'timeout' => 30,
        ];
    }

    /**
     * @return array<string, mixed>|string the mailer config, or what is missing
     */
    private function resend(): array|string
    {
        $key = trim((string) $this->settings->get('settings::modules:email:resend:api_key', ''));

        if ($key === '') {
            return 'No Resend API key is set. Add one in Admin → Email → Resend.';
        }

        return ['transport' => 'resend', 'key' => $key];
    }
}
