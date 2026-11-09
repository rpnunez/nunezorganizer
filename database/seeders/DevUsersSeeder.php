<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Two isolated demo users for local development and tests.
 * Password for both: "password". Never runs outside local/testing.
 */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DevUsersSeeder skipped: only runs in local/testing environments.');

            return;
        }

        foreach ([['Alice Example', 'alice@example.test'], ['Bob Example', 'bob@example.test']] as [$name, $email]) {
            if (! User::query()->where('email', $email)->exists()) {
                User::factory()->create(['name' => $name, 'email' => $email]);
            }
        }
    }
}
