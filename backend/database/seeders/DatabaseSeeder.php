<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data only. For a real installation create the company with
     * `php artisan app:create-company` instead of seeding.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
