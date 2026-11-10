<?php

namespace App\Http\Controllers\Web;

use App\Actions\RegisterAttendeeForEvent;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Tickets\TicketSessionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function index(): View
    {
        $events = Event::query()
            ->whereIn('status', [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED])
            ->where('ends_at', '>=', now())
            ->with([
                'venue',
                'ticketCategories' => fn ($query) => $query->active()
                    ->withCount(['registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED)]),
            ])
            ->withCount(['registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED)])
            ->orderBy('starts_at')
            ->get();

        return view('public.events.index', compact('events'));
    }

    public function show(Event $event): View
    {
        abort_unless(in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED], true), 404);

        $event->load([
            'venue',
            'ticketCategories' => fn ($query) => $query->active()
                ->withCount(['registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED)]),
        ]);
        $event->loadCount(['registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED)]);

        $remainingPlaces = max(0, $event->capacity - (int) $event->confirmed_registrations_count);
        $hasAvailableCategory = $event->ticketCategories->contains(fn (TicketCategory $category): bool => $category->price_minor === 0 && $category->remainingCapacity() !== 0
        );
        $registrationOpen = $event->isRegistrationOpen() && $remainingPlaces > 0 && $hasAvailableCategory;

        return view('public.events.show', compact('event', 'remainingPlaces', 'registrationOpen', 'hasAvailableCategory'));
    }

    public function register(Event $event, Request $request): View
    {
        abort_unless(in_array($event->status, [Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED], true), 404);

        $categories = $event->ticketCategories()
            ->active()
            ->withCount(['registrations as confirmed_registrations_count' => fn ($registrations) => $registrations->where('status', Registration::STATUS_CONFIRMED)])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $availableCategories = $categories->filter(fn (TicketCategory $category): bool => $category->price_minor === 0 && $category->remainingCapacity() !== 0
        );
        $selectedCategoryId = $request->query('category');
        if (! is_string($selectedCategoryId) || ! ctype_digit($selectedCategoryId)
            || ! $availableCategories->contains(fn (TicketCategory $category) => $category->id === (int) $selectedCategoryId)) {
            $selectedCategoryId = $availableCategories->firstWhere('is_default', true)?->id
                ?? $availableCategories->first()?->id;
        }

        $hasAvailableCategory = $availableCategories->isNotEmpty();

        return view('public.events.register', compact('event', 'categories', 'selectedCategoryId', 'hasAvailableCategory'));
    }

    public function store(
        Request $request,
        Event $event,
        RegisterAttendeeForEvent $registration,
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
