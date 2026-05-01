<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TenantSetupSeeder extends Seeder
{
    /**
     * Seed the tenant database with baseline data.
     */
    public function run(): void
    {
        $this->call(CmsSettingSeeder::class);
    }
}
