<?php

namespace App\Services\NodeUpdate;

use App\Exceptions\NodeUpdateException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ReleaseSourceService
{
    public function __construct(private NodeUpdateService $updates) {}

    private function url(): string
    {
        return (string) config('node_updates.manifest_url', 'https://github.com/Micah123321/mi-node/releases/latest/download/node-release.json');
    }

    private function cacheKey(): string { return 'node-update:release-source:'.hash('sha256', $this->url()); }

    public function status(): array
    {
        $state = Cache::get($this->cacheKey(), []);
        $latest = $this->updates->latestRelease();
        return ['url' => $this->url(), 'checked_at' => $state['checked_at'] ?? null,
            'error' => $state['error'] ?? null, 'latest' => $latest ? $this->updates->dto('releases', $latest) : null];
    }

    public function sync(): array
    {
        $error = null;
        try {
            $url = $this->url(); $parts = parse_url($url);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])
                || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) Protocol::fail('invalid_release_source');
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['stream' => true, 'allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($url);
            if (!$response->successful()) Protocol::fail('release_source_unavailable', 503);
            $body = $response->toPsrResponse()->getBody();
            $json = '';
            try {
                while (!$body->eof() && strlen($json) < 131073) {
                    $chunk = $body->read(min(8192, 131073 - strlen($json)));
                    if ($chunk === '') break;
                    $json .= $chunk;
                }
            } finally { $body->close(); }
            if (strlen($json) > 131072) Protocol::fail('invalid_release_manifest');
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) Protocol::fail('invalid_release_manifest');
            $this->updates->importRelease($data);
        } catch (NodeUpdateException $exception) {
            $error = $exception->errorCode;
        } catch (\JsonException $exception) {
            $error = 'invalid_release_manifest';
        } catch (\Throwable $exception) {
            $error = 'release_source_unavailable';
        }
        Cache::forever($this->cacheKey(), ['checked_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'), 'error' => $error]);
        if ($error) Protocol::fail($error, 503);
        return $this->status();
    }
}
