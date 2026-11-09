<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class InviteUserCommand extends Command
{
    protected $signature = 'nunez:invite
        {email : Email address of the person to invite}
        {--days= : Days until the invitation expires (default from config)}';

    protected $description = 'Create a single-use registration invitation and print its link';

    public function handle(): int
    {
        $email = Invitation::normalizeEmail((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('Please provide a valid email address.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('A user with that email address already exists.');

            return self::FAILURE;
        }

        $days = $this->option('days');

        [$invitation, $token] = Invitation::issue($email, ttlDays: is_numeric($days) ? (int) $days : null);

        $this->info("Invitation created for {$invitation->email} (expires {$invitation->expires_at->toDayDateTimeString()} UTC).");
        $this->line('Share this single-use link privately:');
        $this->line(route('register', ['invite' => $token]));

        return self::SUCCESS;
    }
}
