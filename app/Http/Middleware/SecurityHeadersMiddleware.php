<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     * Inserts rigid document and transport security restrictions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Proceed through the downstream request pipeline first
        $response = $next($request);

        $path = $request->path();

        // 🚨 Skip documentation routes (Laravel paths typically match these patterns)
        if (Str::contains($path, ['docs', 'redoc', 'openapi.json', 'api-docs'])) {
            return $response;
        }

        // --- Security headers only for API / verified transactional contexts ---

        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Compile Content-Security-Policy parameters safely
        $cspPolicies = [
            "default-src 'self'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "script-src 'self' https://cdnjs.cloudflare.com",
            "style-src 'self' https://cdnjs.cloudflare.com",
        ];
        $response->headers->set('Content-Security-Policy', implode('; ', $cspPolicies));

        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->headers->set('Cross-Origin-Embedder-Policy', 'require-corp');

        // Strip backend engine indicators from standard header stacks
        if ($response->headers->has('Server')) {
            $response->headers->remove('Server');
        }

        return $response;
    }
}