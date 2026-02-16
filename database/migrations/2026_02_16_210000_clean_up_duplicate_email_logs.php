<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration cleans up duplicate email logs and ensures only complete logs remain.
     */
    public function up(): void
    {
        // Step 1: Delete logs with NULL user_id, template_key, AND correlation_id
        // These are incomplete/partial logs that should not exist
        DB::table('email_logs')
            ->whereNull('user_id')
            ->whereNull('template_key')
            ->whereNull('correlation_id')
            ->delete();
        
        // Step 2: For any remaining duplicates with same message_id, keep the one with complete data
        // Find duplicates by message_id
        $duplicates = DB::table('email_logs')
            ->select('message_id', DB::raw('COUNT(*) as count'))
            ->whereNotNull('message_id')
            ->groupBy('message_id')
            ->having('count', '>', 1)
            ->get();
        
        foreach ($duplicates as $duplicate) {
            // Get all logs with this message_id
            $logs = DB::table('email_logs')
                ->where('message_id', $duplicate->message_id)
                ->orderByRaw('
                    CASE 
                        WHEN user_id IS NOT NULL AND template_key IS NOT NULL AND correlation_id IS NOT NULL THEN 1
                        WHEN user_id IS NOT NULL AND template_key IS NOT NULL THEN 2
                        WHEN user_id IS NOT NULL THEN 3
                        ELSE 4
                    END
                ')
                ->orderBy('updated_at', 'desc')
                ->get();
            
            // Keep the first one (most complete), delete the rest
            $keepId = $logs->first()->id;
            DB::table('email_logs')
                ->where('message_id', $duplicate->message_id)
                ->where('id', '!=', $keepId)
                ->delete();
        }
        
        // Step 3: For any duplicates with same correlation_id, keep the one with complete data
        $duplicates = DB::table('email_logs')
            ->select('correlation_id', DB::raw('COUNT(*) as count'))
            ->whereNotNull('correlation_id')
            ->groupBy('correlation_id')
            ->having('count', '>', 1)
            ->get();
        
        foreach ($duplicates as $duplicate) {
            // Get all logs with this correlation_id
            $logs = DB::table('email_logs')
                ->where('correlation_id', $duplicate->correlation_id)
                ->orderByRaw('
                    CASE 
                        WHEN user_id IS NOT NULL AND template_key IS NOT NULL AND message_id IS NOT NULL THEN 1
                        WHEN user_id IS NOT NULL AND template_key IS NOT NULL THEN 2
                        WHEN user_id IS NOT NULL THEN 3
                        ELSE 4
                    END
                ')
                ->orderBy('updated_at', 'desc')
                ->get();
            
            // Keep the first one (most complete), delete the rest
            $keepId = $logs->first()->id;
            DB::table('email_logs')
                ->where('correlation_id', $duplicate->correlation_id)
                ->where('id', '!=', $keepId)
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse data deletion
    }
};
