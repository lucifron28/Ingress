<?php

namespace App\Http\Controllers\Web;

use App\Actions\ExpireCheckoutReservations;
use App\Http\Controllers\Controller;
use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\EventSimulatedPaymentAudit;
use App\Models\PaymentAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SimulatedPaymentAuthorizationController extends Controller
{
    public function update(Request $request, Event $event, ExpireCheckoutReservations $expiration): RedirectResponse
    {
        Gate::authorize('update', $event);
        abort_unless(config('ingress.mode') === 'cloud', 404);

        $enabled = filter_var(
            $request->validate(['enabled' => ['required', 'boolean']])['enabled'],
            FILTER_VALIDATE_BOOL,
        );

        if ($enabled && ! config('ingress.simulated_payments.enabled')) {
            abort(404);
        }

        DB::transaction(function () use ($request, $event, $enabled, $expiration): void {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $lockedEvent->assertSnapshotNotPrepared();

            $expiration->forLockedEvent($lockedEvent);
            if (! $enabled) {
                $orders = CheckoutOrder::query()->where('event_id', $lockedEvent->id)
                    ->whereIn('status', CheckoutOrder::ACTIVE_RESERVATION_STATUSES)
                    ->lockForUpdate()->get();
                foreach ($orders as $order) {
                    $order->update(['status' => CheckoutOrder::STATUS_CANCELLED]);
                    $order->attempts()->where('status', PaymentAttempt::STATUS_PENDING)->update([
                        'status' => PaymentAttempt::STATUS_CANCELLED,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($lockedEvent->simulated_payments_enabled === $enabled) {
                return;
            }

            $lockedEvent->update(['simulated_payments_enabled' => $enabled]);
            EventSimulatedPaymentAudit::query()->create([
                'event_id' => $lockedEvent->id,
                'actor_user_id' => $request->user()->id,
                'enabled' => $enabled,
                'created_at' => now(),
            ]);
        });

        return redirect()->route('organizer.events.show', $event);
    }
}
