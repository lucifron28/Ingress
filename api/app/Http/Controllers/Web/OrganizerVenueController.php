<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Venue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrganizerVenueController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Venue::class);

        $venues = Venue::query()->withCount('events')->orderBy('name')->get();

        return view('organizer.venues.index', compact('venues'));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Venue::class);
        $data = $this->validatedVenue($request);

        try {
            Venue::query()->create($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => ['A venue with this code already exists.']]);
        }

        return redirect()->route('organizer.venues.index')->with('success', 'Venue added.');
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        Gate::authorize('update', $venue);
        $data = $this->validatedVenue($request, $venue);

        try {
            DB::transaction(function () use ($venue, $data): void {
                $events = Event::query()->where('venue_id', $venue->id)
                    ->orderBy('id')->lockForUpdate()->get();

                if ($events->contains(fn (Event $event): bool => $event->snapshot_prepared_at !== null)
                    || ($events->isNotEmpty() && DB::table('cloud_event_authorities')
                        ->whereIn('event_id', $events->modelKeys())->exists())) {
                    throw ValidationException::withMessages([
                        'venue' => ['Venue details are locked while a linked event has a prepared snapshot or transferred authority.'],
                    ]);
                }

                $lockedVenue = Venue::query()->whereKey($venue->getKey())->lockForUpdate()->firstOrFail();
                $lockedVenue->update($data);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => ['A venue with this code already exists.']]);
        }

        return redirect()->route('organizer.venues.index')->with('success', 'Venue updated.');
    }

    /** @return array{name: string, code: string, location: ?string, default_capacity: int} */
    private function validatedVenue(Request $request, ?Venue $venue = null): array
    {
        $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        $codeRule = Rule::unique('venues', 'code');
        if ($venue) {
            $codeRule->ignore($venue->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'alpha_dash', 'max:32', $codeRule],
            'location' => ['nullable', 'string', 'max:255'],
            'default_capacity' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ]);
    }
}
