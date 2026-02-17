<?php

namespace Everest\Console\Commands\Email;

use Carbon\Carbon;
use Everest\Models\EmailDelivery;
use Everest\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Everest\Events\Email\ServerRenewalNotice;
use Ramsey\Uuid\Uuid;

class SendServerRenewalNoticesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:send-renewal-notices
                            {--days=7 : Send notices for servers expiring in exactly X days (per-day window)}
                            {--dry-run : Preview servers without sending emails}';

    /**
     * The console command description.
     */
    protected $description = 'Send renewal notices to users with servers expiring soon';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysAhead = (int) $this->option('days');
        $isDryRun = $this->option('dry-run');

        $now = Carbon::now();
        $this->info("Finding servers expiring in {$daysAhead} day(s)...");

        // Calculate the date range for the target day window
        $targetDate = $now->copy()->addDays($daysAhead);
        $renewalStart = $targetDate->copy()->startOfDay();
        $renewalEnd = $targetDate->copy()->endOfDay();

        // Find servers with renewal date in the specified timeframe
        // Only send for active servers (not suspended or already expired)
        $servers = Server::whereNotNull('renewal_date')
            ->whereBetween('renewal_date', [$renewalStart, $renewalEnd])
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere('status', '!=', Server::STATUS_SUSPENDED);
            })
            ->with(['user', 'node'])
            ->get();

        if ($servers->isEmpty()) {
            $this->info("No servers found with renewal exactly {$daysAhead} day(s) away.");
            return Command::SUCCESS;
        }

        $this->info("Found {$servers->count()} server(s) with renewal in {$daysAhead} day(s)");

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($servers as $server) {
            if (!$server->user) {
                $this->warn("Skipping server {$server->id}: no user associated");
                $skippedCount++;
                continue;
            }

            $daysUntilRenewal = $now->diffInDays($server->renewal_date, false);
            $correlationId = $this->buildCorrelationId($server, $daysAhead);

            if ($this->noticeAlreadySent($correlationId)) {
                $this->line("Skipping server {$server->id}: renewal notice for {$daysAhead} day(s) already sent or pending");
                $skippedCount++;
                continue;
            }

            if ($isDryRun) {
                $this->line("Would send renewal notice for server: {$server->name} (ID: {$server->id}) - User: {$server->user->email}");
                continue;
            }

            try {
                $this->sendRenewalNotice($server, $daysUntilRenewal, $correlationId);
                $sentCount++;
                $this->info("✓ Sent renewal notice for server: {$server->name} (ID: {$server->id})");
            } catch (\Exception $e) {
                $this->error("✗ Failed to send renewal notice for server {$server->id}: " . $e->getMessage());
                Log::error("Failed to send renewal notice for server {$server->id}", [
                    'error' => $e->getMessage(),
                    'server_id' => $server->id,
                    'user_id' => $server->user_id,
                ]);
                $skippedCount++;
            }
        }

        if ($isDryRun) {
            $this->info("\nDry run complete. No emails were sent.");
        } else {
            $this->info("\nRenewal notices sent: {$sentCount}");
            if ($skippedCount > 0) {
                $this->warn("Skipped: {$skippedCount}");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Send renewal notice for a server.
     */
    private function sendRenewalNotice(Server $server, int $daysUntilRenewal, string $correlationId): void
    {
        $currency = config('modules.billing.currency.code', 'USD');
        
        // Get renewal amount from server's billing amount or default to 0
        $renewalAmount = $server->billing_amount ?? 0;

        // Get billing cycle (days) - defaults to 30 if not set
        $billingDays = $server->billing_days ?? 30;

        // Calculate suspension time (typically renewal_date + 1 day)
        $suspensionTime = $server->renewal_date->copy()->addDay();

        // Generate renewal URL pointing to server billing page
        $renewalUrl = url("/server/{$server->uuidShort}/billing");

        event(new ServerRenewalNotice(
            user: $server->user,
            server: $server,
            renewalUrl: $renewalUrl,
            renewalDate: $server->renewal_date->format('F j, Y \a\t g:i A'),
            suspensionTime: $suspensionTime->format('F j, Y \a\t g:i A'),
            renewalAmount: $renewalAmount,
            currency: $currency,
            billingDays: $billingDays,
            correlationId: $correlationId,
        ));

        Log::info("Sent renewal notice for server {$server->id}", [
            'server_id' => $server->id,
            'user_id' => $server->user_id,
            'renewal_date' => $server->renewal_date->toDateTimeString(),
            'days_until_renewal' => $daysUntilRenewal,
            'billing_days' => $billingDays,
        ]);
    }

    /**
     * Build a deterministic correlation ID for a server/day combination.
     */
    private function buildCorrelationId(Server $server, int $daysUntilRenewal): string
    {
        // Keep correlation IDs stable per server and day-distance to avoid duplicate sends in a window
        $seed = "server-{$server->id}-renewal-{$daysUntilRenewal}";

        return Uuid::uuid5(Uuid::NAMESPACE_URL, $seed)->toString();
    }

    /**
     * Check if a renewal notice for this correlation has already been sent or queued.
     */
    private function noticeAlreadySent(string $correlationId): bool
    {
        try {
            return EmailDelivery::where('correlation_id', $correlationId)
                ->whereIn('status', ['queued', 'sending', 'sent', 'deferred'])
                ->exists();
        } catch (\Throwable $e) {
            // If logging tables are missing, avoid blocking email sending
            Log::warning('Skipping renewal notice deduplication check; email_deliveries table unavailable', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
