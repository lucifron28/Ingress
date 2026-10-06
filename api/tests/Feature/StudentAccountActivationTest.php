<?php

namespace Tests\Feature;

use App\Actions\IssueStudentActivationChallenge;
use App\Models\StudentActivationChallenge;
use App\Models\StudentIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudentAccountActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_request_activation_challenge_using_student_id(): void
    {
        $identity = StudentIdentity::create([
            'student_id' => 'A23-35524',
            'name' => 'Ron Vincent Cada',
            'institutional_email' => 'a23-35524@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/activate/request', [
            'identifier' => 'A23-35524',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Activation challenge sent to your institutional email.',
                'student_identity' => [
                    'student_id' => 'A23-35524',
                    'name' => 'Ron Vincent Cada',
                    'masked_email' => 'a2****@student.mseuf.edu.ph',
                    'college' => 'CCMS',
                ],
            ]);

        $this->assertNotEmpty($response->json('expires_at'));
        $this->assertDatabaseHas('student_activation_challenges', [
            'student_identity_id' => $identity->id,
            'verified_at' => null,
            'attempts' => 0,
        ]);
    }

    public function test_student_can_request_activation_challenge_using_institutional_email(): void
    {
        StudentIdentity::create([
            'student_id' => 'A23-99001',
            'name' => 'Clara Santos',
            'institutional_email' => 'csantos@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/activate/request', [
            'identifier' => 'csantos@student.mseuf.edu.ph',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'student_identity' => [
                    'student_id' => 'A23-99001',
                    'name' => 'Clara Santos',
                ],
            ]);
    }

    public function test_request_challenge_fails_if_student_is_not_in_directory(): void
    {
        $response = $this->postJson('/api/v1/auth/activate/request', [
            'identifier' => 'NON-EXISTENT-ID',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'identity_not_found',
            ]);
    }

    public function test_request_challenge_fails_if_account_is_already_activated(): void
    {
        $user = User::factory()->create([
            'email' => 'activated@student.mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
        ]);

        StudentIdentity::create([
            'student_id' => 'A23-88888',
            'name' => 'Already Claimed',
            'institutional_email' => 'activated@student.mseuf.edu.ph',
            'activated_user_id' => $user->id,
        ]);

        $response = $this->postJson('/api/v1/auth/activate/request', [
            'identifier' => 'A23-88888',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'already_activated',
            ]);
    }

    public function test_requesting_new_challenge_expires_previous_pending_challenges(): void
    {
        $identity = StudentIdentity::create([
            'student_id' => 'A23-11111',
            'name' => 'Multiple Challenges Student',
            'institutional_email' => 'multi@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        // First request
        $this->postJson('/api/v1/auth/activate/request', ['identifier' => 'A23-11111']);
        $firstChallenge = StudentActivationChallenge::where('student_identity_id', $identity->id)->first();
        $this->assertTrue($firstChallenge->expires_at->isFuture());

        // Travel 1 minute into future and issue second request
        Carbon::setTestNow(now()->addMinute());

        $this->postJson('/api/v1/auth/activate/request', ['identifier' => 'A23-11111']);
        $firstChallenge->refresh();

        // First challenge should now be expired
        $this->assertTrue($firstChallenge->expires_at->isPast() || $firstChallenge->expires_at->equalTo(now()));

        $challenges = StudentActivationChallenge::where('student_identity_id', $identity->id)->get();
        $this->assertCount(2, $challenges);
    }

    public function test_successful_activation_creates_student_user_and_links_identity(): void
    {
        $identity = StudentIdentity::create([
            'student_id' => 'A23-22222',
            'name' => 'New Student Activator',
            'institutional_email' => 'activator@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        // Issue challenge
        $issuer = new IssueStudentActivationChallenge();
        $issued = $issuer->execute($identity);
        $code = $issued['code'];

        $response = $this->postJson('/api/v1/auth/activate/confirm', [
            'identifier' => 'A23-22222',
            'code' => $code,
            'password' => 'SafePassword123!',
            'password_confirmation' => 'SafePassword123!',
            'device_name' => 'iPhone-15-Pro',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'name', 'email', 'role', 'student_id'],
                'student_identity' => ['student_id', 'name', 'institutional_email', 'college'],
            ])
            ->assertJson([
                'token_type' => 'Bearer',
                'user' => [
                    'name' => 'New Student Activator',
                    'email' => 'activator@student.mseuf.edu.ph',
                    'student_id' => 'A23-22222',
                    'role' => User::ROLE_STUDENT,
                ],
            ]);

        // Assert User in database
        $user = User::where('email', 'activator@student.mseuf.edu.ph')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isStudent());

        // Assert StudentIdentity linked
        $identity->refresh();
        $this->assertEquals($user->id, $identity->activated_user_id);
        $this->assertTrue($identity->isActivated());

        // Assert Challenge marked verified
        $challenge = StudentActivationChallenge::where('student_identity_id', $identity->id)->latest('id')->first();
        $this->assertTrue($challenge->isVerified());

        // Assert bearer token authenticates user on /api/v1/me
        $token = $response->json('token');
        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/me');

        $meResponse->assertStatus(200)
            ->assertJson([
                'user' => [
                    'id' => $user->id,
                    'email' => 'activator@student.mseuf.edu.ph',
                    'student_id' => 'A23-22222',
                ],
            ]);
    }

    public function test_activation_confirm_fails_with_invalid_code(): void
    {
        $identity = StudentIdentity::create([
            'student_id' => 'A23-33333',
            'name' => 'Wrong Code Student',
            'institutional_email' => 'wrongcode@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        $issuer = new IssueStudentActivationChallenge();
        $issuer->execute($identity);

        $response = $this->postJson('/api/v1/auth/activate/confirm', [
            'identifier' => 'A23-33333',
            'code' => '999999',
            'password' => 'SafePassword123!',
            'password_confirmation' => 'SafePassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $challenge = StudentActivationChallenge::where('student_identity_id', $identity->id)->latest('id')->first();
        $this->assertEquals(1, $challenge->attempts);
        $this->assertNull($challenge->verified_at);
        $this->assertFalse($identity->fresh()->isActivated());
    }

    public function test_activation_locks_out_after_five_failed_attempts(): void
    {
        $identity = StudentIdentity::create([
            'student_id' => 'A23-44444',
            'name' => 'Lockout Student',
            'institutional_email' => 'lockout@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        $issuer = new IssueStudentActivationChallenge();
        $issued = $issuer->execute($identity);
        $validCode = $issued['code'];

        // Perform 5 failed attempts
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/activate/confirm', [
                'identifier' => 'A23-44444',
                'code' => '000000',
                'password' => 'SafePassword123!',
                'password_confirmation' => 'SafePassword123!',
            ])->assertStatus(422);
        }

        // Even with the valid code, attempt is rejected due to max attempts exceeded
        $response = $this->postJson('/api/v1/auth/activate/confirm', [
            'identifier' => 'A23-44444',
            'code' => $validCode,
            'password' => 'SafePassword123!',
            'password_confirmation' => 'SafePassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertFalse($identity->fresh()->isActivated());
    }

    public function test_activation_fails_when_challenge_has_expired(): void
    {
        $identity = StudentIdentity::create([
            'student_id' => 'A23-55555',
            'name' => 'Expired Challenge Student',
            'institutional_email' => 'expired@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        $issuer = new IssueStudentActivationChallenge();
        $issued = $issuer->execute($identity);
        $code = $issued['code'];

        // Fast forward 20 minutes (past 15 minute TTL)
        Carbon::setTestNow(now()->addMinutes(20));

        $response = $this->postJson('/api/v1/auth/activate/confirm', [
            'identifier' => 'A23-55555',
            'code' => $code,
            'password' => 'SafePassword123!',
            'password_confirmation' => 'SafePassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertFalse($identity->fresh()->isActivated());
    }

    public function test_activation_rejects_mismatched_password_confirmation(): void
    {
        $identity = StudentIdentity::create([
            'student_id' => 'A23-66666',
            'name' => 'Mismatch Student',
            'institutional_email' => 'mismatch@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        $issuer = new IssueStudentActivationChallenge();
        $issued = $issuer->execute($identity);

        $response = $this->postJson('/api/v1/auth/activate/confirm', [
            'identifier' => 'A23-66666',
            'code' => $issued['code'],
            'password' => 'SafePassword123!',
            'password_confirmation' => 'DifferentPassword456!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertFalse($identity->fresh()->isActivated());
    }

    public function test_resend_challenge_issues_new_code(): void
    {
        StudentIdentity::create([
            'student_id' => 'A23-77777',
            'name' => 'Resend Student',
            'institutional_email' => 'resend@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/activate/resend', [
            'identifier' => 'A23-77777',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'student_identity' => [
                    'student_id' => 'A23-77777',
                ],
            ]);
    }
}
