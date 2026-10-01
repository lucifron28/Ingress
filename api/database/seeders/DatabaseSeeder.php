<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Outside demo mode, seed reference data only.
        if (! config('app.demo_mode')) {
            $this->call(VenueSeeder::class);

            return;
        }

        $this->call([
            UserSeeder::class,
            VenueSeeder::class,
            EventSeeder::class,
        ]);
    }
}
