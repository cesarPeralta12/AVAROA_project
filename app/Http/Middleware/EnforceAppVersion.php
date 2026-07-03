<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks API requests coming from app builds older than the minimum
 * allowed version. The minimum is controlled by the MIN_APP_VERSION env
 * var (Coolify) so old apps can be cut off without a code deploy.
 *
 * Old APKs never send the X-App-Version header, so they are treated as
 * version 0 and rejected as soon as MIN_APP_VERSION is >= 1.
 */
class EnforceAppVersion
{
    /**
     * Routes that are NOT the driver app and must never be version-gated:
     * WhatsApp webhook (Meta), send-test, and the public GPS tracking link.
     */
    private array $except = [
        'api/whatsapp/*',
        'api/track/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $minVersion = (int) env('MIN_APP_VERSION', 0);

        // Disabled — let everything through (default until the client sets it)
        if ($minVersion <= 0) {
            return $next($request);
        }

        // Skip non-app routes (webhooks, public tracking)
        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        $appVersion = (int) $request->header('X-App-Version', 0);

        if ($appVersion < $minVersion) {
            return response()->json([
                'success' => false,
                'message' => 'Versión de la aplicación no compatible. Descarga la última versión.',
            ], 426); // 426 Upgrade Required
        }

        return $next($request);
    }
}
