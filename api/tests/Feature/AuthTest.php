<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_via_mobile_api_and_receive_token(): void
    {
        $user = User::factory()->create([
            'email' => 'student.test@mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.test@mseuf.edu.ph',
            'password' => 'secret123',
            'device_name' => 'TestPhone',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'name', 'email', 'role', 'student_id'],
            ])
            ->assertJson([
                'token_type' => 'Bearer',
                'user' => [
                    'email' => 'student.test@mseuf.edu.ph',
                    'role' => User::ROLE_STUDENT,
                ],
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'student.test@mseuf.edu.ph',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.test@mseuf.edu.ph',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unauthenticated_user_cannot_access_me_endpoint(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $user = User::factory()->create([
            'email' => 'staff.test@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
        ]);

        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJson([
                'user' => [
                    'id' => $user->id,
                    'email' => 'staff.test@mseuf.edu.ph',
                    'role' => User::ROLE_STAFF,
                ],
            ]);
    }

    public function test_user_can_logout_and_revoke_token(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_STUDENT,
        ]);

        $token = $user->createToken('test-device')->plainTextToken;

        $this->assertCount(1, $user->tokens);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully.']);

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_student_cannot_access_organizer_web_portal(): void
    {
        $student = User::factory()->create([
            'email' => 'student@mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
            'password' => bcrypt('password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'student@mseuf.edu.ph',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_mobile_login_is_throttled_after_five_attempts(): void
    {
        User::factory()->create([
            'email' => 'throttled@mseuf.edu.ph',
            'password' => bcrypt('secret123'),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'throttled@mseuf.edu.ph',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'throttled@mseuf.edu.ph',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_mobile_login_throttle_is_scoped_per_email(): void
    {
        User::factory()->create([
            'email' => 'exhausted@mseuf.edu.ph',
            'password' => bcrypt('secret123'),
        ]);

        User::factory()->create([
            'email' => 'unaffected@mseuf.edu.ph',
            'password' => bcrypt('secret123'),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'exhausted@mseuf.edu.ph',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'exhausted@mseuf.edu.ph',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // A different account from the same IP is not locked out.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'unaffected@mseuf.edu.ph',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_mobile_login_rejects_a_malformed_email_shape_without_erroring(): void
    {
        // The limiter runs before validation, so it must tolerate a non-string
        // `email` instead of raising a 500 and leaving the endpoint unthrottled.
        $this->postJson('/api/v1/auth/login', [
            'email' => ['not-a-string@mseuf.edu.ph'],
            'password' => 'secret',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/login', [
            'email' => ['not-a-string@mseuf.edu.ph'],
            'password' => 'secret',
        ])->assertStatus(422);
    }

    public function test_organizer_web_login_is_throttled_after_five_attempts(): void
    {
        $organizer = User::factory()->create([
            'email' => 'throttle.web@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
            'password' => bcrypt('password'),
        ]);

        // Wrong password so the session stays a guest and the form stays reachable.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => $organizer->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $blocked = $this->post('/login', [
            'email' => $organizer->email,
            'password' => 'wrong-password',
        ]);

        $blocked->assertRedirect(route('login'));

        // Assert on the rendered form rather than on the session bag: reading
        // `session('errors')` between the POST and this GET rewraps the flashed bag
        // and it then fails to re-render, which is a test-harness artifact.
        $form = $this->get(route('login'));

        $form->assertSee('Too many login attempts. Please try again in a minute.');
        $form->assertDontSee('do not match our records');

        $this->assertGuest();
    }
}
