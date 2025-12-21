<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing alerts to use the old position values if needed
        // Add comment to document the new position options
        DB::statement("ALTER TABLE `alerts` MODIFY COLUMN `position` VARCHAR(255) DEFAULT 'top-center' COMMENT 'top-center, slide-out, top-right-banner, center'");
        
        // Update any existing bottom-left or bottom-right to slide-out
        DB::table('alerts')
            ->whereIn('position', ['bottom-left', 'bottom-right'])
            ->update(['position' => 'slide-out']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert slide-out back to bottom-right for backwards compatibility
        DB::table('alerts')
            ->where('position', 'slide-out')
            ->update(['position' => 'bottom-right']);
            
        DB::table('alerts')
            ->where('position', 'top-right-banner')
            ->update(['position' => 'top-center']);
    }
};
