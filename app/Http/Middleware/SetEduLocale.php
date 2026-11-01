<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Обуки" (edu) admin API speaks Macedonian: validation and other
 * translated messages for /api/edu-* requests use the "mk" locale
 * (lang/mk). Everything else (healthcare / e-shop) keeps the app locale.
 *
 * The previous locale is restored afterwards so it can't leak into later
 * requests handled by the same app instance (tests, Octane, queues).
 */
class SetEduLocale
{
    public const LOCALE = 'mk';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/edu-*')) {
            return $next($request);
        }

        $previous = App::getLocale();
        App::setLocale(self::LOCALE);

        try {
            return $next($request);
        } finally {
            App::setLocale($previous);
        }
    }
}
