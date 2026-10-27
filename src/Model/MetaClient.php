<?php

declare(strict_types=1);

namespace SafePath\Model;

use RuntimeException;
use SafePath\Infra\Env;

/** Thin client for Meta Model API (https://api.meta.ai/v1). The key is read from .env only and never logged. */
final class MetaClient
{
    public function __construct(private UsageGuard $guard = new UsageGuard())
    {
    }

    /** Segment one image with a text prompt; returns the raw SAM output text (frame lines). */
    public function samImage(string $imagePath, string $prompt): string
    {
        $mime = mime_content_type($imagePath) ?: 'image/jpeg';
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($imagePath));
        $this->guard->reserve('sam_image');
        return $this->outputText($this->post('/responses', [
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
    }

    /** Segment and track a prompt across an uploaded video (file_id from uploadFile). */
    public function samVideo(string $fileId, string $prompt, int $frames): string
    {
        $this->guard->reserve('sam_frame', max(50, $frames)); // billed with a 50-frame minimum
        return $this->outputText($this->post('/responses', [
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
    }

    public function uploadFile(string $path): string
    {
        $this->guard->reserve('upload');
        $res = $this->request('/files', ['purpose' => 'user_data', 'file' => new \CURLFile($path)], false);
        return $res['id'] ?? throw new RuntimeException('upload failed: no id');
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
