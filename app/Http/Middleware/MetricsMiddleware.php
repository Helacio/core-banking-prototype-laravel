<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Monitoring\Services\MetricsCollector;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MetricsMiddleware
{
    /**
     * Transaction-creating route patterns mapped to a transaction type.
     */
    private const TRANSACTION_ROUTES = [
        '#^api/transfers$#'                       => 'transfer',
        '#^api/v\d+/transfers$#'                  => 'transfer',
        '#^api/v\d+/accounts/[^/]+/deposit$#'     => 'deposit',
        '#^api/v\d+/accounts/[^/]+/withdraw$#'    => 'withdraw',
        '#^api/bian/payment-initiation(?:/.*)?$#' => 'payment',
        '#^api/custodians/[^/]+/transfer$#'       => 'custody',
        '#^api/agent-protocol/.*/payments$#'      => 'agent_payment',
    ];

    public function __construct(
        private readonly MetricsCollector $metrics
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);
        $channel = $this->resolveChannel($request);
        $recordable = ! $this->isInternalMetricsRequest($request);

        try {
            $response = $next($request);
            $duration = microtime(true) - $startTime;

            if ($recordable) {
                // Record request metrics using the MetricsCollector
                $this->metrics->recordHttpRequest(
                    method: $request->method(),
                    path: $request->path(),
                    statusCode: $response->status(),
                    duration: $duration
                );

                // Record channel attribution and business transactions
                $this->metrics->recordChannelRequest($channel, $response->status());
                $this->recordTransaction($request, $channel, $response->status());

                // Track additional metrics for tests (these should already be handled by recordHttpRequest)
                // Cache::increment('metrics:http:requests:total'); // Already done in recordHttpRequest
                Cache::put('metrics:http:duration', $duration, 60);

                // Track by method
                $byMethod = Cache::get('metrics:http:by_method', []);
                $byMethod[$request->method()] = ($byMethod[$request->method()] ?? 0) + 1;
                Cache::put('metrics:http:by_method', $byMethod, 60);

                // Track by path
                $byPath = Cache::get('metrics:http:by_path', []);
                $byPath[$request->path()] = ($byPath[$request->path()] ?? 0) + 1;
                Cache::put('metrics:http:by_path', $byPath, 60);

                // Track error rates (some already handled by recordHttpRequest)
                if ($response->status() >= 400) {
                    Cache::increment('metrics:errors:total');
                    Cache::increment('metrics.http.errors');

                    if ($response->status() >= 500) {
                        Cache::increment('metrics:errors:server');
                    } else {
                        Cache::increment('metrics:errors:client');
                    }
                }
                // Success count already handled by recordHttpRequest

                // Update requests total for legacy test
                Cache::increment('metrics.http.total');
                Cache::put('metrics:requests:total', Cache::get('metrics:http:requests:total', 0), 60);
            }

            return $response;
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            if ($recordable) {
                // Record exception metrics
                $this->metrics->recordHttpRequest(
                    method: $request->method(),
                    path: $request->path(),
                    statusCode: 500,
                    duration: $duration
                );

                $this->metrics->recordChannelRequest($channel, 500);
                $this->recordTransaction($request, $channel, 500);

                // Update cache for tests
                Cache::increment('metrics.http.total');
                Cache::increment('metrics.http.errors');
            }

            throw $e;
        }
    }

    /**
     * Resolve the entry channel for a request.
     *
     * Priority: explicit X-Channel header (e.g. "payproof"), then route prefix.
     */
    private function resolveChannel(Request $request): string
    {
        $header = trim((string) $request->header('X-Channel', ''));

        if ($header !== '') {
            return $header;
        }

        $path = $request->path();

        return match (true) {
            str_starts_with($path, 'api/agent-protocol')          => 'agent',
            str_starts_with($path, 'api/graphql') || $path === 'graphql' => 'graphql',
            str_starts_with($path, 'api/')                        => 'api',
            default                                               => 'web',
        };
    }

    /**
     * Count money-moving requests as business transactions.
     */
    private function recordTransaction(Request $request, string $channel, int $statusCode): void
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $path = $request->path();

        foreach (self::TRANSACTION_ROUTES as $pattern => $type) {
            if (preg_match($pattern, $path) === 1) {
                $this->metrics->recordTransaction($channel, $type, $statusCode);

                return;
            }
        }
    }

    /**
     * Metrics/health endpoints should not pollute business counters.
     */
    private function isInternalMetricsRequest(Request $request): bool
    {
        $path = $request->path();

        return str_starts_with($path, 'api/monitoring') || str_starts_with($path, 'api/metrics');
    }
}
