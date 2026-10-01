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
        // Venues are reference data with nothing predictable in them, so they are
        // always seeded. The demonstration accounts and events exist only to make a
        // development or classroom environment immediately usable, and they are
        // deliberately withheld outside demo mode: this seeder must never be the
        // source of a predictable production credential.
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
