<?php

namespace App\Services\Checkout;

use App\Models\PaymentAttempt;

class SimulatedPaymentProcessor
{
    /** The simulator resolves an outcome on the server and never reads a client status field. */
    public function process(PaymentAttempt $attempt): string
    {
        return random_int(1, 100) <= 95
            ? PaymentAttempt::STATUS_SUCCESSFUL
            : PaymentAttempt::STATUS_FAILED;
    }
}
