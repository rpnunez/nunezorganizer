<?php

namespace App\Domain\Accounts\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Operator-only data portability and deletion; never exposed to HTTP. */
class UserDataService
{
    /** @return array<string, mixed> */
    public function export(string $email): array
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->firstOrFail();
        $taskIds = DB::table('tasks')->where('owner_user_id', $user->id)->pluck('id');

        return [
            'exported_at' => now()->toIso8601String(),
            'user' => ['name' => $user->name, 'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String()],
            'preferences' => DB::table('user_preferences')->where('user_id', $user->id)->get(),
            'tasks' => DB::table('tasks')->where('owner_user_id', $user->id)->get(),
            'occurrences' => DB::table('task_occurrences')->whereIn('task_id', $taskIds)->get(),
            'responses' => DB::table('completion_responses')->whereIn('task_id', $taskIds)->get(),
            'reviews' => DB::table('daily_reviews')->where('user_id', $user->id)->get(),
            'routine_plan' => DB::table('routine_plans')->where('user_id', $user->id)->first(),
            'routine_advancements' => DB::table('routine_plan_advancements')->where('user_id', $user->id)->get(),
            'telegram_links' => DB::table('telegram_links')->where('user_id', $user->id)->get(),
            'deliveries' => DB::table('notification_deliveries')->where('user_id', $user->id)->get(),
            'audit_events' => DB::table('audit_events')->where('actor_user_id', $user->id)->get(),
            // Password hashes, API tokens, pairing secrets and callback token hashes are deliberately omitted.
        ];
    }

    public function delete(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $user = User::query()->where('email', mb_strtolower(trim($email)))->lockForUpdate()->firstOrFail();
            $accountIds = DB::table('account_memberships')->where('user_id', $user->id)->pluck('account_id');

            $shared = DB::table('account_memberships')->whereIn('account_id', $accountIds)
                ->where('user_id', '!=', $user->id)->exists();
            if ($shared) {
                throw new RuntimeException('Refusing to delete a shared account.');
            }

            // Keep only action + time for operational audit, without actor, tenant, subject or metadata.
            DB::table('audit_events')->where('actor_user_id', $user->id)
                ->orWhereIn('account_id', $accountIds)
                ->update(['actor_user_id' => null, 'account_id' => null,
                    'subject_type' => null, 'subject_id' => null, 'metadata' => null]);

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('invitations')->where('email', $user->email)->delete();
            $user->tokens()->delete();
            $user->delete(); // Database foreign keys cascade tenant-owned children.
            DB::table('accounts')->whereIn('id', $accountIds)->where('type', 'personal')->delete();
        });
    }
}
