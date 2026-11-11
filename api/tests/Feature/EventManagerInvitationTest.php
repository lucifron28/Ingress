<?php

namespace Tests\Feature;

use App\Mail\EventManagerInvitationMail;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventManagerInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_invites_a_new_manager_with_a_fragment_token_and_the_account_is_created_on_acceptance(): void
    {
        config([
            'ingress.mode' => 'cloud',
            'app.url' => 'https://ingress.example.test',
        ]);
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Faculty Workshop',
            'slug' => 'faculty-workshop',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => 'new.manager@mseuf.edu.ph'])
            ->assertRedirect(route('organizer.events.show', $event))
            ->assertSessionHas('success');

        $mail = Mail::sent(EventManagerInvitationMail::class)->last();
        $this->assertNotNull($mail);
        $this->assertSame('new.manager@mseuf.edu.ph', $mail->recipientEmail);
        $this->assertStringStartsWith('https://ingress.example.test/event-manager-invitation#', $mail->invitationUrl);
        $token = parse_url($mail->invitationUrl, PHP_URL_FRAGMENT);
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);

        $this->assertDatabaseHas('event_manager_invitations', [
            'event_id' => $event->id,
            'email' => 'new.manager@mseuf.edu.ph',
            'token_hash' => hash('sha256', $token),
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'new.manager@mseuf.edu.ph']);
        $this->assertDatabaseHas('event_responsibility_audits', [
            'event_id' => $event->id,
            'subject_email' => 'new.manager@mseuf.edu.ph',
            'actor_user_id' => $admin->id,
            'action' => 'manager_invited',
        ]);

        $this->post(route('logout'));
        $this->get(route('event-manager-invitation.show'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee($token);

        $this->postJson(route('event-manager-invitations.preview'), ['token' => $token])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('invitation.email', 'new.manager@mseuf.edu.ph')
            ->assertJsonPath('invitation.event_title', 'Faculty Workshop')
            ->assertJsonPath('invitation.requires_account_creation', true)
            ->assertSessionHas('event_manager_invitation_id')
            ->assertDontSee($token);

        $this->postJson(route('event-manager-invitations.accept'), [
            'name' => 'New Faculty Manager',
            'password' => 'a-strong-test-only-password-123',
            'password_confirmation' => 'a-strong-test-only-password-123',
        ])->assertOk()->assertJsonPath('redirect', route('organizer.dashboard', absolute: false));

        $manager = User::query()->where('email', 'new.manager@mseuf.edu.ph')->firstOrFail();
        $this->assertSame(User::ROLE_EVENT_MANAGER, $manager->role);
        $this->assertNotNull($manager->email_verified_at);
        $this->assertAuthenticatedAs($manager, 'web');
        $this->assertDatabaseHas('event_manager_assignments', [
            'event_id' => $event->id,
            'user_id' => $manager->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('event_responsibility_audits', [
            'event_id' => $event->id,
            'subject_user_id' => $manager->id,
            'actor_user_id' => $admin->id,
            'action' => 'manager_assigned',
        ]);

        $this->postJson(route('event-manager-invitations.preview'), ['token' => $token])->assertNotFound();
        $this->get(route('organizer.events.index'))->assertOk()->assertSee('Faculty Workshop');
        $this->get(route('organizer.events.create'))->assertForbidden();
    }

    public function test_invalid_invitation_tokens_are_not_echoed_or_cached(): void
    {
        config(['ingress.mode' => 'cloud']);

        $this->postJson(route('event-manager-invitations.preview'), ['token' => 'sensitive-invalid-token'])
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('sensitive-invalid-token');
    }

    public function test_manager_invitation_is_not_created_or_sent_through_a_logging_mailer(): void
    {
        config(['mail.default' => 'log', 'ingress.mode' => 'cloud']);
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Faculty Workshop',
            'slug' => 'faculty-workshop',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => 'new.manager@mseuf.edu.ph'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('event_manager_invitations', 0);
    }

    public function test_manager_invitation_fails_closed_when_resend_has_no_api_key(): void
    {
        config([
            'ingress.mode' => 'cloud',
            'app.url' => 'https://ingress.example.test',
            'mail.default' => 'resend',
            'services.resend.key' => '',
        ]);
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Faculty Workshop',
            'slug' => 'faculty-workshop-no-mail',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => 'new.manager@mseuf.edu.ph'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('event_manager_invitations', 0);
    }

    public function test_invitation_for_an_existing_account_requires_that_account_to_be_authenticated(): void
    {
        config([
            'ingress.mode' => 'cloud',
            'app.url' => 'https://ingress.example.test',
        ]);
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Faculty Workshop',
            'slug' => 'faculty-workshop',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => 'existing.student@mseuf.edu.ph'])
            ->assertSessionHas('success');
        $mail = Mail::sent(EventManagerInvitationMail::class)->last();
        $this->assertNotNull($mail);
        $token = parse_url($mail->invitationUrl, PHP_URL_FRAGMENT);

        $this->post(route('logout'));
        $existingStudent = User::factory()->create([
            'email' => 'existing.student@mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
            'firebase_uid' => 'firebase-existing-student',
        ]);

        $this->postJson(route('event-manager-invitations.preview'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('invitation.requires_account_creation', false)
            ->assertJsonPath('invitation.sign_in_url', route('student.auth', absolute: false));

        $this->postJson(route('event-manager-invitations.accept'), [])->assertConflict();
        $this->assertDatabaseMissing('event_manager_assignments', [
            'event_id' => $event->id,
            'user_id' => $existingStudent->id,
        ]);

        $this->actingAs($existingStudent)
            ->postJson(route('event-manager-invitations.accept'), [])
            ->assertOk();

        $this->assertDatabaseHas('event_manager_assignments', [
            'event_id' => $event->id,
            'user_id' => $existingStudent->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        $this->assertSame(User::ROLE_STUDENT, $existingStudent->fresh()->role);
    }

    public function test_expired_manager_invitation_cannot_be_previewed_or_accepted(): void
    {
        config(['ingress.mode' => 'cloud', 'app.url' => 'https://ingress.example.test']);
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Faculty Workshop',
            'slug' => 'faculty-workshop',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => 'expired.manager@mseuf.edu.ph'])
            ->assertSessionHas('success');
        $mail = Mail::sent(EventManagerInvitationMail::class)->last();
        $this->assertNotNull($mail);
        $token = parse_url($mail->invitationUrl, PHP_URL_FRAGMENT);

        $this->post(route('logout'));
        $this->travel(49)->hours();

        $this->postJson(route('event-manager-invitations.preview'), ['token' => $token])
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('event_manager_assignments', 0);
    }

    public function test_invitation_allows_an_existing_staff_account_to_sign_in_and_accept_without_role_change(): void
    {
        config([
            'ingress.mode' => 'cloud',
            'app.url' => 'https://ingress.example.test',
        ]);
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Faculty Workshop',
            'slug' => 'faculty-workshop',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($admin)
            ->post(route('organizer.events.managers.store', $event), ['email' => 'existing.staff@mseuf.edu.ph'])
            ->assertSessionHas('success');
        $mail = Mail::sent(EventManagerInvitationMail::class)->last();
        $this->assertNotNull($mail);
        $token = parse_url($mail->invitationUrl, PHP_URL_FRAGMENT);
        $this->post(route('logout'));

        $existingStaff = User::factory()->create([
            'email' => 'existing.staff@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
            'password' => bcrypt('staff-manager-password'),
            'email_verified_at' => null,
        ]);

        $this->postJson(route('event-manager-invitations.preview'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('invitation.requires_account_creation', false)
            ->assertJsonPath('invitation.sign_in_url', route('login', absolute: false));

        $this->post(route('login'), [
            'email' => $existingStaff->email,
            'password' => 'staff-manager-password',
        ])->assertRedirect(route('event-manager-invitation.show'));

        $this->postJson(route('event-manager-invitations.accept'), [])->assertOk();

        $this->assertDatabaseHas('event_manager_assignments', [
            'event_id' => $event->id,
            'user_id' => $existingStaff->id,
        ]);
        $this->assertSame(User::ROLE_STAFF, $existingStaff->fresh()->role);
        $this->assertNotNull($existingStaff->fresh()->email_verified_at);
    }
}
