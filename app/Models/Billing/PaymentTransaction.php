<?php

namespace Everest\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';

    protected $fillable = [
        'order_id',
        'processor',
        'external_id',
        'capture_id',
        'status',
        'amount',
        'currency',
        'payer_id',
        'payer_email',
        'payment_token',
        'raw_metadata',
        'captured_at',
    ];

    protected $casts = [
        'captured_at'  => 'datetime',
        'raw_metadata' => 'array',
        'amount'       => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
