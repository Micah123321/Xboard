<?php

namespace App\Services\NodeUpdate;

use App\Exceptions\NodeUpdateException;

final class Protocol
{
    public const TERMINAL = ['succeeded','failed','rolled_back','rollback_failed','canceled','skipped'];
    public const STATES = ['queued','claimed','downloading','verifying_artifacts','installing','verifying','rolling_back','uncertain','succeeded','failed','rolled_back','rollback_failed','canceled','skipped'];
    public const CODES = ['already_current','download_failed','checksum_mismatch','size_mismatch','binary_version_mismatch','arch_mismatch','backup_failed','lease_expired','policy_disabled','batch_paused','batch_canceled','release_revoked','restart_failed','health_failed','rollback_legacy_health','rollback_failed','manual_resolution'];

    public static function fail(string $code = 'validation_failed', int $status = 422, array $extra = []): never
    {
        throw new NodeUpdateException($code, $status, $extra);
    }

    public static function fields(array $data, array $required, array $optional = []): void
    {
        if (array_diff(array_keys($data), array_merge($required,$optional)) || array_diff($required,array_keys($data))) self::fail();
    }

    public static function uuid($value): void
    {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)) self::fail();
    }

    public static function integer($value, int $min = 1, int $max = 9007199254740991): void
    {
        if (!is_int($value) || $value < $min || $value > $max) self::fail();
    }

    public static function boolean($value): void
    {
        if (!is_bool($value)) self::fail();
    }

    public static function version($value): bool
    {
        return is_string($value) && strlen($value)<=64 && preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$value);
    }

    public static function compare(string $a, string $b): int
    {
        $aa=explode('.',substr($a,1)); $bb=explode('.',substr($b,1));
        foreach ($aa as $n=>$part) {
            $cmp = strlen($part)<=>strlen($bb[$n]);
            if (!$cmp) $cmp=strcmp($part,$bb[$n]);
            if ($cmp) return $cmp<=>0;
        }
        return 0;
    }

    public static function pair($data, bool $sha = false): void
    {
        if (!is_array($data)) self::fail();
        self::fields($data,['mi_node','xbctl']);
        foreach ($data as $v) if ($v !== null && ($sha ? !is_string($v) || !preg_match('/^[a-f0-9]{64}$/D',$v) : !self::version($v))) self::fail();
    }

    public static function secret($value): void
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$value) || strlen(base64_decode(strtr($value,'-_','+/').'=', true))!==32) self::fail();
    }

    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    }

    public static function canonical($value): string
    {
        $sort = function ($v) use (&$sort) {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v);
            foreach ($v as &$item) $item=$sort($item);
            return $v;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }

    public static function hash($value): string { return hash('sha256',self::canonical($value)); }
    public static function time($value): ?string { return $value?->utc()->format('Y-m-d\TH:i:s\Z'); }

    public static function message($value): ?string
    {
        if ($value===null) return null;
        if (!is_string($value) || mb_strlen($value)>1024) self::fail();
        return preg_replace(['/(Bearer\s+)\S+/i','/\b(token|secret|password|authorization|api_key)\s*[:=]\s*\S+/i','/\b[A-Za-z0-9_-]{32,}\b/'], '[REDACTED]', $value);
    }

    public static function release(array $data): void
    {
        self::fields($data,['version','os','min_agent_protocol','artifacts']);
        if (!self::version($data['version']) || $data['os']!=='linux') self::fail();
        self::integer($data['min_agent_protocol']);
        if (!is_array($data['artifacts']) || !array_is_list($data['artifacts']) || !in_array(count($data['artifacts']),[2,4])) self::fail();
        $seen=[];
        foreach ($data['artifacts'] as $a) {
            if (!is_array($a)) self::fail();
            self::fields($a,['arch','component','https_url','sha256','size_bytes']);
            if (!in_array($a['arch'],['amd64','arm64'],true) || !in_array($a['component'],['mi-node','xbctl'],true)) self::fail();
            if (!is_string($a['https_url']) || strlen($a['https_url'])>2048 || preg_match('/[\x00-\x20\x7f]/',$a['https_url'])) self::fail();
            $url=parse_url($a['https_url']);
            if (!$url || ($url['scheme']??null)!=='https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) self::fail();
            if (!is_string($a['sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$a['sha256'])) self::fail();
            self::integer($a['size_bytes'],1,536870912);
            $key=$a['arch'].':'.$a['component'];
            if (isset($seen[$key])) self::fail();
            $seen[$key]=true;
        }
        foreach (['amd64','arm64'] as $arch) if (isset($seen[$arch.':mi-node'])!==isset($seen[$arch.':xbctl'])) self::fail();
    }
}
