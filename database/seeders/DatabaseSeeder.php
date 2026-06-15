<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The §10.3 permission registry — required for permission-driven UI and
        // any DB-backed authorization tooling (resolution itself reads the
        // canonical registry so it works unseeded in feature tests).
        $this->call(PermissionSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
