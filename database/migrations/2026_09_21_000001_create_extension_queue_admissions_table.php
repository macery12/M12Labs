<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * One durable mutex row per extension queue group.
 *
 * Dispatchers update and lock this row before counting in-flight journal rows
 * and inserting a reservation. The row is deliberately permanent: deleting
 * idle rows would recreate the empty-set race this table exists to prevent.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('extension_queue_admissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('extension_id');
            $table->string('queue_name', 64);
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamps();

            $table->unique(['extension_id', 'queue_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_queue_admissions');
    }
};
