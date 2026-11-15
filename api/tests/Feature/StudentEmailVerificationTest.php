<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudentEmailVerificationTest extends TestCase
{
    public function test_legacy_laravel_student_verification_routes_are_retired_in_favor_of_firebase(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/student-verification', [
            'email' => 'student@student.mseuf.edu.ph',
        ])->assertNotFound();
        $this->get('/student/complete')->assertNotFound();
        $this->post('/student/complete')->assertNotFound();

        $this->assertTrue(Route::has('student.auth'));
        $this->assertTrue(Route::has('student.session.exchange'));
        Mail::assertNothingSent();
    }
}
