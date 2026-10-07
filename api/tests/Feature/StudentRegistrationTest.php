<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_register_successfully_and_receives_sanctum_session(): void
    {
        $payload = [
            'name' => 'Maria Clara de los Santos',
            'student_id' => '2026-00123',
            'email' => 'mclara@student.mseuf.edu.ph',
            'password' => 'secretPassword123!',
            'password_confirmation' => 'secretPassword123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                    'student_id',
                    'created_at',
                ],
            ])
            ->assertJson([
                'token_type' => 'Bearer',
                'user' => [
                    'name' => 'Maria Clara de los Santos',
                    'email' => 'mclara@student.mseuf.edu.ph',
                    'role' => User::ROLE_STUDENT,
                    'student_id' => '2026-00123',
                ],
            ]);

        $this->assertNotEmpty($response->json('token'));

        // Verify the user was created in database
        $this->assertDatabaseHas('users', [
            'email' => 'mclara@student.mseuf.edu.ph',
            'student_id' => '2026-00123',
            'role' => User::ROLE_STUDENT,
        ]);
    }

    public function test_created_user_strictly_has_role_student(): void
    {
        $payload = [
            'name' => 'Crisostomo Ibarra',
            'student_id' => '2026-00456',
            'email' => 'cibarra@student.mseuf.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);
        $response->assertStatus(201);

        $user = User::where('email', 'cibarra@student.mseuf.edu.ph')->first();
        $this->assertNotNull($user);
        $this->assertEquals(User::ROLE_STUDENT, $user->role);
        $this->assertTrue($user->isStudent());
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->isOrganizer());
        $this->assertFalse($user->isStaff());
    }

    public function test_registered_password_is_properly_hashed(): void
    {
        $payload = [
            'name' => 'Elias Salig',
            'student_id' => '2026-00789',
            'email' => 'esalig@student.mseuf.edu.ph',
            'password' => 'PlaintextPassword123!',
            'password_confirmation' => 'PlaintextPassword123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);
        $response->assertStatus(201);

        $user = User::where('email', 'esalig@student.mseuf.edu.ph')->first();
        $this->assertNotNull($user);
        $this->assertNotEquals('PlaintextPassword123!', $user->password);
        $this->assertTrue(Hash::check('PlaintextPassword123!', $user->password));
    }

    public function test_returned_token_authenticates_requests_to_me_endpoint(): void
    {
        $payload = [
            'name' => 'Sisa Narcisa',
            'student_id' => '2026-00999',
            'email' => 'snarcisa@student.mseuf.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);
        $response->assertStatus(201);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me');

        $meResponse->assertStatus(200)
            ->assertJson([
                'user' => [
                    'email' => 'snarcisa@student.mseuf.edu.ph',
                    'student_id' => '2026-00999',
                    'role' => User::ROLE_STUDENT,
                ],
            ]);
    }

    public function test_duplicate_email_is_rejected_with_validation_error(): void
    {
        User::factory()->create([
            'email' => 'existing@student.mseuf.edu.ph',
            'student_id' => '2024-11111',
            'role' => User::ROLE_STUDENT,
        ]);

        $payload = [
            'name' => 'Another Student',
            'student_id' => '2026-22222',
            'email' => 'existing@student.mseuf.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_duplicate_student_id_is_rejected_with_validation_error(): void
    {
        User::factory()->create([
            'email' => 'first@student.mseuf.edu.ph',
            'student_id' => '2024-33333',
            'role' => User::ROLE_STUDENT,
        ]);

        $payload = [
            'name' => 'Duplicate ID Student',
            'student_id' => '2024-33333',
            'email' => 'second@student.mseuf.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_malicious_client_role_cannot_escalate_privileges(): void
    {
        $payload = [
            'name' => 'Attacker Attempting Admin',
            'student_id' => '2026-99999',
            'email' => 'attacker@student.mseuf.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_ADMIN,
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201);
        $user = User::where('email', 'attacker@student.mseuf.edu.ph')->first();
        $this->assertNotNull($user);
        $this->assertEquals(User::ROLE_STUDENT, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_invalid_and_missing_fields_are_rejected(): void
    {
        // 1. Missing all fields
        $response = $this->postJson('/api/v1/auth/register', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'student_id', 'email', 'password']);

        // 2. Invalid email format
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test',
            'student_id' => '123',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // 3. Password shorter than 8 characters
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test',
            'student_id' => '123',
            'email' => 'test@student.mseuf.edu.ph',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // 4. Password confirmation mismatch
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test',
            'student_id' => '123',
            'email' => 'test@student.mseuf.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'differentPassword123',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_duplicate_email_with_different_case_is_rejected_as_validation_error(): void
    {
        User::factory()->create([
            'email' => 'lowercase@student.mseuf.edu.ph',
            'student_id' => '2024-55551',
            'role' => User::ROLE_STUDENT,
        ]);

        $payload = [
            'name' => 'Case Test Student',
            'student_id' => '2026-55552',
            'email' => 'LOWERCASE@STUDENT.MSEUF.EDU.PH',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_duplicate_email_with_surrounding_spaces_is_rejected_as_validation_error(): void
    {
        User::factory()->create([
            'email' => 'trimmed@student.mseuf.edu.ph',
            'student_id' => '2024-66661',
            'role' => User::ROLE_STUDENT,
        ]);

        $payload = [
            'name' => 'Space Test Student',
            'student_id' => '2026-66662',
            'email' => '   trimmed@student.mseuf.edu.ph   ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_duplicate_student_id_with_surrounding_spaces_is_rejected_as_validation_error(): void
    {
        User::factory()->create([
            'email' => 'idtest1@student.mseuf.edu.ph',
            'student_id' => '2024-77777',
            'role' => User::ROLE_STUDENT,
        ]);

        $payload = [
            'name' => 'Space ID Student',
            'student_id' => '   2024-77777   ',
            'email' => 'idtest2@student.mseuf.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }
}
