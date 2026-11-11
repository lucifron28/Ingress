<?php

namespace App\Services\Checkout;

use App\Models\PaymentAttempt;

class SimulatedPaymentProcessor
{
    /** Resolve only the persisted demo selection; unknown values fail closed. */
    public function process(PaymentAttempt $attempt): string
    {
        return match ($attempt->simulated_outcome) {
            PaymentAttempt::SIMULATED_OUTCOME_SUCCESS => PaymentAttempt::STATUS_SUCCESSFUL,
            PaymentAttempt::SIMULATED_OUTCOME_FAILURE => PaymentAttempt::STATUS_FAILED,
            default => PaymentAttempt::STATUS_FAILED,
        };
    }
}
