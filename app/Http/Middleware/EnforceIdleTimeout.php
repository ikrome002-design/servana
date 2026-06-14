<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sliding idle-timeout for authenticated SPA sessions (Plan §9.2).
 *
 * If more than `servana.auth.idle_timeout_minutes` have elapsed since the last
 * authenticated request, the session is destroyed and the request is rejected
 * with 401 (rendered as the structured envelope). Otherwise the activity clock
 * is reset. Runs after `auth:sanctum`, so a user is always present.
 */
final class EnforceIdleTimeout
{
    private const SESSION_KEY = 'last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        // No session = a stateless (token) request; idle timeout does not apply.
        if (! $request->hasSession()) {
            return $next($request);
        }

        $idleMinutes = (int) Config::get('servana.auth.idle_timeout_minutes', 60);
        $now = now()->getTimestamp();
        $last = $request->session()->get(self::SESSION_KEY);

        if (is_int($last) && ($now - $last) > $idleMinutes * 60) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException;
        }

        $request->session()->put(self::SESSION_KEY, $now);

        return $next($request);
    }
}
