<?php

namespace App\Http\Controllers\Web;

use App\Actions\BeginSimulatedPaymentAttempt;
use App\Actions\CancelSimulatedCheckout;
use App\Actions\ExpireCheckoutReservations;
use App\Actions\ProcessSimulatedPayment;
use App\Http\Controllers\Controller;
use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\PaymentAttempt;
use App\Models\Ticket;
use App\Services\Tickets\TicketSessionAccess;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function show(
        Request $request,
        CheckoutOrder $checkoutOrder,
        ExpireCheckoutReservations $expiration,
        CancelSimulatedCheckout $cancel,
        TicketSessionAccess $ticketAccess,
    ): Response {
        $this->requireSecureRequest($request);
        $this->authorizeOrder($request, $checkoutOrder);
        $event = $checkoutOrder->event;

        if (! $event->canOfferSimulatedPayments()) {
            $cancel->execute($checkoutOrder->id);
        } else {
            DB::transaction(function () use ($event, $expiration): void {
                $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $expiration->forLockedEvent($lockedEvent);
            });
        }

        $checkoutOrder->refresh()->load([
            'event',
            'ticketCategory',
            'registration',
            'attempts' => fn ($query) => $query->orderByDesc('attempt_number'),
        ]);
        if ($checkoutOrder->status === CheckoutOrder::STATUS_SUCCESSFUL
            && $checkoutOrder->registration?->user_id === null) {
            $ticket = $checkoutOrder->registration->ticket()->firstOrFail();
            if ($ticket->credential_version === Ticket::INITIAL_CREDENTIAL_VERSION) {
                $ticketAccess->grantGuest($request, $checkoutOrder->registration, $ticket);

                $redemptionCode = $this->recoverRedemptionCode($checkoutOrder);
                if ($redemptionCode !== null) {
                    $ticketAccess->grantRedemptionCode($request, $checkoutOrder->registration, $redemptionCode);
                }
            }
        }
        $attemptKey = (string) Str::uuid();

        return response()->view('public.checkout.show', compact('checkoutOrder', 'attemptKey'))
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }

    public function beginAttempt(
        Request $request,
        CheckoutOrder $checkoutOrder,
        BeginSimulatedPaymentAttempt $begin,
    ): RedirectResponse {
        $this->requireSecureRequest($request);
        $this->authorizeOrder($request, $checkoutOrder);
        $data = $request->validate([
            'payment_method' => ['required', Rule::in([PaymentAttempt::METHOD_WALLET, PaymentAttempt::METHOD_CARD])],
            'attempt_key' => ['required', 'uuid'],
            'simulated_outcome' => ['required', Rule::in([
                PaymentAttempt::SIMULATED_OUTCOME_SUCCESS,
                PaymentAttempt::SIMULATED_OUTCOME_FAILURE,
            ])],
        ]);

        $begin->execute($checkoutOrder->id, $data['payment_method'], $data['attempt_key'], $data['simulated_outcome']);

        return redirect()->route('public.checkout.show', $checkoutOrder->public_id);
    }

    public function process(
        Request $request,
        CheckoutOrder $checkoutOrder,
        ProcessSimulatedPayment $process,
        TicketSessionAccess $ticketAccess,
    ): RedirectResponse {
        $this->requireSecureRequest($request);
        $this->authorizeOrder($request, $checkoutOrder);
        $data = $request->validate(['attempt_key' => ['required', 'uuid']]);
        $result = $process->execute($checkoutOrder->id, $data['attempt_key']);

        if ($result['status'] === CheckoutOrder::STATUS_SUCCESSFUL && $result['registration'] !== null) {
            $registration = $result['registration'];
            $ticket = $registration->ticket()->firstOrFail();
            if ($registration->user_id === null
                && $ticket->credential_version === Ticket::INITIAL_CREDENTIAL_VERSION) {
                $ticketAccess->grantGuest($request, $registration, $ticket);
                $redemptionCode = $result['redemption_code'] ?? $this->recoverRedemptionCode($result['order']);
                if ($redemptionCode !== null) {
                    $ticketAccess->grantRedemptionCode($request, $registration, $redemptionCode);
                }
            }

            return redirect()->route('public.tickets.show', $registration->id);
        }

        $message = match ($result['status']) {
            CheckoutOrder::STATUS_FAILED => 'The simulated payment was declined. No money was charged. You can try again before the reservation expires.',
            CheckoutOrder::STATUS_EXPIRED => 'This checkout expired. Its capacity reservation has been released.',
            CheckoutOrder::STATUS_CANCELLED => 'This checkout is no longer available.',
            default => 'The checkout is still pending. Resume it here when you are ready.',
        };

        return redirect()->route('public.checkout.show', $checkoutOrder->public_id)->with('checkout_message', $message);
    }

    public function cancel(
        Request $request,
        CheckoutOrder $checkoutOrder,
        CancelSimulatedCheckout $cancel,
    ): RedirectResponse {
        $this->requireSecureRequest($request);
        $this->authorizeOrder($request, $checkoutOrder);
        $order = $cancel->execute($checkoutOrder->id);

        return redirect()->route('public.checkout.show', $order->public_id);
    }

    private function authorizeOrder(Request $request, CheckoutOrder $order): void
    {
        $accountOwnsOrder = $order->user_id !== null
            && (int) $request->user()?->id === (int) $order->user_id;
        $guestHasSessionGrant = $order->user_id === null
            && $request->session()->get('simulated_checkout_access.'.$order->public_id) === true;

        abort_unless($accountOwnsOrder || $guestHasSessionGrant, 404);
    }

    private function recoverRedemptionCode(CheckoutOrder $order): ?string
    {
        $ciphertext = $order->redemption_code_ciphertext;
        if (! is_string($ciphertext)) {
            return null;
        }

        if ($order->redemption_code_expires_at === null || $order->redemption_code_expires_at->lte(now())) {
            $order->forceFill(['redemption_code_ciphertext' => null, 'redemption_code_expires_at' => null])->save();

            return null;
        }

        try {
            return Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            $order->forceFill(['redemption_code_ciphertext' => null, 'redemption_code_expires_at' => null])->save();

            return null;
        }
    }

    private function requireSecureRequest(Request $request): void
    {
        if (! app()->environment(['local', 'testing'])) {
            abort_unless($request->secure(), 403, 'HTTPS is required.');
        }
    }
}
