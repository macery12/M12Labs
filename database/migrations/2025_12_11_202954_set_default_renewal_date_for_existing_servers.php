<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Default renewal period in days for new servers.
     * This should match the value in CreateServerService.
     */
    private const DEFAULT_RENEWAL_DAYS = 30;

    /**
     * Run the migrations.
     *
     * Set a default renewal date for servers that have null renewal_date.
     * This ensures all servers have a valid renewal date for billing purposes.
     */
    public function up(): void
    {
        // Update servers with null renewal_date to have a renewal date 30 days from now
        DB::table('servers')
            ->whereNull('renewal_date')
            ->update(['renewal_date' => Carbon::now()->addDays(self::DEFAULT_RENEWAL_DAYS)->toDateTimeString()]);
    }

    /**
     * Reverse the migrations.
     *
     * This migration is not reversible because setting null renewal dates would break
     * billing functionality for servers that depend on this data for renewal tracking.
     */
    public function down(): void
    {
        // This migration is intentionally not reversible to protect billing data integrity
    }
};
