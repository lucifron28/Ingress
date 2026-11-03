<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRuntimeDatabase;
use App\Mail\StudentVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudentEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ingress.mode' => 'cloud', 'app.url' => 'https://ingress.example.test']);
        Mail::fake();
    }

    private function requestToken(string $email = 'student@student.mseuf.edu.ph'): string
    {
        $this->postJson('/api/v1/auth/student-verification', ['email' => $email])->assertStatus(202);
        $mail = Mail::sent(StudentVerificationMail::class)->last();
        $this->assertNotNull($mail);
        $this->assertStringStartsWith('https://ingress.example.test/student/complete#', $mail->verificationUrl);
        $this->assertStringContainsString($mail->verificationUrl, $mail->render());

        return substr($mail->verificationUrl, -64);
    }

    private function account(string $token): array
    {
        return ['token' => $token, 'name' => 'Student Name', 'student_id' => 'A23-12345',
            'password' => 'a long secure password', 'password_confirmation' => 'a long secure password'];
    }

    public function test_institutional_email_proof_creates_one_student_account(): void
    {
        $token = $this->requestToken(' Student@Student.Mseuf.Edu.Ph ');
        $record = DB::table('student_email_verifications')->first();
        $this->assertSame('student@student.mseuf.edu.ph', $record->email);
        $this->assertSame(hash('sha256', $token), $record->token_hash);
        $this->assertNotSame($token, $record->token_hash);
        $this->assertDatabaseCount('users', 0);
        $this->get('/student/complete')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDatabaseCount('users', 0);
        $this->post('/student/complete', $this->account($token) + ['role' => 'admin'])->assertOk()->assertSee('Account created');
        $user = User::where('email', 'student@student.mseuf.edu.ph')->firstOrFail();
        $this->assertSame(User::ROLE_STUDENT, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a long secure password', $user->password));
        $this->assertDatabaseCount('student_email_verifications', 0);
        $this->post('/student/complete', $this->account($token))->assertStatus(422);
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a long secure password'])->assertOk()->assertJsonPath('user.role', 'student');
    }

    public function test_wrong_domain_invalid_token_and_expiry_cannot_create_accounts(): void
    {
        foreach (['student@other.mseuf.edu.ph', 'student@student.mseuf.edu.ph.evil.test', 'student@example.com'] as $email) {
            $this->postJson('/api/v1/auth/student-verification', ['email' => $email])->assertStatus(422);
        }
        Mail::assertNothingSent();
        $token = $this->requestToken();
        $this->post('/student/complete', $this->account(str_repeat('a', 64)))->assertStatus(422);
        $this->travel(31)->minutes();
        $this->post('/student/complete', $this->account($token))->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_new_link_invalidates_old_link_without_revealing_existing_accounts(): void
    {
        $first = $this->requestToken();
        $second = $this->requestToken();
        $this->assertNotSame($first, $second);
        $this->post('/student/complete', $this->account($first))->assertStatus(422);
        $this->post('/student/complete', $this->account($second))->assertOk();
        Mail::fake();
        $response = $this->postJson('/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(202);
        $response->assertJsonPath('message', 'If the address is eligible, check your inbox for a verification link.');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('student_email_verifications', 0);
    }

    public function test_validation_does_not_consume_token_and_never_flashes_password(): void
    {
        $token = $this->requestToken();
        $bad = $this->account($token);
        $bad['password_confirmation'] = 'wrong';
        $this->post('/student/complete', $bad)->assertStatus(422)->assertDontSee('a long secure password');
        $this->assertDatabaseCount('student_email_verifications', 1);
        $this->post('/student/complete', $this->account($token))->assertOk();
    }

    public function test_malformed_and_overlong_passwords_do_not_crash_or_consume_a_link(): void
    {
        $token = $this->requestToken();
        $malformed = $this->account($token);
        $malformed['password'] = ['not a string'];
        $this->post('/student/complete', $malformed)->assertStatus(422);
        $long = $this->account($token);
        $long['password'] = str_repeat('🔒', 20);
        $long['password_confirmation'] = $long['password'];
        $this->post('/student/complete', $long)->assertStatus(422);
        $badToken = $this->account($token);
        $badToken['token'] = ['not a string'];
        $this->post('/student/complete', $badToken)->assertStatus(422);
        $this->assertDatabaseCount('student_email_verifications', 1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_student_number_is_rejected_without_consuming_the_link(): void
    {
        User::factory()->create(['student_id' => 'a23-12345']);
        $token = $this->requestToken();
        $this->post('/student/complete', $this->account($token))->assertStatus(422);
        $this->assertDatabaseCount('student_email_verifications', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_node_and_legacy_runtimes_cannot_issue_or_complete_accounts(): void
    {
        foreach (['legacy', 'node'] as $mode) {
            config(['ingress.mode' => $mode, 'ingress.node_id' => 'node-1']);
            DB::table('runtime_identity')->delete();
            $this->postJson('/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(503);
            $this->get('/student/complete')->assertStatus(503);
        }
        Mail::assertNothingSent();
    }

    public function test_verification_requests_are_rate_limited_per_address(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(202);
        }
        $this->postJson('/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(429);
    }

    public function test_throttled_completion_does_not_redirect_to_organizer_login(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/student/complete', $this->account(str_repeat('a', 64)))->assertStatus(422);
        }
        $this->post('/student/complete', $this->account(str_repeat('a', 64)))->assertStatus(429);
    }

    public function test_production_requires_encrypted_smtp(): void
    {
        $this->withoutMiddleware(EnsureRuntimeDatabase::class);
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.url' => null, 'mail.mailers.smtp.scheme' => 'smtp']);
        $this->postJson('https://example.test/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(503);
        config(['mail.mailers.smtp.scheme' => null, 'mail.mailers.smtp.url' => 'smtps://example.test:25']);
        $this->postJson('https://example.test/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(503);
        config(['mail.mailers.smtp.url' => null, 'mail.mailers.smtp.scheme' => 'smtps']);
        $this->postJson('https://example.test/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(202);
        Mail::assertSent(StudentVerificationMail::class);
    }

    public function test_production_refuses_insecure_url_or_log_mailer(): void
    {
        $this->withoutMiddleware(EnsureRuntimeDatabase::class);
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://example.test', 'mail.default' => 'log']);
        $this->postJson('https://example.test/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(503);
        config(['mail.default' => 'smtp', 'app.url' => 'http://example.test']);
        $this->postJson('https://example.test/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(503);
        config(['app.url' => 'https://example.test']);
        $this->postJson('http://example.test/api/v1/auth/student-verification', ['email' => 'student@student.mseuf.edu.ph'])->assertStatus(503);
        Mail::assertNothingSent();
    }
}
