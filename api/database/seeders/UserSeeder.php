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
        // Keep direct UserSeeder runs behind the same demo-mode guard.
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
                'name' => 'Staff Alex Rivera (Entrance 1)',
                'email' => 'staff.gate_a@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_STAFF,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Staff Bea Ramos (Entrance 2)',
                'email' => 'staff.gate_b@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_STAFF,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Staff Chris Dizon (Entrance 3)',
                'email' => 'staff.gate_c@mseuf.edu.ph',
                'student_id' => null,
                'role' => User::ROLE_STAFF,
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Ron Vincent Cada',
                'email' => 'a23-35524@student.mseuf.edu.ph',
                'student_id' => 'A23-35524',
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
