<?php

declare(strict_types=1);

namespace SafePath\Model;

use RuntimeException;
use SafePath\Infra\Env;

/** Thin client for Meta Model API (https://api.meta.ai/v1). The key is read from .env only and never logged. */
final class MetaClient
{
    /** @var array<string, string> video path => uploaded file id */
    private array $uploadedFiles = [];

    public function __construct(private UsageGuard $guard = new UsageGuard())
    {
    }

    /** Segment one image with a text prompt; returns the raw SAM output text (frame lines). */
    public function samImage(string $imagePath, string $prompt): string
    {
        $cache = $this->cachePath('sam', sha1_file($imagePath) . '|' . $prompt, 'txt');
        if (is_file($cache)) {
            return (string) file_get_contents($cache);
        }
        $mime = mime_content_type($imagePath) ?: 'image/jpeg';
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($imagePath));
        $this->guard->reserve('sam_image');
        $out = $this->outputText($this->post('/responses', [
            'model' => Env::get('META_SAM_MODEL', 'sam-3.1'),
            'input' => [[
                'type' => 'message',
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ['type' => 'input_image', 'image_url' => $dataUrl],
                ],
            ]],
            'metadata' => ['include_confidence' => 'true', 'mask_encoding' => 'one_bit'],
        ]));
        file_put_contents($cache, $out);
        return $out;
    }

    /** Segment and track a prompt across a video; object IDs stay stable between frames. */
    public function samVideo(string $videoPath, string $prompt, int $frames): string
    {
        $cache = $this->cachePath('sam', sha1_file($videoPath) . '|' . $prompt, 'txt');
        if (is_file($cache)) {
            return (string) file_get_contents($cache);
        }
        $fileId = $this->uploadedFiles[$videoPath] ??= $this->uploadFile($videoPath);
        $this->guard->reserve('sam_frame', max(50, $frames)); // billed with a 50-frame minimum
        $out = $this->outputText($this->post('/responses', [
            'model' => Env::get('META_SAM_MODEL', 'sam-3.1'),
            'input' => [[
                'type' => 'message',
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt],
                    ['type' => 'input_video', 'file_id' => $fileId],
                ],
            ]],
            'metadata' => ['include_confidence' => 'true', 'mask_encoding' => 'one_bit'],
        ]));
        file_put_contents($cache, $out);
        return $out;
    }

    public function uploadFile(string $path): string
    {
        $this->guard->reserve('upload');
        $res = $this->request('/files', ['purpose' => 'user_data', 'file' => new \CURLFile($path)], false);
        return $res['id'] ?? throw new RuntimeException('upload failed: no id');
    }

    /** Generate one image; returns raw bytes. */
    public function imageGenerate(string $prompt): string
    {
        $this->guard->reserve('image');
        $res = $this->post('/images/generations', ['model' => 'muse-image-1.0', 'prompt' => $prompt, 'n' => 1]);
        return base64_decode($res['data'][0]['b64_json'] ?? throw new RuntimeException('no image returned'));
    }

    /** Edit an image while keeping its composition; returns raw bytes. */
    public function imageEdit(string $imagePath, string $prompt): string
    {
        $mime = mime_content_type($imagePath) ?: 'image/jpeg';
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($imagePath));
        $this->guard->reserve('image');
        $res = $this->post('/images/edits', [
            'model' => 'muse-image-1.0',
            'prompt' => $prompt,
            'images' => [['image_url' => $dataUrl]],
            'n' => 1,
        ]);
        return base64_decode($res['data'][0]['b64_json'] ?? throw new RuntimeException('no image returned'));
    }

    /** Ask Muse Spark for JSON matching a strict schema. */
    public function sparkJson(string $system, string $user, string $name, array $schema): array
    {
        $cache = $this->cachePath('spark', $system . '|' . $user . '|' . json_encode($schema), 'json');
        if (is_file($cache)) {
            return json_decode((string) file_get_contents($cache), true);
        }
        $this->guard->reserve('spark');
        $res = $this->post('/responses', [
            'model' => Env::get('META_SPARK_MODEL', 'muse-spark-1.3'),
            'instructions' => $system,
            'input' => $user,
            'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'schema' => $schema, 'strict' => true]],
        ]);
        $json = json_decode($this->outputText($res), true);
        if (!is_array($json)) {
            throw new RuntimeException('muse spark returned non-JSON output');
        }
        file_put_contents($cache, json_encode($json, JSON_UNESCAPED_UNICODE));
        return $json;
    }

    /**
     * One agent turn with tools. History is replayed in full (no server state), so a whole
     * deterministic agent run replays from cache at no cost.
     *
     * @return array<int, array> the response's output items
     */
    public function sparkTurn(string $system, array $input, array $tools): array
    {
        $cache = $this->cachePath('agent', $system . '|' . json_encode($input) . '|' . json_encode($tools), 'json');
        if (is_file($cache)) {
            return json_decode((string) file_get_contents($cache), true);
        }
        $this->guard->reserve('spark');
        $res = $this->post('/responses', [
            'model' => Env::get('META_SPARK_MODEL', 'muse-spark-1.3'),
            'instructions' => $system,
            'input' => $input,
            'tools' => $tools,
            'store' => false,
        ]);
        file_put_contents($cache, json_encode($res['output'] ?? [], JSON_UNESCAPED_UNICODE));
        return $res['output'] ?? [];
    }

    /** Same as sparkJson, with images the model looks at. */
    public function sparkVisionJson(string $system, string $user, array $imagePaths, string $name, array $schema): array
    {
        $hashes = implode('|', array_map('sha1_file', $imagePaths));
        $cache = $this->cachePath('spark', $system . '|' . $user . '|' . $hashes . '|' . json_encode($schema), 'json');
        if (is_file($cache)) {
            return json_decode((string) file_get_contents($cache), true);
        }
        $content = [['type' => 'input_text', 'text' => $user]];
        foreach ($imagePaths as $path) {
            $mime = mime_content_type($path) ?: 'image/jpeg';
            $content[] = ['type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path))];
        }
        $this->guard->reserve('spark');
        $res = $this->post('/responses', [
            'model' => Env::get('META_SPARK_MODEL', 'muse-spark-1.3'),
            'instructions' => $system,
            'input' => [['type' => 'message', 'role' => 'user', 'content' => $content]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'schema' => $schema, 'strict' => true]],
        ]);
        $json = json_decode($this->outputText($res), true);
        if (!is_array($json)) {
            throw new RuntimeException('muse spark returned non-JSON output');
        }
        file_put_contents($cache, json_encode($json, JSON_UNESCAPED_UNICODE));
        return $json;
    }

    /** Responses are cached by content hash, so reruns and the demo cost nothing. */
    private function cachePath(string $kind, string $key, string $ext): string
    {
        $dir = dirname(__DIR__, 2) . '/samples/cache/' . $kind;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/' . sha1($key) . '.' . $ext;
    }

    public function transcribe(string $path, string $filename): string
    {
        $this->guard->reserve('transcribe');
        $res = $this->request('/asr/transcribe', [
            'model' => Env::get('META_TRANSCRIBE_MODEL', 'muse-voice-transcribe-1.0'),
            'file' => new \CURLFile($path, null, $filename),
        ], false);
        return (string) ($res['text'] ?? '');
    }

    private function outputText(array $res): string
    {
        $text = '';
        foreach ($res['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $part) {
                $text .= $part['text'] ?? '';
            }
        }
        return $text;
    }

    private function post(string $path, array $body): array
    {
        return $this->request($path, json_encode($body, JSON_UNESCAPED_SLASHES), true);
    }

    private function request(string $path, mixed $body, bool $json): array
    {
        $key = Env::get('META_API_KEY') ?: throw new RuntimeException('META_API_KEY missing in .env');
        $headers = ['Authorization: Bearer ' . $key];
        if ($json) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init(rtrim((string) Env::get('META_API_BASE', 'https://api.meta.ai/v1'), '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            throw new RuntimeException('meta api: ' . curl_error($ch));
        }
        $res = json_decode($raw, true) ?? [];
        if ($code >= 400) {
            throw new RuntimeException(sprintf('meta api %d: %s', $code, $res['error']['message'] ?? 'unknown error'));
        }
        return $res;
    }
}
