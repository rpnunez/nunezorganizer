<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional `Idempotency-Key` header for mutating API requests.
 *
 * The first successful (2xx) response for (user, method, path, key) is stored for
 * 24 hours and replayed for retries, with an `Idempotent-Replayed: true` header.
 * Reusing a key with a different payload is rejected with 422. Keys are namespaced
 * per user, so one user's key can never return another user's response.
 */
class EnforceIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    public const int TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        $user = $request->user();

        if (! is_string($key) || $key === '' || ! $user instanceof User || ! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return $next($request);
        }

        if (strlen($key) > 100 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return response()->json(['message' => __('Invalid Idempotency-Key header.')], 400);
        }

        $cacheKey = 'idempotency:'.hash('sha256', implode('|', [$user->id, $request->method(), $request->path(), $key]));
        $fingerprint = hash('sha256', (string) $request->getContent());

        return Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($cacheKey, $fingerprint, $next, $request) {
            /** @var array{fingerprint: string, status: int, body: string, content_type: string}|null $stored */
            $stored = Cache::get($cacheKey);

            if ($stored !== null) {
                if (! hash_equals($stored['fingerprint'], $fingerprint)) {
                    return response()->json(['message' => __('This Idempotency-Key was already used with a different request.')], 422);
                }

                return response($stored['body'], $stored['status'])
                    ->header('Content-Type', $stored['content_type'])
                    ->header('Idempotent-Replayed', 'true');
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'content_type' => (string) $response->headers->get('Content-Type', 'application/json'),
                ], self::TTL_SECONDS);
            }

            return $response;
        });
    }
}
