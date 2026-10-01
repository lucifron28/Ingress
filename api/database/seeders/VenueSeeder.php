<?php

namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $venues = [
            [
                'name' => 'MSEUF University Gymnasium',
                'code' => 'GYM',
                'location' => 'University Athletic Complex, Lucena City',
                'default_capacity' => 1500,
            ],
            [
                'name' => 'MSEUF University Auditorium',
                'code' => 'AUD',
                'location' => 'Main Academic Building, 2nd Floor, Lucena City',
                'default_capacity' => 600,
            ],
            [
                'name' => 'Enverga Multipurpose Hall',
                'code' => 'MPH',
                'location' => 'Student Activity Center, Lucena City',
                'default_capacity' => 300,
            ],
            [
                'name' => 'CCS Innovation & Robotics Lab',
                'code' => 'CCSLAB',
                'location' => 'College of Computer Studies Building, 3rd Floor',
                'default_capacity' => 80,
            ],
        ];

        foreach ($venues as $venueData) {
            Venue::updateOrCreate(
                ['code' => $venueData['code']],
                $venueData
            );
        }
    }
}
