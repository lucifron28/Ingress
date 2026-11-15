<?php

namespace App\Http\Controllers\Web;

use App\Actions\CreateSimulatedCheckout;
use App\Actions\RegisterAttendeeForEvent;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Auth\StudentEmailEligibility;
use App\Services\Tickets\TicketSessionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function index(StudentEmailEligibility $studentEmailEligibility): View
    {
        $events = Event::query()
            ->whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED])
            ->where('ends_at', '>=', now())
            ->with([
                'venue',
                'ticketCategories' => fn ($query) => $query->active()
                    ->withCount([
                        'registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED),
                        'checkoutOrders as active_checkout_orders_count' => fn ($orders) => $orders->activeReservation(),
                    ]),
            ])
            ->withCount([
                'registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED),
                'checkoutOrders as active_checkout_orders_count' => fn ($orders) => $orders->activeReservation(),
            ])
            ->orderBy('starts_at')
            ->get();

        $requiresInstitutionalEmail = $studentEmailEligibility->requiresInstitutionalEmail();
        $institutionalDomain = $studentEmailEligibility->domain();

        return view('public.events.index', compact('events', 'requiresInstitutionalEmail', 'institutionalDomain'));
    }

    public function show(Event $event, StudentEmailEligibility $studentEmailEligibility): View
    {
        abort_unless(in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED], true), 404);

        $event->load([
            'venue',
            'ticketCategories' => fn ($query) => $query->active()
                ->withCount([
                    'registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED),
                    'checkoutOrders as active_checkout_orders_count' => fn ($orders) => $orders->activeReservation(),
                ]),
        ]);
        $event->loadCount([
            'registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED),
            'checkoutOrders as active_checkout_orders_count' => fn ($orders) => $orders->activeReservation(),
        ]);

        $remainingPlaces = max(0, $event->capacity - (int) $event->confirmed_registrations_count - (int) $event->active_checkout_orders_count);
        $hasAvailableCategory = $event->ticketCategories->contains(fn (TicketCategory $category): bool => ($category->price_minor === 0 || $event->canOfferSimulatedPayments()) && $category->remainingCapacity() !== 0
        );
        $registrationOpen = $event->isRegistrationOpen() && $remainingPlaces > 0 && $hasAvailableCategory;

        $requiresInstitutionalEmail = $studentEmailEligibility->requiresInstitutionalEmail();
        $institutionalDomain = $studentEmailEligibility->domain();

        return view('public.events.show', compact('event', 'remainingPlaces', 'registrationOpen', 'hasAvailableCategory', 'requiresInstitutionalEmail', 'institutionalDomain'));
    }

    public function register(Event $event, Request $request, StudentEmailEligibility $studentEmailEligibility): View
    {
        abort_unless(in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED], true), 404);

        $categories = $event->ticketCategories()
            ->active()
            ->withCount([
                'registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED),
                'checkoutOrders as active_checkout_orders_count' => fn ($orders) => $orders->activeReservation(),
            ])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $availableCategories = $categories->filter(fn (TicketCategory $category): bool => ($category->price_minor === 0 || $event->canOfferSimulatedPayments()) && $category->remainingCapacity() !== 0
        );
        $selectedCategoryId = $request->query('category');
        if (! is_string($selectedCategoryId) || ! ctype_digit($selectedCategoryId)
            || ! $availableCategories->contains(fn (TicketCategory $category) => $category->id === (int) $selectedCategoryId)) {
            $selectedCategoryId = $availableCategories->firstWhere('is_default', true)?->id
                ?? $availableCategories->first()?->id;
        }

        $hasAvailableCategory = $availableCategories->isNotEmpty();
        $idempotencyKey = (string) Str::uuid();
        $formKeys = $request->session()->get('public_checkout_form_keys', []);
        $formKeys = is_array($formKeys) ? $formKeys : [];
        $eventKeys = $formKeys[(string) $event->id] ?? [];
        $eventKeys = is_array($eventKeys) ? $eventKeys : [];
        $eventKeys[$idempotencyKey] = true;
        $formKeys[(string) $event->id] = array_slice($eventKeys, -10, null, true);
        $request->session()->put('public_checkout_form_keys', array_slice($formKeys, -10, null, true));

        $requiresInstitutionalEmail = $studentEmailEligibility->requiresInstitutionalEmail();
        $institutionalDomain = $studentEmailEligibility->domain();

        return view('public.events.register', compact('event', 'categories', 'selectedCategoryId', 'hasAvailableCategory', 'idempotencyKey', 'requiresInstitutionalEmail', 'institutionalDomain'));
    }

    public function store(
        Request $request,
        Event $event,
        RegisterAttendeeForEvent $registration,
        CreateSimulatedCheckout $checkout,
        TicketSessionAccess $ticketAccess,
    ): RedirectResponse {
        $this->requireSecureRequest($request);

        if (! $event->isRegistrationOpen()) {
            throw ValidationException::withMessages(['registration' => ['Registration is not open for this event.']]);
        }

        $validated = $request->validate([
            'ticket_category_id' => ['required', 'integer'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);

        $category = $event->ticketCategories()
            ->active()
            ->whereKey($validated['ticket_category_id'])
            ->first();

        if (! $category) {
            throw ValidationException::withMessages(['ticket_category_id' => ['Choose an active ticket category for this event.']]);
        }

        $requiresStudent = $event->registration_eligibility === Event::ELIGIBILITY_STUDENT
            || $category->eligibility === TicketCategory::ELIGIBILITY_STUDENT;
        $user = Auth::user();

        if ($requiresStudent && $user === null) {
            $request->session()->put('public_registration_intent', [
                'event_id' => $event->id,
                'category_id' => $category->id,
            ]);

            return redirect()->route('student.auth');
        }

        if ($category->price_minor > 0) {
            if (! $event->canOfferSimulatedPayments()) {
                throw ValidationException::withMessages(['payment' => ['Simulated paid checkout is not authorized for this event.']]);
            }

            $idempotencyKey = $validated['idempotency_key'] ?? null;
            $formKeys = $request->session()->get('public_checkout_form_keys', []);
            if (! is_string($idempotencyKey)
                || ! is_array($formKeys)
                || ! isset($formKeys[(string) $event->id][$idempotencyKey])) {
                throw ValidationException::withMessages(['idempotency_key' => ['Reload the registration form to start a secure checkout.']]);
            }

            $order = $checkout->execute(
                eventId: $event->id,
                categoryId: $category->id,
                user: $requiresStudent && $user instanceof User ? $user : null,
                guestName: $validated['guest_name'] ?? null,
                guestEmail: $validated['guest_email'] ?? null,
                idempotencyKey: $idempotencyKey,
            );
            $request->session()->put('simulated_checkout_access.'.$order->public_id, true);
            $request->session()->forget('public_registration_intent');

            return redirect()->route('public.checkout.show', $order->public_id);
        }

        $result = $registration->execute(
            eventId: $event->id,
            categoryId: $category->id,
            user: $requiresStudent && $user instanceof User ? $user : null,
            guestName: $validated['guest_name'] ?? null,
            guestEmail: $validated['guest_email'] ?? null,
        );

        $ticketAccess->grantGuest($request, $result['registration'], $result['ticket']);
        if (is_string($result['redemption_code'])) {
            $ticketAccess->grantRedemptionCode($request, $result['registration'], $result['redemption_code']);
        }
        $request->session()->forget('public_registration_intent');

        return redirect()->route('public.tickets.show', $result['registration']);
    }

    private function requireSecureRequest(Request $request): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        abort_unless($request->secure(), 403, 'HTTPS is required.');
    }
}
