<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use Illuminate\Support\Facades\Cache;

class MetricsCollector
{
    /**
     * HTTP status codes exported to Prometheus.
     *
     * @var list<int>
     */
    public const STATUS_CODES = [200, 201, 202, 204, 400, 401, 403, 404, 422, 429, 500, 502, 503];

    /**
     * HTTP methods exported to Prometheus.
     *
     * @var list<string>
     */
    public const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Record an HTTP request metric.
     */
    public function recordHttpRequest(string $method, string $path, int $statusCode, float $duration): void
    {
        $this->increment('metrics:http:requests:total');
        $this->increment("metrics:http:requests:status:{$statusCode}");
        $this->increment("metrics:http:methods:{$method}");
        $this->updateAverage('metrics:http:duration:average', $duration);

        // Track success/error counts
        if ($statusCode >= 200 && $statusCode < 300) {
            $this->increment('metrics:http:requests:success');
        } elseif ($statusCode >= 400) {
            $this->increment('metrics:http:requests:errors');
        }
    }

    /**
     * Record an HTTP request attributed to an entry channel (api, agent, graphql, web...).
     */
    public function recordChannelRequest(string $channel, int $statusCode): void
    {
        $channel = $this->sanitizeLabel($channel);

        $this->increment("metrics:http:channel:{$channel}:requests");
        $this->increment("metrics:http:channel:{$channel}:status:{$statusCode}");
        $this->registerIn('metrics:http:channels:registered', $channel);
    }

    /**
     * Record a business transaction attributed to an entry channel.
     */
    public function recordTransaction(string $channel, string $type, int $statusCode): void
    {
        $channel = $this->sanitizeLabel($channel);
        $type    = $this->sanitizeLabel($type);
        $status  = $statusCode >= 200 && $statusCode < 300 ? 'completed' : 'failed';

        $this->increment("metrics:transactions:{$channel}:{$type}:{$status}");
        $this->registerIn('metrics:transactions:registered', "{$channel}|{$type}|{$status}");
    }

    /**
     * Channels seen by the HTTP channel counter.
     *
     * @return list<string>
     */
    public function registeredChannels(): array
    {
        $registered = Cache::get('metrics:http:channels:registered', []);

        return is_array($registered) ? array_values($registered) : [];
    }

    /**
     * Registered transaction series in "channel|type|status" format.
     *
     * @return list<string>
     */
    public function registeredTransactionSeries(): array
    {
        $registered = Cache::get('metrics:transactions:registered', []);

        return is_array($registered) ? array_values($registered) : [];
    }

    /**
     * Record a business event metric.
     */
    public function recordBusinessEvent(string $eventName, array $metadata = []): void
    {
        $this->increment("metrics:events:{$eventName}:total");
        $this->increment('metrics:events:total');
    }

    /**
     * Record an aggregate metric.
     */
    public function recordAggregateMetric(string $aggregateType, string $action, float $duration): void
    {
        $this->increment("metrics:aggregates:{$aggregateType}:{$action}:total");
        $this->updateAverage("metrics:aggregates:{$aggregateType}:duration", $duration);
    }

    /**
     * Record a workflow metric.
     */
    public function recordWorkflowMetric(string $workflowName, string $status, float $duration): void
    {
        $this->increment("metrics:workflows:{$workflowName}:{$status}");

        if ($duration > 0) {
            Cache::put("metrics:workflows:{$workflowName}:duration", (string) $duration);
        }
    }

    /**
     * Record a cache metric.
     */
    public function recordCacheMetric(string $key, bool $hit): void
    {
        if ($hit) {
            $this->increment('metrics:cache:hits');
        } else {
            $this->increment('metrics:cache:misses');
        }
    }

    /**
     * Record a queue metric.
     */
    public function recordQueueMetric(string $queue, string $job, string $status, float $duration): void
    {
        $this->increment("metrics:queue:{$status}");
        $this->updateAverage('metrics:queue:duration', $duration);
    }

    /**
     * Batch record multiple metrics.
     */
    public function batchRecord(array $metrics): void
    {
        foreach ($metrics as $metric) {
            if (isset($metric['name']) && isset($metric['value'])) {
                Cache::put("metrics:custom:{$metric['name']}", $metric['value']);
            }
        }
    }

    /**
     * Increment a counter metric.
     */
    private function increment(string $key): void
    {
        $current = (int) Cache::get($key, 0);
        Cache::put($key, (string) ($current + 1));
    }

    /**
     * Register a value in a cache-backed registry list.
     */
    private function registerIn(string $registryKey, string $value): void
    {
        $registered = Cache::get($registryKey, []);

        if (! is_array($registered)) {
            $registered = [];
        }

        if (! in_array($value, $registered, true)) {
            $registered[] = $value;
            Cache::put($registryKey, $registered);
        }
    }

    /**
     * Sanitize a value so it is safe as a Prometheus label value.
     */
    private function sanitizeLabel(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value === '' ? 'unknown' : substr($value, 0, 32);
    }

    /**
     * Update a running average.
     */
    private function updateAverage(string $key, float $value): void
    {
        $countKey = "{$key}:count";
        $sumKey = "{$key}:sum";

        $count = Cache::get($countKey, 0);
        $sum = Cache::get($sumKey, 0.0);

        $count++;
        $sum += $value;

        Cache::put($countKey, $count);
        Cache::put($sumKey, $sum);
        Cache::put($key, $sum / $count);
    }

    /**
     * Record a custom metric with labels.
     */
    public function recordCustomMetric(string $name, float $value, array $labels = []): void
    {
        $key = "metrics:custom:{$name}";

        if (! empty($labels)) {
            $labelString = $this->formatLabels($labels);
            $key .= ":{$labelString}";
        }

        // Store the metric value
        Cache::put($key, $value);

        // Track that this metric exists
        $metricsKey = 'metrics:custom:registered';
        $registeredMetrics = Cache::get($metricsKey, []);
        if (! in_array($name, $registeredMetrics)) {
            $registeredMetrics[] = $name;
            Cache::put($metricsKey, $registeredMetrics);
        }
    }

    /**
     * Set an alert threshold for a metric.
     */
    public function setAlertThreshold(
        string $metricName,
        float $threshold,
        \App\Domain\Monitoring\ValueObjects\AlertLevel $level,
        string $operator = '>'
    ): void {
        $key = "metrics:thresholds:{$metricName}";
        Cache::put($key, [
            'threshold' => $threshold,
            'level'     => $level->value,
            'operator'  => $operator,
        ]);
    }

    /**
     * Format labels for cache key.
     */
    private function formatLabels(array $labels): string
    {
        ksort($labels);
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = "{$key}={$value}";
        }

        return implode(',', $parts);
    }
}
