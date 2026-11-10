<?php

namespace App\Console\Commands;

use App\Domain\Accounts\Actions\UserDataService;
use Illuminate\Console\Command;
use Throwable;

class DeleteUserData extends Command
{
    protected $signature = 'nunez:user:delete {email} {--confirm-email= : Repeat the exact email to confirm deletion}';

    protected $description = 'Permanently delete one user and their personal account data';

    public function handle(UserDataService $data): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if ($email === '' || $this->option('confirm-email') !== $email) {
            $this->error('Deletion requires --confirm-email matching the exact account email.');

            return self::FAILURE;
        }

        try {
            $data->delete($email);
        } catch (Throwable) {
            $this->error('Deletion failed. Check the account and database logs, then retry.');

            return self::FAILURE;
        }

        $this->info('User data deleted; audit action/timestamps retained without identifiers.');

        return self::SUCCESS;
    }
}
