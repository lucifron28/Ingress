<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_assigns_an_existing_user_to_one_event_and_the_change_is_audited(): void
    {
        config(['ingress.mode' => 'cloud']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $manager = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $managedEvent = $this->event($owner, $venue, 'Managed Event', 'managed-event');
        $unrelatedEvent = $this->event($owner, $venue, 'Unrelated Event', 'unrelated-event');

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $managedEvent), ['email' => $manager->email])
            ->assertRedirect(route('organizer.events.show', $managedEvent))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_manager_assignments', [
            'event_id' => $managedEvent->id,
            'user_id' => $manager->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        $this->assertSame(User::ROLE_STUDENT, $manager->fresh()->role);
        $this->assertDatabaseHas('event_responsibility_audits', [
            'event_id' => $managedEvent->id,
            'subject_user_id' => $manager->id,
            'actor_user_id' => $admin->id,
            'action' => 'manager_assigned',
        ]);

        $this->actingAs($admin)
            ->get(route('organizer.events.show', $managedEvent))
            ->assertOk()
            ->assertSee('Event Managers')
            ->assertSee($manager->email)
            ->assertSee('manager assigned')
            ->assertSee('Event Responsibility Audit');

        $index = $this->actingAs($manager)->get(route('organizer.events.index'));
        $index->assertOk()->assertSee('Managed Event')->assertDontSee('Unrelated Event');

        $manager->forceFill(['firebase_uid' => 'firebase-manager-identity', 'email_verified_at' => now()])->save();
        $this->actingAs($manager)
            ->get('/student/dashboard')
            ->assertOk()
            ->assertSee('Manage assigned events')
            ->assertSee(route('organizer.dashboard'));

        $this->get(route('organizer.events.edit', $managedEvent))->assertOk();
        $this->get(route('organizer.events.edit', $unrelatedEvent))->assertForbidden();

        $this->assertSame($owner->id, $managedEvent->fresh()->organizer_id);
        $this->assertDatabaseCount('event_manager_assignments', 1);
        $this->assertDatabaseCount('event_responsibility_audits', 1);
    }

    public function test_platform_admin_can_revoke_a_manager_and_access_is_removed_without_erasing_audit_history(): void
    {
        config(['ingress.mode' => 'cloud']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $manager = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Managed Event', 'managed-event');

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => $manager->email])
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->delete(route('organizer.events.managers.revoke', [$event, $manager]))
            ->assertRedirect(route('organizer.events.show', $event))
            ->assertSessionHas('success');

        $this->actingAs($manager)->get(route('organizer.events.edit', $event))->assertRedirect(route('login'));
        $this->assertDatabaseMissing('event_manager_assignments', ['event_id' => $event->id, 'user_id' => $manager->id]);
        $this->assertDatabaseHas('event_responsibility_audits', [
            'event_id' => $event->id,
            'subject_user_id' => $manager->id,
            'actor_user_id' => $admin->id,
            'action' => 'manager_revoked',
        ]);
        $this->assertDatabaseCount('event_responsibility_audits', 2);
    }

    public function test_event_manager_role_alone_grants_neither_event_access_nor_manager_assignment_authority(): void
    {
        config(['ingress.mode' => 'cloud']);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $manager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Private Event', 'private-event');

        $this->actingAs($manager)->get(route('organizer.events.index'))->assertRedirect(route('login'));

        $this->actingAs($owner)
            ->post(route('organizer.events.managers.store', $event), ['email' => $manager->email])
            ->assertForbidden();

        $this->assertDatabaseCount('event_manager_assignments', 0);
        $this->assertDatabaseCount('event_responsibility_audits', 0);
    }

    public function test_manager_assignment_rejects_an_email_that_matches_multiple_accounts(): void
    {
        config(['ingress.mode' => 'cloud']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        User::factory()->create(['email' => 'duplicate.manager@example.com', 'role' => User::ROLE_STUDENT]);
        User::factory()->create(['email' => 'Duplicate.Manager@example.com', 'role' => User::ROLE_STAFF]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Private Event', 'private-event');

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => 'duplicate.manager@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('event_manager_assignments', 0);
        $this->assertDatabaseCount('event_manager_invitations', 0);
        $this->assertDatabaseCount('event_responsibility_audits', 0);
    }

    public function test_assigned_event_manager_can_sign_in_but_cannot_create_events(): void
    {
        config(['ingress.mode' => 'cloud']);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $manager = User::factory()->create([
            'role' => 'event_manager',
            'password' => bcrypt('manager-password'),
        ]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Managed Event', 'managed-event');

        $event->managerAssignments()->create(['user_id' => $manager->id]);

        $this->post(route('login'), [
            'email' => $manager->email,
            'password' => 'manager-password',
        ])->assertRedirect(route('organizer.dashboard'));

        $this->get(route('organizer.events.index'))->assertOk()->assertSee('Managed Event');
        $this->get(route('organizer.events.create'))->assertForbidden();
        $this->post(route('organizer.events.store'), [
            'venue_id' => $venue->id,
            'title' => 'Unauthorized Event',
            'capacity' => 20,
            'registration_opens_at' => Carbon::now()->toDateTimeString(),
            'registration_closes_at' => Carbon::now()->addDays(2)->toDateTimeString(),
            'starts_at' => Carbon::now()->addDays(3)->toDateTimeString(),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2)->toDateTimeString(),
        ])->assertForbidden();

        $this->assertDatabaseMissing('events', ['title' => 'Unauthorized Event']);
    }

    public function test_event_manager_can_assign_any_account_as_scanner_and_revoke_it_with_audit(): void
    {
        config(['ingress.mode' => 'cloud']);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $manager = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $scanner = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Managed Event', 'managed-event');
        $gate = $event->accessPoints()->create(['name' => 'Main Gate', 'code' => 'MAIN', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => $manager->email])
            ->assertSessionHas('success');

        $this->actingAs($manager)
            ->post(route('organizer.events.staff.assign', $event), [
                'user_id' => $scanner->id,
                'access_point_id' => null,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_staff', [
            'event_id' => $event->id,
            'user_id' => $scanner->id,
            'access_point_id' => null,
        ]);

        $this->actingAs($manager)
            ->post(route('organizer.events.staff.assign', $event), [
                'user_id' => $scanner->id,
                'access_point_id' => $gate->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_staff', [
            'event_id' => $event->id,
            'user_id' => $scanner->id,
            'access_point_id' => $gate->id,
        ]);

        $this->actingAs($manager)
            ->delete(route('organizer.events.staff.revoke', [$event, $scanner]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('event_staff', ['event_id' => $event->id, 'user_id' => $scanner->id]);
        $this->assertDatabaseHas('event_responsibility_audits', [
            'event_id' => $event->id,
            'subject_user_id' => $scanner->id,
            'actor_user_id' => $manager->id,
            'action' => 'scanner_assigned',
            'access_point_id' => null,
        ]);
        $this->assertDatabaseHas('event_responsibility_audits', [
            'event_id' => $event->id,
            'subject_user_id' => $scanner->id,
            'actor_user_id' => $manager->id,
            'action' => 'scanner_gate_changed',
            'access_point_id' => $gate->id,
        ]);
        $this->assertDatabaseHas('event_responsibility_audits', [
            'event_id' => $event->id,
            'subject_user_id' => $scanner->id,
            'actor_user_id' => $manager->id,
            'action' => 'scanner_revoked',
            'access_point_code' => 'MAIN',
        ]);
        $this->assertSame(User::ROLE_STUDENT, $scanner->fresh()->role);
    }

    public function test_unassigned_organizer_cannot_view_another_published_events_management_details(): void
    {
        config(['ingress.mode' => 'cloud']);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $unrelatedOrganizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Published Event', 'published-event');
        $event->forceFill(['status' => Event::STATUS_PUBLISHED])->save();

        $this->actingAs($unrelatedOrganizer)
            ->get(route('organizer.events.show', $event))
            ->assertForbidden();
    }

    public function test_event_overview_shows_section_navigation_and_the_exact_snapshot_blocker(): void
    {
        config(['ingress.mode' => 'cloud']);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Preparation Event', 'preparation-event');

        $this->actingAs($owner)
            ->get(route('organizer.events.show', $event))
            ->assertOk()
            ->assertSee('aria-label="Event sections"', false)
            ->assertSee('href="#ticket-categories"', false)
            ->assertSee('href="#gates-staff"', false)
            ->assertSee('href="#node-handoff"', false)
            ->assertSee('Event preparation')
            ->assertSee('No active gates configured.')
            ->assertSee('No scanner staff assigned.')
            ->assertSee('Only published events can be prepared.')
            ->assertSee('Not closed')
            ->assertSee('Registration is not closed by organizer action or cutoff.')
            ->assertSee('Attendance');
    }

    public function test_published_event_shows_the_registration_blocker_before_snapshot_preparation(): void
    {
        config(['ingress.mode' => 'cloud']);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Open Registration', 'open-registration');
        $event->forceFill(['status' => Event::STATUS_PUBLISHED])->save();

        $this->actingAs($owner)
            ->get(route('organizer.events.show', $event))
            ->assertOk()
            ->assertSee('Close registration before preparing a snapshot.')
            ->assertSee('<p class="event-next-action"><strong>Next step:</strong> Close registration before preparing a snapshot.</p>', false)
            ->assertSee('<button type="submit" class="btn-primary" disabled>Prepare Snapshot</button>', false);
    }

    public function test_event_section_navigation_only_links_to_sections_available_in_this_runtime(): void
    {
        config(['ingress.mode' => 'legacy']);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Local Event', 'local-event');

        $this->actingAs($owner)
            ->get(route('organizer.events.show', $event))
            ->assertOk()
            ->assertSee('href="#gates-staff"', false)
            ->assertDontSee('href="#ticket-categories"', false)
            ->assertDontSee('href="#node-handoff"', false)
            ->assertDontSee('Event preparation');
    }

    public function test_completed_event_is_not_described_as_cancelled_in_readiness(): void
    {
        config(['ingress.mode' => 'cloud']);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = $this->event($owner, $venue, 'Completed Event', 'completed-event');
        $event->forceFill([
            'status' => Event::STATUS_COMPLETED,
            'registration_closes_at' => now()->subDay(),
            'ends_at' => now()->subHour(),
        ])->save();

        $this->actingAs($owner)
            ->get(route('organizer.events.show', $event))
            ->assertOk()
            ->assertSee('This event is complete and cannot be prepared for a new Event Node admission.')
            ->assertDontSee('This event is cancelled. Create a new event if it should run.');
    }

    private function event(User $owner, Venue $venue, string $title, string $slug): Event
    {
        return Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => $title,
            'slug' => $slug,
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_DRAFT,
        ]);
    }
}
