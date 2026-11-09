<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounts\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $account = $tenant->account();
        $preference = $user->preference()->first();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $preference?->timezone,
            ],
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
            ],
        ]);
    }
}
