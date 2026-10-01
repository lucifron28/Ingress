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
        // Demonstration events exist only to make a demo environment usable, and
        // they depend on the demo users. Guarded here as well as in DatabaseSeeder.
        if (! config('app.demo_mode')) {
            return;
        }

        $organizer1 = User::where('email', 'organizer@mseuf.edu.ph')->first();
        $organizer2 = User::where('email', 'organizer2@mseuf.edu.ph')->first();
        $staffA = User::where('email', 'staff.gate_a@mseuf.edu.ph')->first();
        $staffB = User::where('email', 'staff.gate_b@mseuf.edu.ph')->first();

        $gym = Venue::where('code', 'GYM')->first();
        $aud = Venue::where('code', 'AUD')->first();
        $mph = Venue::where('code', 'MPH')->first();

        // 1. Published flagship symposium (organizer 1)
        $symposium = Event::updateOrCreate(
            ['slug' => 'mseuf-annual-tech-symposium-2026'],
            [
                'organizer_id' => $organizer1->id,
                'venue_id' => $gym->id,
                'title' => 'MSEUF Annual Tech Symposium 2026',
                'description' => 'The premier university technology and engineering exhibition featuring keynote speakers, innovative student capstone projects, and industry networking.',
                'capacity' => 50,
                'registration_opens_at' => Carbon::now()->subDay(),
                'registration_closes_at' => Carbon::now()->addDays(7),
                'starts_at' => Carbon::now()->addDays(2)->setHour(9)->setMinute(0),
                'ends_at' => Carbon::now()->addDays(2)->setHour(17)->setMinute(0),
                'status' => Event::STATUS_PUBLISHED,
            ]
        );

        // Access points for symposium
        $mainGate = AccessPoint::updateOrCreate(
            ['event_id' => $symposium->id, 'code' => 'MAIN'],
            ['name' => 'Main Turnstile Entrance', 'is_active' => true]
        );
        $gateA = AccessPoint::updateOrCreate(
            ['event_id' => $symposium->id, 'code' => 'GATE-A'],
            ['name' => 'Gate A - West Wing', 'is_active' => true]
        );
        $gateB = AccessPoint::updateOrCreate(
            ['event_id' => $symposium->id, 'code' => 'GATE-B'],
            ['name' => 'Gate B - East Wing', 'is_active' => true]
        );

        // Assign staff to gates
        if ($staffA) {
            EventStaff::updateOrCreate(
                ['event_id' => $symposium->id, 'user_id' => $staffA->id],
                ['access_point_id' => $gateA->id]
            );
        }
        if ($staffB) {
            EventStaff::updateOrCreate(
                ['event_id' => $symposium->id, 'user_id' => $staffB->id],
                ['access_point_id' => $gateB->id]
            );
        }

        // 2. Published hackathon (organizer 2)
        $hackathon = Event::updateOrCreate(
            ['slug' => 'coe-engineering-hackathon-2026'],
            [
                'organizer_id' => $organizer2->id,
                'venue_id' => $mph->id,
                'title' => 'COE Engineering Week Hackathon 2026',
                'description' => 'A 24-hour collaborative hardware and software challenge for MSEUF engineering and computing students.',
                'capacity' => 30,
                'registration_opens_at' => Carbon::now()->subHours(12),
                'registration_closes_at' => Carbon::now()->addDays(5),
                'starts_at' => Carbon::now()->addDays(5)->setHour(8)->setMinute(0),
                'ends_at' => Carbon::now()->addDays(6)->setHour(12)->setMinute(0),
                'status' => Event::STATUS_PUBLISHED,
            ]
        );

        AccessPoint::updateOrCreate(
            ['event_id' => $hackathon->id, 'code' => 'MAIN'],
            ['name' => 'MPH Entrance Gate', 'is_active' => true]
        );

        // 3. Draft event (organizer 1)
        Event::updateOrCreate(
            ['slug' => 'cas-freshmen-orientation-2026'],
            [
                'organizer_id' => $organizer1->id,
                'venue_id' => $aud->id,
                'title' => 'College of Arts & Sciences Freshmen Orientation',
                'description' => 'Orientation program for incoming first-year CAS students.',
                'capacity' => 200,
                'registration_opens_at' => Carbon::now()->addDays(10),
                'registration_closes_at' => Carbon::now()->addDays(20),
                'starts_at' => Carbon::now()->addDays(25)->setHour(10)->setMinute(0),
                'ends_at' => Carbon::now()->addDays(25)->setHour(15)->setMinute(0),
                'status' => Event::STATUS_DRAFT,
            ]
        );
    }
}
