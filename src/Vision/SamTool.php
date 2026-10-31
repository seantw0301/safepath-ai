<?php

declare(strict_types=1);

namespace SafePath\Vision;

use RuntimeException;

/** Runs helpers/samtool (official SAM parser + image work) and returns its JSON. */
final class SamTool
{
    public static function run(string $command, array $args, ?string $stdin = null): array
    {
        $root = dirname(__DIR__, 2);
        $cmd = [$root . '/helpers/samtool/.venv/bin/python', $root . '/helpers/samtool/samtool.py', $command];
        foreach ($args as $key => $value) {
            $cmd[] = '--' . $key;
            foreach ((array) $value as $v) {
                $cmd[] = (string) $v;
            }
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('samtool failed to start');
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new RuntimeException('samtool ' . $command . ': ' . trim((string) $err));
        }
        return json_decode((string) $out, true) ?? throw new RuntimeException('samtool returned bad JSON');
    }
}
