<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('state')
            ->orWhere('state', '=','')
            ->update(['state' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is intentionally irreversible.
    }
};
