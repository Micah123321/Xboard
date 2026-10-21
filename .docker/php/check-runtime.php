<?php

declare(strict_types=1);

// Upstream PHP security releases for CVE-2026-109618 and CVE-2026-109619.
function xboardPhpRuntimeIsPatched(string $version): bool
{
    if (!preg_match('/^(8\.[2-5])\.[0-9]+$/D', $version, $match)) {
        return false;
    }

    $minimum = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
    return version_compare($version, $minimum[$match[1]], '>=');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (!xboardPhpRuntimeIsPatched(PHP_VERSION)) {
        fwrite(STDERR, 'Unsafe PHP runtime ' . PHP_VERSION . ': PHP2Shell requires PHP 8.2.35, 8.3.36, 8.4.27 or 8.5.12 (or a later patch in the same branch).' . PHP_EOL);
        exit(78);
    }
    echo 'PHP runtime security check passed: ', PHP_VERSION, PHP_EOL;
}
