<?php

namespace Everest\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Everest\Events\Email\PaymentReceived;

class PaymentReceivedMail extends PanelMail
{
    public const KEY = 'billing.payment_received';

    /**
     * The invoice PDF, set by withInvoice(). Not constructor parameters, so
     * they never reach the template.
     */
    private ?string $invoicePath = null;
    private ?string $invoiceDisk = null;
    private string $invoiceName = 'invoice.pdf';

    public function __construct(
        public readonly string $userName,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $paymentMethod,
        public readonly string $invoiceId,
        public readonly string $transactionDate,
        public readonly bool $isRenewal,
        public readonly ?string $originalAmount,
        public readonly ?string $discountAmount,
        public readonly ?string $couponCode,
        public readonly ?int $billingDays,
        public readonly string $billingCycle,
    ) {
    }

    public static function fromEvent(PaymentReceived $event): self
    {
        $mail = new self(
            userName: self::displayName($event->user),
            amount: number_format($event->amount, 2),
            currency: strtoupper($event->currency),
            paymentMethod: $event->paymentMethod,
            invoiceId: $event->invoiceId ?? 'N/A',
            transactionDate: self::now(),
            isRenewal: $event->isRenewal,
            originalAmount: $event->originalAmount ? number_format($event->originalAmount, 2) : null,
            discountAmount: $event->discountAmount ? number_format($event->discountAmount, 2) : null,
            couponCode: $event->couponCode,
            billingDays: $event->billingDays,
            billingCycle: self::billingCycle($event->billingDays),
        );

        if ($event->invoiceFilePath) {
            $mail->withInvoice($event->invoiceFilePath, $event->invoiceFileDisk, $event->invoiceFileName);
        }

        return $mail;
    }

    /**
     * Attach the invoice PDF. With no disk the path is an absolute local path,
     * which is what billing passes for its cached PDFs. (The old listener only
     * attached when a disk was set, so these were never attached at all.).
     */
    public function withInvoice(string $path, ?string $disk = null, ?string $name = null): self
    {
        $this->invoicePath = $path;
        $this->invoiceDisk = $disk;
        $this->invoiceName = $name ?: 'invoice.pdf';

        return $this;
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if ($this->invoicePath === null) {
            return [];
        }

        // The PDF cache is evicted after a day, and a missing attachment must
        // not stop the receipt itself from going out.
        $exists = $this->invoiceDisk !== null
            ? Storage::disk($this->invoiceDisk)->exists($this->invoicePath)
            : is_file($this->invoicePath) && is_readable($this->invoicePath);

        if (!$exists) {
            Log::warning('PaymentReceivedMail: invoice PDF is gone, sending the receipt without it.', [
                'invoice_id' => $this->invoiceId,
            ]);

            return [];
        }

        $attachment = $this->invoiceDisk !== null
            ? Attachment::fromStorageDisk($this->invoiceDisk, $this->invoicePath)
            : Attachment::fromPath($this->invoicePath);

        return [$attachment->as($this->invoiceName)->withMime('application/pdf')];
    }

    protected function subjectLine(): string
    {
        return 'Payment Received - Thank You';
    }
}
