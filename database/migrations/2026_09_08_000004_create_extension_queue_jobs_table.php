<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Bookkeeping for every job an extension dispatches.
 *
 * The queue driver already tracks jobs, but not per extension and not in a way
 * uninstall can reason about. Three things need this table:
 *
 *  - Uninstall has to know whether the package still has work in flight.
 *    Deleting a package's class files while a worker holds one of its jobs
 *    produces an unserializable payload and a job that can never succeed or be
 *    retried, so uninstall drains first and refuses while anything is running.
 *  - maxOutstanding is a quota, which needs a count of what is already queued.
 *  - The admin page shows an extension's queue health and last failure without
 *    an operator having to correlate Horizon tags by hand.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('extension_queue_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // The queue driver's own job uuid, which is how a worker event
            // finds this row again. Null only for the window between the row
            // being written and the push returning.
            $table->uuid('job_uuid')->nullable()->unique();
            $table->string('extension_id');
            // The logical queue group from the manifest, not a driver queue.
            $table->string('queue_name', 64);
            $table->string('job_class');
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // Redacted: class plus message, never the payload — a job's
            // constructor arguments can carry an extension's secrets.
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['extension_id', 'status']);
            $table->index(['extension_id', 'queue_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_queue_jobs');
    }
};
