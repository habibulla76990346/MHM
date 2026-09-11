<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Reference data only.
     *
     * No demo users are created here: a seeder that invents an account with a
     * known password is a backdoor waiting to reach production. The first
     * administrator is created deliberately, by `php artisan aziv:admin:create`
     * or by the web installer.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);
    }
}
