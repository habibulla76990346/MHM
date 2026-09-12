<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data only.
     *
     * No demo users are created here: a seeder that invents an account with a
     * known password is a backdoor waiting to reach production. The first
     * administrator is created deliberately, by `php artisan aziv:admin:create`
     * or by the web installer.
     *
     * MODEL EVENTS STAY ON. Laravel's `WithoutModelEvents` trait is the usual
     * default here, and it silently broke a fresh install: this codebase
     * generates uuids and slugs in `creating` hooks, so suppressing events
     * made every seeded page fail on a NOT NULL uuid. Seeding a fresh database
     * is the one path a new owner takes first, and it has to work.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ThemesSeeder::class,
            ContentSeeder::class,
            BillingSeeder::class,
        ]);
    }
}
