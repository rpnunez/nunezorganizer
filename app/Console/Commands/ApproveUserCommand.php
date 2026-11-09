<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Console\Command;

class ApproveUserCommand extends Command
{
    protected $signature = 'nunez:user:approve {email : Email address of the user to approve}';

    protected $description = 'Approve a user registered in "approval" registration mode';

    public function handle(): int
    {
        $user = User::where('email', Invitation::normalizeEmail((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->error('No user found with that email address.');

            return self::FAILURE;
        }

        if ($user->isApproved()) {
            $this->info("{$user->email} is already approved.");

            return self::SUCCESS;
        }

        $user->forceFill(['approved_at' => now()])->save();
        $this->info("{$user->email} has been approved.");

        return self::SUCCESS;
    }
}
