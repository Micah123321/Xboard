<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AccessTrafficDomainDatabase
{
    private const ROOT = 'https://raw.githubusercontent.com/v2fly/domain-list-community/master/data/';
    private const MAX_BYTES = 8 * 1024 * 1024;
    private static array $cache = [];

    private function paths(): array
    {
        return [config('access_traffic_geosite.update_path', __DIR__ . '/../../storage/app/access-traffic/geosite.json'),
            config('access_traffic_geosite.snapshot_path', __DIR__ . '/../../resources/data/access-traffic/geosite.json')];
    }

    private function database(): array
    {
        foreach ($this->paths() as $path) {
            clearstatcache(true, $path);
            if (!is_file($path) || filesize($path) > self::MAX_BYTES) continue;
            $signature = $path . ':' . filemtime($path) . ':' . filesize($path);
            if (isset(self::$cache[$signature]) && self::$cache[$signature]['expires_at'] > microtime(true)) return self::$cache[$signature]['data'];
            try {
                $data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                if (($data['version'] ?? null) !== 1 || !is_array($data['suffix'] ?? null) || !is_array($data['full'] ?? null)
                    || !is_array($data['groups'] ?? null)) continue;
                self::$cache = [$signature => ['data' => $data, 'expires_at' => microtime(true) + 30]];
                return $data;
            } catch (\Throwable) { continue; }
        }
        return [];
    }

    public function lookup(string $domain): ?array
    {
        $data = $this->database();
        $match = $domain;
        $group = $data['full'][$domain] ?? null;
        if ($group === null) {
            while ($match !== '') {
                if (isset($data['suffix'][$match])) { $group = $data['suffix'][$match]; break; }
                $dot = strpos($match, '.');
                $match = $dot === false ? '' : substr($match, $dot + 1);
            }
        }
        if ($group === null || !isset($data['groups'][$group])) return null;
        [$service, $category] = $data['groups'][$group];
        return compact('service', 'category') + ['confidence' => 'medium',
            'reason' => 'GeoSite 数据库 v2fly/domain-list-community：' . $group . ' / ' . $match
                . '；此可信度表示服务归属，具体页面、播放、下载及用户意图未被采集。'];
    }

    public function status(): array
    {
        $data = $this->database();
        return ['available' => $data !== [], 'source' => 'v2fly/domain-list-community (GeoSite)',
            'updated_at' => $data['updated_at'] ?? null, 'rule_count' => count($data['suffix'] ?? []) + count($data['full'] ?? []),
            'group_count' => count($data['groups'] ?? []), 'skipped_rules' => $data['skipped_rules'] ?? 0];
    }

    public function update(?string $destination = null): array
    {
        $groups = config('access_traffic_geosite.groups', []);
        if (!$groups) throw new RuntimeException('No GeoSite service groups configured.');
        $data = ['version' => 1, 'source' => 'https://github.com/v2fly/domain-list-community', 'license' => 'MIT',
            'updated_at' => time(), 'groups' => $groups, 'suffix' => [], 'full' => [], 'source_hashes' => [], 'skipped_rules' => 0];
        $texts = [];
        $resolving = [];
        $resolved = [];
        $download = function (string $name) use (&$texts, &$data): string {
            if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,100}$/D', $name)) throw new RuntimeException('Invalid GeoSite group.');
            if (isset($texts[$name])) return $texts[$name];
            if (count($texts) >= 256) throw new RuntimeException('GeoSite include limit exceeded.');
            $response = Http::connectTimeout(10)->timeout(25)->retry(2, 250, throw: false)->withOptions(['allow_redirects' => false,
                'progress' => static function ($total, $downloaded): void {
                    if ($total > 1024 * 1024 || $downloaded > 1024 * 1024) throw new RuntimeException('GeoSite source file too large.');
                }])->get(self::ROOT . $name);
            $body = $response->body();
            if (!$response->successful() || strlen($body) > 1024 * 1024 || trim($body) === '' || str_contains($body, '<html')) {
                throw new RuntimeException('GeoSite download failed: ' . $name . ' (HTTP ' . $response->status() . ')');
            }
            $data['source_hashes'][$name] = hash('sha256', $body);
            return $texts[$name] = $body;
        };
        $read = function (string $name) use (&$read, &$resolved, &$resolving, &$data, $download): array {
            if (isset($resolved[$name])) return $resolved[$name];
            if (isset($resolving[$name])) throw new RuntimeException('Cyclic GeoSite include.');
            $resolving[$name] = true;
            $rules = [];
            foreach (preg_split('/\R/', $download($name)) as $line) {
                $line = trim(explode('#', $line, 2)[0]);
                if ($line === '') continue;
                $parts = preg_split('/\s+/', $line);
                $rule = strtolower($parts[0]);
                if (str_starts_with($rule, 'include:')) {
                    // Attribute-filtered includes must not silently become unrestricted lists.
                    if (count($parts) > 1) { $data['skipped_rules']++; continue; }
                    $rules = array_replace($rules, $read(substr($rule, 8)));
                    continue;
                }
                $kind = 'suffix';
                if (str_starts_with($rule, 'full:')) { $kind = 'full'; $rule = substr($rule, 5); }
                elseif (str_starts_with($rule, 'domain:')) $rule = substr($rule, 7);
                elseif (str_contains($rule, ':')) { $data['skipped_rules']++; continue; }
                if (strlen($rule) > 253 || filter_var($rule, FILTER_VALIDATE_IP)
                    || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $rule)) {
                    $data['skipped_rules']++; continue;
                }
                $rules[$kind . ':' . $rule] = [$kind, $rule];
            }
            unset($resolving[$name]);
            return $resolved[$name] = $rules;
        };
        foreach ($groups as $name => [$service, $category]) {
            if (!isset(AccessTrafficClassifier::LABELS[$category])) throw new RuntimeException('Invalid service category.');
            $rules = $read($name);
            if (!$rules) throw new RuntimeException('GeoSite group has no usable domains: ' . $name);
            foreach ($rules as [$kind, $domain]) $data[$kind][$domain] ??= $name;
        }
        ksort($data['suffix']); ksort($data['full']);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) throw new RuntimeException('GeoSite index too large.');
        $destination ??= $this->paths()[0];
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('GeoSite directory creation failed.');
        $temp = tempnam($directory, '.geosite-');
        if ($temp === false) throw new RuntimeException('GeoSite temporary file creation failed.');
        try {
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $destination)) throw new RuntimeException('GeoSite atomic replacement failed.');
            chmod($destination, 0644);
        } finally { if (is_file($temp)) unlink($temp); }
        self::$cache = [];
        return ['rule_count' => count($data['suffix']) + count($data['full']), 'group_count' => count($groups),
            'skipped_rules' => $data['skipped_rules'], 'updated_at' => $data['updated_at']];
    }
}
