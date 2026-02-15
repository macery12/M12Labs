<?php

namespace Everest\Console\Commands\Email;

use Carbon\Carbon;
use Everest\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Everest\Events\Email\ServerRenewalNotice;

class SendServerRenewalNoticesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:send-renewal-notices
                            {--days=7 : Send notices for servers expiring in this many days}
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

        $this->info("Finding servers expiring in {$daysAhead} days...");

        // Calculate the date range for expiring servers
        $expiresAt = Carbon::now()->addDays($daysAhead);
        $expiresStart = $expiresAt->copy()->startOfDay();
        $expiresEnd = $expiresAt->copy()->endOfDay();

        // Find servers expiring in the specified timeframe
        // Only send for active servers (not suspended or already expired)
        $servers = Server::whereNotNull('expires_at')
            ->whereBetween('expires_at', [$expiresStart, $expiresEnd])
            ->where('status', '!=', 'suspended')
            ->with(['user', 'node'])
            ->get();

        if ($servers->isEmpty()) {
            $this->info('No servers found expiring in ' . $daysAhead . ' days.');
            return Command::SUCCESS;
        }

        $this->info("Found {$servers->count()} server(s) expiring on {$expiresAt->format('Y-m-d')}");

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($servers as $server) {
            if (!$server->user) {
                $this->warn("Skipping server {$server->id}: no user associated");
                $skippedCount++;
                continue;
            }

            if ($isDryRun) {
                $this->line("Would send renewal notice for server: {$server->name} (ID: {$server->id}) - User: {$server->user->email}");
                continue;
            }

            try {
                $this->sendRenewalNotice($server, $daysAhead);
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
    private function sendRenewalNotice(Server $server, int $daysUntilExpiry): void
    {
        $currency = config('modules.billing.currency.code', 'USD');
        
        // Get renewal amount from server's billing amount or default to 0
        $renewalAmount = $server->billing_amount ?? 0;

        // Get billing cycle (days) - defaults to 30 if not set
        $billingDays = $server->billing_days ?? 30;

        // Calculate suspension time (typically same day as expiration or 1 day after)
        $suspensionTime = $server->expires_at->copy()->addDay();

        // Generate renewal URL pointing to server billing page
        $renewalUrl = url("/server/{$server->uuidShort}/billing");

        event(new ServerRenewalNotice(
            user: $server->user,
            server: $server,
            renewalUrl: $renewalUrl,
            expiresAt: $server->expires_at->format('F j, Y \a\t g:i A'),
            suspensionTime: $suspensionTime->format('F j, Y \a\t g:i A'),
            renewalAmount: $renewalAmount,
            currency: $currency,
            billingDays: $billingDays,
            correlationId: \Illuminate\Support\Str::uuid()->toString(),
        ));

        Log::info("Sent renewal notice for server {$server->id}", [
            'server_id' => $server->id,
            'user_id' => $server->user_id,
            'expires_at' => $server->expires_at->toDateTimeString(),
            'days_until_expiry' => $daysUntilExpiry,
            'billing_days' => $billingDays,
        ]);
    }
}
