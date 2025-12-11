<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
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
            ->update(['renewal_date' => Carbon::now()->addDays(30)->toDateString()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to revert, as the renewal dates set are valid data
    }
};
