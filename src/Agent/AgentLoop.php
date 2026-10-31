<?php

declare(strict_types=1);

namespace SafePath\Agent;

use RuntimeException;
use SafePath\Infra\Db;
use SafePath\Model\MetaClient;

/** The multi-turn tool loop: the model picks tools, code runs them, every call lands in the trace. */
final class AgentLoop
{
    public function __construct(private MetaClient $meta, private int $scanId, private string $agent)
    {
    }

    /**
     * @param callable(string, array): array $dispatch runs a tool; throw RuntimeException to tell the model it was wrong
     * @param callable(): bool $done
     * @return int turns used
     */
    public function run(string $system, array $tools, string $task, callable $dispatch, callable $done, int $maxTurns): int
    {
        $input = [['type' => 'message', 'role' => 'user', 'content' => $task]];
        $turn = 0;
        while (!$done() && $turn < $maxTurns) {
            $turn++;
            $output = $this->meta->sparkTurn($system, $input, $tools);
            $input = array_merge($input, $output);
            $calls = array_values(array_filter($output, static fn ($item) => ($item['type'] ?? '') === 'function_call'));
            if ($calls === []) {
                $input[] = ['type' => 'message', 'role' => 'user', 'content' => 'Continue with your tools, and call finish when every hazard is handled.'];
                continue;
            }
            foreach ($calls as $call) {
                $input[] = ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => json_encode($this->call($call, $dispatch), JSON_UNESCAPED_UNICODE)];
            }
        }
        return $turn;
    }

    private function call(array $call, callable $dispatch): array
    {
        $t = microtime(true);
        $args = json_decode((string) $call['arguments'], true) ?? [];
        try {
            $result = $dispatch($call['name'], $args);
        } catch (RuntimeException $e) {
            $result = ['error' => $e->getMessage()]; // the model reads the error and corrects itself
        }
        Db::pdo()->prepare('INSERT INTO scan_steps (scan_id, agent, tool, input_json, output_json, ms) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$this->scanId, $this->agent, $call['name'], json_encode($args, JSON_UNESCAPED_UNICODE), json_encode($result, JSON_UNESCAPED_UNICODE), (int) ((microtime(true) - $t) * 1000)]);
        return $result;
    }

    /** Declares one function tool. */
    public static function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function', 'name' => $name, 'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
        ];
    }
}
