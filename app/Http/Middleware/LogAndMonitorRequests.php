<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogAndMonitorRequests
{
    protected bool $logHeaders;

    // 1. Made these nullable with default values to prevent any uninitialized property crashes
    protected ?float $startTime = null;
    protected ?string $requestId = null;

    public function __construct()
    {
        $this->logHeaders = config('logging.channels.api.log_headers', true);
    }

    /**
     * Handle an incoming request.
     * This acts as the pre-controller hook (Incoming Pipeline).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->startTime = (float) microtime(true);

        // Ensure the UUID string has absolutely no trailing whitespace or hidden newlines
        $this->requestId = trim(Str::uuid()->toString());
        $this->requestId = str_replace(["\r", "\n"], '', $this->requestId);

        // Bind the ID to the request attributes context container
        $request->attributes->set('request_id', $this->requestId);
        $request->attributes->set('start_time', $this->startTime);

        // Capture incoming telemetry parameters
        $this->logIncomingRequest($request);

        // Execute the next middleware / controller layer
        $response = $next($request);

        // Sanitize values one last time before pushing them to the outbound headers
        $safeRequestId = str_replace(["\r", "\n"], '', $this->requestId);
        $response->headers->set('X-Request-ID', $safeRequestId);

        $durationMs = round((microtime(true) - $this->startTime) * 1000, 2);
        $safeDuration = str_replace(["\r", "\n"], '', (string) $durationMs);
        $response->headers->set('X-Request-Duration-MS', $safeDuration);

        return $response;
    }


    /**
     * Handle tasks after the response has been successfully sent to the browser.
     * (Post-Response Pipeline - Terminable execution layer).
     */
    public function terminate(Request $request, Response $response): void
    {
        // 3. Pull values out of the request attributes because this is a brand new class instance
        $requestId = $request->attributes->get('request_id', 'unknown');
        $startTime = $request->attributes->get('start_time');

        // Fallback calculation in case handle() was skipped or errored early
        $duration = ($startTime) ? (float) microtime(true) - $startTime : 0.0;
        $durationMs = round($duration * 1000, 2);

        $logData = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
        ];

        // Evaluate warning layers or successful route conditions
        if ($duration > 1.0) {
            Log::channel('daily')->warning("Slow request: " . json_encode($logData));
        } elseif ($response->getStatusCode() >= 400) {
            Log::channel('daily')->warning("Error response: " . json_encode($logData));
        } else {
            Log::channel('daily')->info("Response: " . json_encode($logData));
        }

        // Performance tracking fallback alert for execution delays (> 500ms)
        if ($durationMs > 500.0) {
            Log::channel('daily')->warning("Performance boundary crossed for {$request->method()} /{$request->path()}: Total execution took {$durationMs}ms");
        }
    }

    /**
     * Parse and filter out core sensitive verification parameters.
     */
    protected function logIncomingRequest(Request $request): void
    {
        $path = $request->path();

        $logData = [
            'request_id' => $this->requestId,
            'method' => $request->method(),
            'path' => $path,
            'query_params' => $request->query(),
            'client_ip' => $request->ip() ?? 'unknown',
            'user_agent' => $request->userAgent() ?? 'unknown',
        ];

        if ($this->logHeaders) {
            // Filter sensitive headers safely (Laravel automatically maps case-insensitive headers)
            $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key'];
            $logData['headers'] = collect($request->headers->all())
                ->reject(fn($value, $key) => in_array(strtolower($key), $sensitiveHeaders))
                ->toArray();
        }

        // Separate logging granularity rules matching FastAPI path targets
        if (Str::startsWith($path, ['api', 'verify'])) {
            Log::channel('daily')->info("Request: " . json_encode($logData));
        } else {
            Log::channel('daily')->debug("Request: " . json_encode($logData));
        }
    }
}
