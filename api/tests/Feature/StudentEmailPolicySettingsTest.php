<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentEmailPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingress.mode' => 'cloud']);
    }

    public function test_admin_can_change_student_email_policy_and_the_change_is_audited(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/admin/settings/student-access')
            ->assertOk()
            ->assertSee('Require institutional student email')
            ->assertSee('Personal email allowed')
            ->assertSee('Ingress Cloud Event Management')
            ->assertDontSee('LAN Mode Active');

        $this->actingAs($admin)
            ->post('/admin/settings/student-access', ['require_institutional_email' => '1'])
            ->assertRedirect('/admin/settings/student-access')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('student_access_settings', [
            'id' => 1,
            'require_institutional_email' => true,
        ]);
        $this->assertDatabaseHas('student_access_policy_audits', [
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
            'old_required' => false,
            'new_required' => true,
        ]);
        $this->assertNotNull(
            DB::table('student_access_settings')->where('id', 1)->value('policy_enabled_at'),
        );

        $this->actingAs($admin)
            ->post('/admin/settings/student-access', ['require_institutional_email' => '0'])
            ->assertRedirect('/admin/settings/student-access');

        $this->assertDatabaseCount('student_access_policy_audits', 2);
        $this->assertDatabaseHas('student_access_policy_audits', [
            'admin_user_id' => $admin->id,
            'old_required' => true,
            'new_required' => false,
        ]);
        $this->assertDatabaseHas('student_access_settings', [
            'id' => 1,
            'require_institutional_email' => false,
            'policy_enabled_at' => null,
        ]);
    }

    public function test_non_admin_cannot_view_or_change_student_email_policy(): void
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);

        $this->actingAs($organizer)
            ->get('/admin/settings/student-access')
            ->assertForbidden();

        $this->actingAs($organizer)
            ->post('/admin/settings/student-access', ['require_institutional_email' => '1'])
            ->assertForbidden();

        $this->assertDatabaseHas('student_access_settings', [
            'id' => 1,
            'require_institutional_email' => false,
        ]);
        $this->assertDatabaseCount('student_access_policy_audits', 0);
    }
}
