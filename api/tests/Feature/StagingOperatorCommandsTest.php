<?php

namespace Tests\Feature;

use App\Mail\IngressMailConnectivityTest;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Support\CloudOperatorDatabase;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StagingOperatorCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_account_command_requires_explicit_confirmation(): void
    {
        $this->artisan('ingress:provision-staging-account', ['role' => 'organizer'])
            ->expectsOutputToContain('Pass --confirm-staging to create a synthetic staging account.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_reference_venue_command_seeds_only_idempotent_venues(): void
    {
        $this->useTestCloudDatabase();

        $this->artisan('ingress:seed-cloud-reference-venues', ['--confirm-staging' => true])
            ->expectsOutputToContain('Cloud reference venues are ready.')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, Venue::count());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('events', 0);

        $this->artisan('ingress:seed-cloud-reference-venues', ['--confirm-staging' => true])
            ->expectsOutputToContain('Cloud reference venues are ready.')
            ->assertExitCode(0);

        $this->assertSame(4, Venue::count());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_staging_account_command_never_creates_admin_accounts(): void
    {
        $this->artisan('ingress:provision-staging-account', [
            'role' => 'admin',
            '--confirm-staging' => true,
        ])
            ->expectsOutputToContain('Choose organizer, staff, or student. Administrator accounts use ingress:create-admin.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_staging_account_command_creates_synthetic_accounts_idempotently(): void
    {
        $this->useTestCloudDatabase();

        $this->artisan('ingress:provision-staging-account', [
            'role' => 'organizer',
            '--confirm-staging' => true,
        ])
            ->expectsQuestion('Staging account name', 'Ingress Test Organizer')
            ->expectsQuestion('Synthetic email ending in @example.test', 'ingress-organizer@example.test')
            ->expectsQuestion('Password, at least 12 characters', 'temporary-staging-password')
            ->expectsQuestion('Confirm password', 'temporary-staging-password')
            ->expectsOutputToContain('Synthetic staging organizer account created.')
            ->assertExitCode(0);

        $organizer = User::where('email', 'ingress-organizer@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_ORGANIZER, $organizer->role);
        $this->assertNull($organizer->email_verified_at);
        $this->assertTrue(Hash::check('temporary-staging-password', $organizer->password));

        $this->artisan('ingress:provision-staging-account', [
            'role' => 'organizer',
            '--confirm-staging' => true,
        ])
            ->expectsQuestion('Staging account name', 'Changed Name Must Be Ignored')
            ->expectsQuestion('Synthetic email ending in @example.test', 'INGRESS-ORGANIZER@example.test')
            ->expectsQuestion('Password, at least 12 characters', 'replacement-staging-password')
            ->expectsQuestion('Confirm password', 'replacement-staging-password')
            ->expectsOutputToContain('That synthetic account already exists with the same role; no changes were made.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Ingress Test Organizer', $organizer->fresh()->name);
        $this->assertTrue(Hash::check('temporary-staging-password', $organizer->fresh()->password));
    }

    public function test_staging_student_requires_a_synthetic_student_id_and_remains_unverified(): void
    {
        $this->useTestCloudDatabase();

        $this->artisan('ingress:provision-staging-account', [
            'role' => 'student',
            '--confirm-staging' => true,
        ])
            ->expectsQuestion('Staging account name', 'Ingress Test Student')
            ->expectsQuestion('Synthetic email ending in @example.test', 'ingress-student@example.test')
            ->expectsQuestion('Synthetic student ID beginning with STAGE-', 'STAGE-001')
            ->expectsQuestion('Password, at least 12 characters', 'temporary-staging-password')
            ->expectsQuestion('Confirm password', 'temporary-staging-password')
            ->expectsOutputToContain('Synthetic staging student account created.')
            ->assertExitCode(0);

        $student = User::where('email', 'ingress-student@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_STUDENT, $student->role);
        $this->assertSame('STAGE-001', $student->student_id);
        $this->assertNull($student->email_verified_at);
    }

    public function test_unverified_synthetic_student_can_register_for_a_staging_event(): void
    {
        $this->useTestCloudDatabase();

        $this->artisan('ingress:provision-staging-account', [
            'role' => 'student',
            '--confirm-staging' => true,
        ])
            ->expectsQuestion('Staging account name', 'Ingress Test Student')
            ->expectsQuestion('Synthetic email ending in @example.test', 'ingress-student@example.test')
            ->expectsQuestion('Synthetic student ID beginning with STAGE-', 'STAGE-002')
            ->expectsQuestion('Password, at least 12 characters', 'temporary-staging-password')
            ->expectsQuestion('Confirm password', 'temporary-staging-password')
            ->assertExitCode(0);

        $student = User::where('email', 'ingress-student@example.test')->firstOrFail();
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create([
            'name' => 'Staging Hall',
            'code' => 'STAGE-HALL',
            'location' => 'Staging campus',
            'default_capacity' => 10,
        ]);
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $venue->id,
            'title' => 'Synthetic Staging Event',
            'slug' => 'synthetic-staging-event',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now()->subHour(),
            'registration_closes_at' => Carbon::now()->addDay(),
            'starts_at' => Carbon::now()->addDays(2),
            'ends_at' => Carbon::now()->addDays(2)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'temporary-staging-password',
            'device_name' => 'StagingTestPhone',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_STUDENT);

        $this->withHeader('Authorization', 'Bearer '.$login->json('token'))
            ->postJson("/api/v1/events/{$event->id}/registration")
            ->assertCreated()
            ->assertJsonPath('message', 'Registration confirmed and ticket issued.');

        $this->assertNull($student->fresh()->email_verified_at);
        $this->assertDatabaseHas('registrations', [
            'event_id' => $event->id,
            'user_id' => $student->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_staging_account_command_rejects_non_synthetic_email_domains(): void
    {
        $this->useTestCloudDatabase();

        $this->artisan('ingress:provision-staging-account', [
            'role' => 'staff',
            '--confirm-staging' => true,
        ])
            ->expectsQuestion('Staging account name', 'Ingress Test Staff')
            ->expectsQuestion('Synthetic email ending in @example.test', 'staff@example.com')
            ->expectsQuestion('Password, at least 12 characters', 'temporary-staging-password')
            ->expectsQuestion('Confirm password', 'temporary-staging-password')
            ->expectsOutputToContain('Use a valid name, an @example.test email')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_mail_test_requires_an_explicit_configured_recipient(): void
    {
        $this->useTestCloudDatabase();
        $this->configureResendTest(recipient: '');
        Mail::fake();

        $this->artisan('ingress:send-mail-test')
            ->expectsOutputToContain('Set INGRESS_MAIL_TEST_RECIPIENT to the authorized test inbox before sending.')
            ->assertExitCode(1);

        Mail::assertNothingOutgoing();
    }

    public function test_mail_test_sends_only_a_static_connectivity_message_to_the_configured_inbox(): void
    {
        $this->useTestCloudDatabase();
        $this->configureResendTest(recipient: 'lucifron28@gmail.com');
        Mail::fake();

        $this->artisan('ingress:send-mail-test')
            ->expectsOutputToContain('Resend accepted the connectivity test request for delivery.')
            ->assertExitCode(0);

        Mail::assertSent(IngressMailConnectivityTest::class, function (IngressMailConnectivityTest $mail): bool {
            return $mail->hasTo('lucifron28@gmail.com')
                && $mail->envelope()->subject === 'Ingress email connectivity test';
        });
    }

    public function test_mail_test_does_not_print_provider_exception_details(): void
    {
        $this->useTestCloudDatabase();
        $this->configureResendTest(recipient: 'lucifron28@gmail.com');
        Mail::shouldReceive('to')
            ->once()
            ->with('lucifron28@gmail.com')
            ->andThrow(new RuntimeException('re_secret_key verification-token-example'));

        $this->artisan('ingress:send-mail-test')
            ->expectsOutputToContain('Resend could not accept the connectivity test.')
            ->assertExitCode(1);

        $output = Artisan::output();
        $this->assertStringNotContainsString('re_secret_key', $output);
        $this->assertStringNotContainsString('verification-token-example', $output);
    }

    private function useTestCloudDatabase(): void
    {
        $database = Mockery::mock(CloudOperatorDatabase::class);
        $database->shouldReceive('configurationErrors')->andReturn([]);
        $database->shouldReceive('connection')->andReturn(DB::connection());
        $database->shouldReceive('runLocked')->zeroOrMoreTimes()->andReturnUsing(
            function (Connection $connection, callable $operation): bool {
                $operation();

                return true;
            },
        );
        $this->app->instance(CloudOperatorDatabase::class, $database);
    }

    private function configureResendTest(string $recipient): void
    {
        config([
            'app.url' => 'https://ingress-pp2u.onrender.com',
            'ingress.mail_test_recipient' => $recipient,
            'mail.default' => 'resend',
            'mail.from.address' => 'ingress-test@example.test',
            'services.resend.key' => 're_test_key',
        ]);
    }
}
