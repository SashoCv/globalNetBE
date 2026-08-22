<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictEshopRole
{
    /**
     * Paths (relative to /api) that an "eshop" role user is allowed to hit,
     * in addition to their own shop-* resources.
     */
    private const ALWAYS_ALLOWED = [
        'user',
        'logout',
        'uploads',
    ];

    private const ALLOWED_PREFIXES = [
        'shop-',
        'notifications',
    ];

    /**
     * Explicitly off-limits even though they match an allowed prefix above
     * (e.g. the E-Shop overview dashboard, kept admin-only).
     */
    private const DENIED_PATHS = [
        'shop-orders/dashboard',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'eshop') {
            return $next($request);
        }

        $path = preg_replace('#^api/#', '', ltrim($request->path(), '/'));

        $isDenied = in_array($path, self::DENIED_PATHS, true);

        $isAllowed = ! $isDenied && (
            in_array($path, self::ALWAYS_ALLOWED, true)
            || collect(self::ALLOWED_PREFIXES)->contains(fn ($prefix) => str_starts_with($path, $prefix))
        );

        if (! $isAllowed) {
            return response()->json([
                'message' => 'Немате пристап до овој ресурс.',
            ], 403);
        }

        return $next($request);
    }
}
