<?php

namespace Tests\Feature\Tenancy;

use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sweeps every authenticated route that takes a URL parameter and proves Alice gets
 * 404 for Bob's resource. A new parameterised route FAILS this test until it is either
 * registered in foreignResourceFactories() or explicitly allow-listed with a reason.
 */
class TenantRouteSweepTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * Authenticated parameterised routes that do not address tenant data.
     * Key: route name or URI. Value: why it is exempt.
     *
     * @var array<string, string>
     */
    private const array NON_TENANT_ROUTES = [
        'verification.verify' => 'Signed email-verification link for the current user; id/hash are checked by Fortify.',
        'password.reset' => 'Guest password reset form; token parameter, not a tenant resource.',
    ];

    /**
     * Route parameter name => factory returning the value that addresses one of Bob's resources.
     * Add an entry here for every new tenant-owned route parameter.
     *
     * @return array<string, Closure(User): string>
     */
    private function foreignResourceFactories(): array
    {
        return [
            // User-owned (not tenant-owned), but must still be unreachable across users.
            'passkey' => fn (User $bob) => (string) DB::table('passkeys')->insertGetId([
                'user_id' => $bob->id,
                'name' => 'Bob key',
                'credential_id' => 'bob-credential-'.uniqid(),
                'credential' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'task' => fn (User $bob) => $this->asTenant($bob, fn () => Task::factory()->create())->id,
            'occurrence' => fn (User $bob) => $this->asTenant($bob, fn () => TaskOccurrence::factory()->create())->id,
        ];
    }

    public function test_every_authenticated_parameterised_route_rejects_foreign_resources(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $factories = $this->foreignResourceFactories();
        $checked = 0;

        foreach ($this->candidateRoutes() as $route) {
            $key = $route->getName() ?? $route->uri();

            $missing = array_diff($route->parameterNames(), array_keys($factories));
            $this->assertSame([], array_values($missing), sprintf(
                'Route [%s] has parameter(s) [%s] without a cross-tenant check. Register them in %s::foreignResourceFactories() or allow-list the route in NON_TENANT_ROUTES with a reason.',
                $key, implode(', ', $missing), self::class,
            ));

            $parameters = [];
            foreach ($route->parameterNames() as $name) {
                $parameters[$name] = $factories[$name]($bob);
            }

            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $uri = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $parameters[$m[1]] ?? '', $route->uri()), '/');

            $status = $this->actingAs($alice)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->json((string) $method, $uri)
                ->getStatusCode();

            $this->assertContains($status, [403, 404], "Route [{$key}] returned {$status} for another tenant's resource.");
            $checked++;
        }

        // At least the passkey route must have been exercised; guards against the sweep silently matching nothing.
        $this->assertGreaterThanOrEqual(1, $checked, 'Route sweep did not exercise any routes.');
    }

    /**
     * @return list<Route>
     */
    private function candidateRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if ($route->parameterNames() === []) {
                continue;
            }

            if (array_key_exists((string) $route->getName(), self::NON_TENANT_ROUTES) || array_key_exists($route->uri(), self::NON_TENANT_ROUTES)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $authenticated = collect($middleware)->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate')));

            if ($authenticated) {
                $routes[] = $route;
            }
        }

        return $routes;
    }
}
