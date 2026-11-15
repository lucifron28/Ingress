<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    public const METHOD_WALLET = 'wallet';

    public const METHOD_CARD = 'card';

    public const SIMULATED_OUTCOME_SUCCESS = 'success';

    public const SIMULATED_OUTCOME_FAILURE = 'failure';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'checkout_order_id',
        'attempt_number',
        'attempt_key',
        'payment_method',
        'simulated_outcome',
        'status',
        'outcome_code',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function checkoutOrder(): BelongsTo
    {
        return $this->belongsTo(CheckoutOrder::class);
    }
}
