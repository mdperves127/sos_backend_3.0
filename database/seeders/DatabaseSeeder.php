<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // SubscriptionSedder::run();

        // $this->call(UserSeeder::class);
        // $this->call(SubscriptionSedder::class);
        // $this->call(PlanTypeSeeder::class);

        // $this->call(PermissionTableSeeder::class);

        $this->call(CountriesTableSeeder::class);
        // $this->call(StatesTableSeeder::class);
        // $this->call(CitiesTableSeeder::class);
    }
}
