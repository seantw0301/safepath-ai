<?php

declare(strict_types=1);

namespace SafePath\Model;

use RuntimeException;
use SafePath\Infra\Env;

/**
 * Hard spend cap. Meta's "Spend Limit" only alerts, so every API call
 * passes through here first. Costs are estimates from .env until pricing is confirmed.
 */
final class UsageGuard
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $dir = Env::get('STORAGE_PATH', 'storage');
        if (!str_starts_with($dir, '/')) {
            $dir = dirname(__DIR__, 2) . '/' . $dir;
        }
        $this->file = $file ?? rtrim($dir, '/') . '/usage.json';
    }

    public function reserve(string $kind, int $units = 1): void
    {
        $state = $this->read();
        $cost = $units * (float) Env::get('COST_' . strtoupper($kind), '0.01');
        $budget = (float) Env::get('USAGE_BUDGET_USD', '120');
        $maxRequests = (int) Env::get('USAGE_MAX_REQUESTS', '5000');

        if ($state['usd'] + $cost > $budget || $state['requests'] + 1 > $maxRequests) {
            throw new RuntimeException(sprintf(
                'usage cap reached: $%.2f of $%.2f, %d of %d requests',
                $state['usd'], $budget, $state['requests'], $maxRequests
            ));
        }
        $state['usd'] += $cost;
        $state['requests']++;
        $state['by_kind'][$kind] = ($state['by_kind'][$kind] ?? 0) + $units;
        $this->write($state);
    }

    public function summary(): array
    {
        return $this->read();
    }

    private function read(): array
    {
        if (!is_file($this->file)) {
            return ['usd' => 0.0, 'requests' => 0, 'by_kind' => []];
        }
        return json_decode((string) file_get_contents($this->file), true);
    }

    private function write(array $state): void
    {
        @mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
