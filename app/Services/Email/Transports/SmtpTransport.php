<?php

namespace Everest\Services\Email\Transports;

use Everest\Services\Email\EmailMessage;
use Everest\Services\Email\EmailResult;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;

class SmtpTransport implements EmailTransport
{
    public function __construct(private array $config)
    {
    }

    public function getName(): string
    {
        return 'smtp';
    }

    public function send(EmailMessage $message): EmailResult
    {
        $encryption = $this->config['encryption'] ?? null;
        if ($encryption === '') {
            $encryption = null;
        }

        // Apply runtime mail configuration for SMTP
        $configuredPort = $this->config['port'] ?? null;
        $configuredPort = ($configuredPort === '' || $configuredPort === null) ? null : (int) $configuredPort;

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => $this->config['host'] ?? null,
                'port' => $configuredPort,
                'username' => $this->config['username'] ?? null,
                'password' => $this->config['password'] ?? null,
                'encryption' => $encryption,
                'timeout' => $this->config['timeout'] ?? null,
                'auth_mode' => null,
            ],
            'mail.from.address' => $message->from,
            'mail.from.name' => $message->fromName,
        ]);

        try {
            Mail::mailer('smtp')->send([], [], function (Message $mail) use ($message) {
                $mail->to($message->to)
                    ->from($message->from, $message->fromName)
                    ->subject($message->subject)
                    ->html($message->html);

                if ($message->text) {
                    $mail->text($message->text);
                }

                if ($message->replyTo) {
                    $mail->replyTo($message->replyTo);
                }
            });

            // Laravel's Mail::send does not expose provider message id; use a generated id for tracking consistency
            $generatedId = $this->generateMessageId();

            return new EmailResult(
                success: true,
                messageId: $generatedId,
                statusCode: 200
            );
        } catch (\Throwable $e) {
            Log::error('SMTP send failed', [
                'error' => $e->getMessage(),
            ]);

            return EmailResult::failure($e->getMessage());
        }
    }

    private function generateMessageId(): string
    {
        return sprintf(
            '%s@%s',
            bin2hex(random_bytes(16)),
            parse_url(config('app.url'), PHP_URL_HOST) ?: 'smtp.local'
        );
    }
}
