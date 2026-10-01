<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Guarded here as well as in DatabaseSeeder: `db:seed --class=UserSeeder`
        // is a standard invocation and must not be able to create the predictable
        // demonstration credentials in an environment that did not opt in.
        if (! config('app.demo_mode')) {
            return;
        }

        $users = [
            [
                'name' => 'MSEUF Administrator',
                'email' => 'admin@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_ADMIN,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Prof. Juan Dela Cruz (CCS)',
                'email' => 'organizer@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_ORGANIZER,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Engr. Maria Santos (COE)',
                'email' => 'organizer2@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_ORGANIZER,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Staff Alex Rivera (Gate A)',
                'email' => 'staff.gate_a@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_STAFF,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Staff Bea Ramos (Gate B)',
                'email' => 'staff.gate_b@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_STAFF,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Carlo Mendoza',
                'email' => 'student1@mseuf.edu.ph',
                'student_id' => '2024-01001',
                'role' => User::ROLE_STUDENT,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Patricia Reyes',
                'email' => 'student2@mseuf.edu.ph',
                'student_id' => '2024-01002',
                'role' => User::ROLE_STUDENT,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Mark Aquino',
                'email' => 'student3@mseuf.edu.ph',
                'student_id' => '2024-01003',
                'role' => User::ROLE_STUDENT,
                'password' => Hash::make('password'),
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }
    }
}
