<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Drop the three columns that welded Custom Domains into core billing.
 *
 * Custom Domains is being extracted into an extension package, and an
 * extension cannot own a column on `products`, `servers` or `orders`. There is
 * no generic entitlement or checkout capability for it to hook into either, so
 * per-plan domain caps and purchase-time domain selection are being removed
 * outright rather than reimplemented — see docs/leftover_extensions.md.
 *
 * Deliberately additive-then-destructive rather than editing the consolidated
 * chain: 2026_07_29_000002 positions plan_change_snapshot with
 * ->after('domain_payload'), so the column must still exist when that migration
 * runs. Dropping it here, afterwards, keeps a fresh install's ordering intact.
 *
 * `orders.domain_payload` fed two HMACs: CheckoutIntegrityService's tamper
 * fingerprint and CheckoutSnapshotService's request-idempotency fingerprint.
 * Both keep a constant empty entry in the column's place, so every order that
 * never bought a domain keeps the fingerprint it was locked with — including
 * the copy written into the payment provider's own metadata, which no migration
 * can reach. Without that, an order already paid at the provider would fail
 * capture after this deploy and need manual reconciliation. Orders that really
 * did carry a domain payload will fail verification and have to be re-checked
 * out; they were buying a feature that no longer exists.
 *
 * Plan-change orders cannot be protected the same way. PlanChangeService hashes
 * a product state that included subdomain_limit into orders.plan_change_snapshot,
 * and no constant reproduces that hash once the real per-product value is gone.
 * A plan change quoted before this migration and captured after it fails as an
 * invalid entitlement snapshot, so any still open are named in the log below —
 * drain them before deploying.
 */
return new class () extends Migration {
    public function up(): void
    {
        $this->warnAboutOpenPlanChanges();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('subdomain_limit');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('subdomain_limit');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('domain_payload');
        });
    }

    /**
     * Name the orders this migration is about to strand, rather than letting
     * them fail one at a time at capture with no explanation.
     */
    private function warnAboutOpenPlanChanges(): void
    {
        $stranded = DB::table('orders')
            ->where('type', 'upg')
            ->whereNotNull('plan_change_snapshot')
            ->whereNotNull('checkout_locked_at')
            ->whereIn('status', ['pending', 'fulfilling', 'payment_review'])
            ->pluck('id')
            ->all();

        if ($stranded === []) {
            return;
        }

        Log::warning('Plan-change orders stranded by the custom-domain column removal', [
            'order_ids' => $stranded,
            'reason' => 'Their entitlement snapshot hashes a product state that included subdomain_limit.',
            'action' => 'Refund or reconcile these orders by hand; they cannot be captured.',
        ]);
    }

    public function down(): void
    {
        // The columns come back empty. Their contents described a capability
        // the panel no longer has, so restoring shapes without values is the
        // honest limit of what a rollback can offer.
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('subdomain_limit')->nullable()->default(1);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('subdomain_limit')->nullable()->default(1);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->json('domain_payload')->nullable();
        });
    }
};
