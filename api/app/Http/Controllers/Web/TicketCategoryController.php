<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\TicketCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketCategoryController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('update', $event);
        abort_unless(config('ingress.mode') === 'cloud', 404);
        $event->assertSnapshotNotPrepared();

        $data = $this->validatedCategory($request, $event);

        try {
            DB::transaction(function () use ($event, $data): void {
                $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $lockedEvent->assertSnapshotNotPrepared();
                $this->assertCapacityWithinEvent($data['capacity'], $lockedEvent);

                $isDefault = ! $lockedEvent->ticketCategories()->where('is_default', true)->exists();
                $lockedEvent->ticketCategories()->create([
                    'name' => $data['name'],
                    'price_minor' => $this->toMinorUnits($data['price_pesos']),
                    'currency' => 'PHP',
                    'capacity' => $data['capacity'],
                    'eligibility' => $data['eligibility'],
                    'is_active' => true,
                    'is_default' => $isDefault,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A category with this name already exists for the event.']]);
        }

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Ticket category added. Paid checkout remains unavailable until the simulated payment phase is enabled.');
    }

    public function update(Request $request, Event $event, TicketCategory $ticketCategory): RedirectResponse
    {
        Gate::authorize('update', $event);
        abort_unless(config('ingress.mode') === 'cloud', 404);
        $event->assertSnapshotNotPrepared();

        $ticketCategory = $event->ticketCategories()->whereKey($ticketCategory->id)->firstOrFail();
        $data = $this->validatedCategory($request, $event, $ticketCategory);

        try {
            DB::transaction(function () use ($event, $ticketCategory, $data): void {
                $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $lockedEvent->assertSnapshotNotPrepared();
                $lockedCategory = TicketCategory::query()
                    ->where('event_id', $lockedEvent->id)
                    ->whereKey($ticketCategory->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (Registration::query()->where('ticket_category_id', $lockedCategory->id)->exists()) {
                    throw ValidationException::withMessages([
                        'category' => ['A category cannot be changed after a registration has been issued. Add a new category instead.'],
                    ]);
                }

                $this->assertCapacityWithinEvent($data['capacity'], $lockedEvent);
                $lockedCategory->update([
                    'name' => $data['name'],
                    'price_minor' => $this->toMinorUnits($data['price_pesos']),
                    'capacity' => $data['capacity'],
                    'eligibility' => $data['eligibility'],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['A category with this name already exists for the event.']]);
        }

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Ticket category updated.');
    }

    /** @return array{name: string, price_pesos: string, capacity: ?int, eligibility: string} */
    private function validatedCategory(Request $request, Event $event, ?TicketCategory $category = null): array
    {
        $nameRule = Rule::unique('ticket_categories', 'name')->where('event_id', $event->id);
        if ($category) {
            $nameRule->ignore($category->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:100', $nameRule],
            'price_pesos' => ['required', 'numeric', 'min:0', 'max:1000000', 'regex:/\A\d+(?:\.\d{1,2})?\z/D'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:'.$event->capacity],
            'eligibility' => ['required', Rule::in([TicketCategory::ELIGIBILITY_PUBLIC, TicketCategory::ELIGIBILITY_STUDENT])],
        ]);
    }

    private function assertCapacityWithinEvent(?int $capacity, Event $event): void
    {
        if ($capacity !== null && $capacity > $event->capacity) {
            throw ValidationException::withMessages(['capacity' => ['Category capacity cannot exceed event capacity.']]);
        }
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
