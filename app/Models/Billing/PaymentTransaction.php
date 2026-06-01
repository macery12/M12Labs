<?php

namespace Everest\Models\Billing;

use Everest\Models\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';

    public static array $validationRules = [
        'order_id'      => 'required|integer|exists:orders,id',
        'processor'     => 'required|string|in:stripe,paypal,free',
        'external_id'   => 'nullable|string|max:255',
        'capture_id'    => 'nullable|string|max:255',
        'status'        => 'nullable|string|max:50',
        'amount'        => 'nullable|numeric|min:0',
        'currency'      => 'nullable|string|max:10',
        'payer_id'      => 'nullable|string|max:255',
        'payer_email'   => 'nullable|email|max:255',
        'payment_token' => 'nullable|string|max:255',
        'raw_metadata'  => 'nullable|array',
        'captured_at'   => 'nullable|date',
    ];

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
