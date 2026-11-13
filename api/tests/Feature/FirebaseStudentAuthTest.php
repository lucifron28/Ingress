<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRuntimeDatabase;
use App\Models\Event;
use App\Models\EventManagerInvitation;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirebaseStudentAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ingress.mode' => 'cloud',
            'app.url' => 'https://ingress.example.test',
            'services.firebase.api_key' => 'public-test-api-key',
            'services.firebase.auth_domain' => 'ingress-test.firebaseapp.com',
            'services.firebase.project_id' => 'ingress-test',
            'services.firebase.app_id' => '1:123:web:test',
            'services.firebase.student_email_domain' => 'student.mseuf.edu.ph',
        ]);
    }

    public function test_verified_institutional_firebase_identity_creates_and_authenticates_a_student(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-student-123',
                    'email' => 'Student@student.mseuf.edu.ph',
                    'emailVerified' => true,
                    'displayName' => 'Verified Student',
                    'disabled' => false,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]),
        ]);

        $response = $this->postJson('/student/session', ['id_token' => 'firebase-id-token']);

        $response->assertOk()
            ->assertJsonPath('redirect', route('student.dashboard', absolute: false))
            ->assertDontSee('firebase-id-token');
        $student = User::where('email', 'student@student.mseuf.edu.ph')->firstOrFail();
        $this->assertSame('firebase-student-123', $student->firebase_uid);
        $this->assertSame(User::ROLE_STUDENT, $student->role);
        $this->assertSame('Verified Student', $student->name);
        $this->assertNull($student->student_id);
        $this->assertNotNull($student->email_verified_at);
        $this->assertAuthenticatedAs($student, 'web');
        $this->assertFalse(str_contains($response->getContent(), 'firebase_uid'));

        $this->get('/student/dashboard')->assertOk()->assertSee('Your student account is ready');

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?')
                && str_contains($request->url(), 'key=public-test-api-key')
                && $request['idToken'] === 'firebase-id-token';
        });

        $this->post('/student/logout')->assertRedirect(route('student.auth'));
        $this->assertGuest('web');
        $this->get('/student/dashboard')->assertRedirect(route('login'));
    }

    public function test_verified_personal_email_firebase_identity_creates_and_authenticates_a_student(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-personal-student-123',
                    'email' => 'student.presentation@gmail.com',
                    'emailVerified' => true,
                    'displayName' => 'Presentation Student',
                    'disabled' => false,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]),
        ]);

        $this->postJson('/student/session', ['id_token' => 'firebase-personal-id-token'])
            ->assertOk()
            ->assertJsonPath('redirect', route('student.dashboard', absolute: false))
            ->assertDontSee('firebase-personal-id-token');

        $student = User::where('email', 'student.presentation@gmail.com')->firstOrFail();
        $this->assertSame('firebase-personal-student-123', $student->firebase_uid);
        $this->assertSame(User::ROLE_STUDENT, $student->role);
        $this->assertNotNull($student->email_verified_at);
        $this->assertAuthenticatedAs($student, 'web');
    }

    public function test_verified_firebase_identity_without_disabled_field_can_sign_in(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-student-omitted-disabled',
                    'email' => 'verified.demo@gmail.com',
                    'emailVerified' => true,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]),
        ]);

        $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertOk()
            ->assertJsonPath('redirect', route('student.dashboard', absolute: false));

        $student = User::where('email', 'verified.demo@gmail.com')->firstOrFail();
        $this->assertSame('firebase-student-omitted-disabled', $student->firebase_uid);
        $this->assertAuthenticatedAs($student, 'web');
    }

    public function test_existing_student_can_link_only_the_matching_verified_firebase_identity(): void
    {
        $student = User::factory()->create([
            'email' => 'student@student.mseuf.edu.ph',
            'student_id' => 'A23-12345',
            'password' => 'existing-local-password',
            'email_verified_at' => null,
        ]);
        $passwordHash = $student->password;

        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-student-123',
                    'email' => $student->email,
                    'emailVerified' => true,
                    'displayName' => 'Firebase Profile Name',
                    'disabled' => false,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]),
        ]);

        $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])->assertOk();

        $student->refresh();
        $this->assertSame('firebase-student-123', $student->firebase_uid);
        $this->assertSame('A23-12345', $student->student_id);
        $this->assertSame($passwordHash, $student->password);
        $this->assertTrue(Hash::check('existing-local-password', $student->password));
        $this->assertAuthenticatedAs($student, 'web');
    }

    public function test_firebase_sign_in_returns_to_a_pending_event_manager_invitation(): void
    {
        $student = User::factory()->create([
            'email' => 'student@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-student-123',
            'role' => User::ROLE_STUDENT,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $event = Event::create([
            'organizer_id' => $admin->id,
            'venue_id' => $venue->id,
            'title' => 'Invited Event',
            'slug' => 'invited-event',
            'capacity' => 20,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(2),
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);
        $invitation = EventManagerInvitation::create([
            'event_id' => $event->id,
            'email' => $student->email,
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'invited_by_user_id' => $admin->id,
            'expires_at' => now()->addHours(48),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-student-123',
                    'email' => $student->email,
                    'emailVerified' => true,
                    'displayName' => 'Verified Student',
                    'disabled' => false,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]),
        ]);

        $this->withSession(['event_manager_invitation_id' => $invitation->id])
            ->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertOk()
            ->assertJsonPath('redirect', route('event-manager-invitation.show', absolute: false));

        $this->assertAuthenticatedAs($student, 'web');
    }

    public function test_unverified_malformed_email_disabled_and_non_password_identities_are_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence('identitytoolkit.googleapis.com/v1/accounts:lookup*')
            ->push(['users' => [[
                'localId' => 'unverified-user',
                'email' => 'student@student.mseuf.edu.ph',
                'emailVerified' => false,
                'disabled' => false,
                'providerUserInfo' => [['providerId' => 'password']],
            ]]])
            ->push(['users' => [[
                'localId' => 'malformed-email-user',
                'email' => 'not-an-email',
                'emailVerified' => true,
                'disabled' => false,
                'providerUserInfo' => [['providerId' => 'password']],
            ]]])
            ->push(['users' => [[
                'localId' => 'disabled-user',
                'email' => 'student@student.mseuf.edu.ph',
                'emailVerified' => true,
                'disabled' => true,
                'providerUserInfo' => [['providerId' => 'password']],
            ]]])
            ->push(['users' => [[
                'localId' => 'non-password-user',
                'email' => 'student@student.mseuf.edu.ph',
                'emailVerified' => true,
                'disabled' => false,
                'providerUserInfo' => [['providerId' => 'google.com']],
            ]]]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])
                ->assertUnauthorized()
                ->assertDontSee('firebase-id-token');
            $this->assertGuest('web');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_firebase_identity_cannot_claim_an_existing_privileged_user(): void
    {
        $users = collect([User::ROLE_ADMIN, User::ROLE_ORGANIZER, User::ROLE_STAFF])
            ->map(fn (string $role, int $index) => User::factory()->create([
                'email' => 'privileged'.$index.'@student.mseuf.edu.ph',
                'role' => $role,
            ]));

        Http::preventStrayRequests();
        $sequence = Http::sequence();
        foreach ($users as $user) {
            $sequence->push([
                'users' => [[
                    'localId' => 'firebase-'.$user->id,
                    'email' => $user->email,
                    'emailVerified' => true,
                    'disabled' => false,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]);
        }
        Http::fake(['identitytoolkit.googleapis.com/v1/accounts:lookup*' => $sequence]);

        foreach ($users as $user) {
            $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])
                ->assertForbidden()
                ->assertDontSee('firebase-id-token');
            $this->assertGuest('web');
            $this->assertNull($user->fresh()->firebase_uid);
        }

        $this->assertDatabaseCount('users', 3);
    }

    public function test_uid_already_linked_to_another_email_cannot_create_or_take_over_an_account(): void
    {
        $existing = User::factory()->create([
            'email' => 'other@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-student-123',
        ]);
        $emailOwner = User::factory()->create([
            'email' => 'student@student.mseuf.edu.ph',
            'firebase_uid' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-student-123',
                    'email' => 'student@student.mseuf.edu.ph',
                    'emailVerified' => true,
                    'disabled' => false,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]),
        ]);

        $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertForbidden()
            ->assertDontSee('firebase-id-token');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('firebase-student-123', $existing->fresh()->firebase_uid);
        $this->assertNull($emailOwner->fresh()->firebase_uid);
    }

    public function test_conflicting_authenticated_session_must_be_logged_out_before_student_sign_in(): void
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);

        Http::preventStrayRequests();
        $this->actingAs($organizer, 'web')
            ->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertConflict();

        Http::assertNothingSent();
        $this->assertAuthenticatedAs($organizer, 'web');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_student_firebase_auth_is_unavailable_on_the_event_node_runtime(): void
    {
        Http::preventStrayRequests();
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'venue-node-1']);

        $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertServiceUnavailable();

        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_student_firebase_auth_refuses_plain_http_in_production(): void
    {
        $this->withoutMiddleware(EnsureRuntimeDatabase::class);
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.url' => 'https://ingress.example.test']);
        Http::preventStrayRequests();

        $this->get('http://ingress.example.test/student/auth')->assertServiceUnavailable();
        $this->withSession(['_token' => 'known-test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'known-test-csrf-token')
            ->postJson('http://ingress.example.test/student/session', ['id_token' => 'firebase-id-token'])
            ->assertServiceUnavailable();

        Http::assertNothingSent();
    }

    public function test_missing_firebase_configuration_or_identity_service_failure_does_not_authenticate(): void
    {
        Http::preventStrayRequests();
        config(['services.firebase.api_key' => null]);

        $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertServiceUnavailable()
            ->assertDontSee('firebase-id-token');
        Http::assertNothingSent();

        config(['services.firebase.api_key' => 'public-test-api-key']);
        Http::fake(['identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([], 503)]);

        $this->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertServiceUnavailable()
            ->assertDontSee('firebase-id-token');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_malformed_session_token_is_not_echoed_or_cached(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/student/session', ['id_token' => ['sensitive-id-token']])
            ->assertUnprocessable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('sensitive-id-token');

        Http::assertNothingSent();
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_student_auth_page_is_no_store_and_uses_server_supplied_public_firebase_configuration(): void
    {
        $this->withoutVite();

        $response = $this->get('/student/auth');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('Create your student account')
            ->assertSee('Use a personal or university email address')
            ->assertSee('Sign in with your email')
            ->assertDontSee('student.mseuf.edu.ph')
            ->assertSee('data-firebase-config=', false)
            ->assertSee('ingress-test.firebaseapp.com')
            ->assertSee('public-test-api-key');

        $this->assertSame(3, substr_count($response->getContent(), 'method="post"'));
        $response->assertSee('JavaScript is required for secure Firebase student sign-in.');
    }

    public function test_legacy_student_accounts_without_firebase_verification_cannot_use_the_student_dashboard(): void
    {
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'firebase_uid' => null,
            'email_verified_at' => null,
        ]);

        $this->actingAs($student, 'web')
            ->get('/student/dashboard')
            ->assertForbidden();
    }

    public function test_firebase_session_exchange_is_rate_limited_per_requester(): void
    {
        Http::preventStrayRequests();
        Http::fake(['identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([], 401)]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/student/session', ['id_token' => 'invalid-token'])->assertUnauthorized();
        }

        $this->postJson('/student/session', ['id_token' => 'invalid-token'])->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
    }
}
