<?php

namespace App\Http\Middleware;

use App\Domain\Accounts\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for authenticated requests from the user's active membership.
 *
 * Runs before route-model binding (see bootstrap/app.php priority), so bound
 * tenant models are already scoped. Any account_id in the request is ignored.
 */
class EnsureTenant
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            try {
                $this->tenant->setForUser($user);
            } catch (AuthorizationException) {
                abort(403, __('Your account is not active.'));
            }
        }

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
