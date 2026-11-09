<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Minimal API token management until a settings UI exists.
 *
 *   php artisan nunez:token alice@example.test --name=shortcuts --ability=tasks:read --ability=tasks:write
 *   php artisan nunez:token alice@example.test --list
 *   php artisan nunez:token alice@example.test --revoke=3
 */
class ApiTokenCommand extends Command
{
    public const array ABILITIES = ['tasks:read', 'tasks:write', 'reviews:read'];

    protected $signature = 'nunez:token
        {email : The token owner}
        {--name=api : Token name}
        {--ability=* : Abilities (tasks:read, tasks:write, reviews:read); defaults to tasks:read}
        {--list : List the user\'s tokens}
        {--revoke= : Revoke a token by id}';

    protected $description = 'Create, list or revoke personal API tokens (Sanctum)';

    public function handle(): int
    {
        $user = User::where('email', Invitation::normalizeEmail((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('No user found with that email address.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $this->table(['id', 'name', 'abilities', 'last used', 'created'], $user->tokens()->get()->map(fn ($t) => [
                $t->id, $t->name, implode(', ', (array) $t->abilities), $t->last_used_at ?? 'never', $t->created_at,
            ])->all());

            return self::SUCCESS;
        }

        if (($revoke = $this->option('revoke')) !== null) {
            $deleted = $user->tokens()->whereKey($revoke)->delete();
            $deleted ? $this->info('Token revoked.') : $this->error('Token not found for this user.');

            return $deleted ? self::SUCCESS : self::FAILURE;
        }

        $abilities = array_values(array_filter($this->option('ability'), 'is_string'));
        $abilities = $abilities === [] ? ['tasks:read'] : $abilities;
        $unknown = array_diff($abilities, self::ABILITIES);

        if ($unknown !== []) {
            $this->error('Unknown ability: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $token = $user->createToken((string) $this->option('name'), $abilities);

        $this->info('Token created. It is shown only once; store it securely:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
