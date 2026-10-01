<?php

namespace Database\Seeders;

use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Demo events depend on the demo users and stay behind the same guard.
        if (! config('app.demo_mode')) {
            return;
        }

        $organizer1 = User::where('email', 'organizer@mseuf.edu.ph')->first();
        $organizer2 = User::where('email', 'organizer2@mseuf.edu.ph')->first();
        $staffA = User::where('email', 'staff.gate_a@mseuf.edu.ph')->first();
        $staffB = User::where('email', 'staff.gate_b@mseuf.edu.ph')->first();
        $staffC = User::where('email', 'staff.gate_c@mseuf.edu.ph')->first();

        $gym = Venue::where('code', 'GYM')->first();
        $aud = Venue::where('code', 'AUD')->first();

        // 1. Primary Demo Event: MSEUF University Intramurals 2026
        // Title, official dates (Sept 30 - Oct 3, 2026), and venue (University Gymnasium)
        // are confirmed by official MSEUF calendar sources (mseuf.edu.ph/events/2026).
        // Capacity, registration window, and entrances are synthetic Ingress demo configuration.
        $intramurals = Event::updateOrCreate(
            ['slug' => 'mseuf-university-intramurals-2026'],
            [
                'organizer_id' => $organizer1->id,
                'venue_id' => $gym->id,
                'title' => 'MSEUF University Intramurals 2026',
                'description' => 'The annual university-wide sports, parade, e-sports, and athletic competition celebrating camaraderie, excellence, and sportsmanship across the collegiate departments of Manuel S. Enverga University Foundation.',
                'capacity' => 50,
                'registration_opens_at' => Carbon::now()->subDay(),
                'registration_closes_at' => Carbon::now()->addDays(7),
                'starts_at' => Carbon::now()->addDays(2)->setHour(8)->setMinute(0),
                'ends_at' => Carbon::now()->addDays(5)->setHour(18)->setMinute(0),
                'status' => Event::STATUS_PUBLISHED,
            ]
        );

        // Synthetic Ingress demo entrance access points
        $entrance1 = AccessPoint::updateOrCreate(
            ['event_id' => $intramurals->id, 'code' => 'ENTRANCE-1'],
            ['name' => 'Entrance 1', 'is_active' => true]
        );
        $entrance2 = AccessPoint::updateOrCreate(
            ['event_id' => $intramurals->id, 'code' => 'ENTRANCE-2'],
            ['name' => 'Entrance 2', 'is_active' => true]
        );
        $entrance3 = AccessPoint::updateOrCreate(
            ['event_id' => $intramurals->id, 'code' => 'ENTRANCE-3'],
            ['name' => 'Entrance 3', 'is_active' => true]
        );

        // Staff assignments to entrances
        if ($staffA) {
            EventStaff::updateOrCreate(
                ['event_id' => $intramurals->id, 'user_id' => $staffA->id],
                ['access_point_id' => $entrance1->id]
            );
        }
        if ($staffB) {
            EventStaff::updateOrCreate(
                ['event_id' => $intramurals->id, 'user_id' => $staffB->id],
                ['access_point_id' => $entrance2->id]
            );
        }
        if ($staffC) {
            EventStaff::updateOrCreate(
                ['event_id' => $intramurals->id, 'user_id' => $staffC->id],
                ['access_point_id' => $entrance3->id]
            );
        }

        // 2. Secondary Real Event 1: MSEUF 79th Foundation Anniversary: Thanksgiving Mass
        // Official source: mseuf.edu.ph/79/event/miss-enverga (Feb 9, 2026 at University Gymnasium)
        $thanksgiving = Event::updateOrCreate(
            ['slug' => 'mseuf-79th-foundation-thanksgiving-mass'],
            [
                'organizer_id' => $organizer2->id,
                'venue_id' => $gym->id,
                'title' => 'MSEUF 79th Foundation Anniversary: Thanksgiving Mass',
                'description' => 'Opening Eucharistic Celebration for Enverga University\'s 79th Anniversary Foundation Celebration held at the University Gymnasium.',
                'capacity' => 300,
                'registration_opens_at' => Carbon::now()->subDays(30),
                'registration_closes_at' => Carbon::now()->subDays(20),
                'starts_at' => Carbon::now()->subDays(15)->setHour(8)->setMinute(0),
                'ends_at' => Carbon::now()->subDays(15)->setHour(10)->setMinute(0),
                'status' => Event::STATUS_COMPLETED,
            ]
        );

        AccessPoint::updateOrCreate(
            ['event_id' => $thanksgiving->id, 'code' => 'MAIN-GYM'],
            ['name' => 'Main Gymnasium Entrance', 'is_active' => true]
        );

        // 3. Secondary Real Event 2: Miss Enverga University 2026
        // Official source: mseuf.edu.ph/79/event/miss-enverga and mseuf.edu.ph/79/event/alumni
        $missEnverga = Event::updateOrCreate(
            ['slug' => 'miss-enverga-university-2026'],
            [
                'organizer_id' => $organizer1->id,
                'venue_id' => $gym->id,
                'title' => 'Miss Enverga University 2026',
                'description' => 'The prestigious university coronation pageant showcasing talent, intelligence, and student advocacy during the 79th Foundation Anniversary Celebration.',
                'capacity' => 500,
                'registration_opens_at' => Carbon::now()->addDays(5),
                'registration_closes_at' => Carbon::now()->addDays(15),
                'starts_at' => Carbon::now()->addDays(20)->setHour(17)->setMinute(0),
                'ends_at' => Carbon::now()->addDays(20)->setHour(21)->setMinute(0),
                'status' => Event::STATUS_DRAFT,
            ]
        );

        AccessPoint::updateOrCreate(
            ['event_id' => $missEnverga->id, 'code' => 'MAIN'],
            ['name' => 'Main Entrance', 'is_active' => true]
        );
        AccessPoint::updateOrCreate(
            ['event_id' => $missEnverga->id, 'code' => 'NORTH'],
            ['name' => 'North Gate', 'is_active' => true]
        );
    }
}
