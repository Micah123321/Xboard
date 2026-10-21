<?php

declare(strict_types=1);

require '/usr/local/bin/xboard-check-php';
if (!xboardPhpRuntimeIsPatched(PHP_VERSION)) {
    throw new RuntimeException('Unpatched PHP runtime.');
}
foreach (['bcmath', 'curl', 'dom', 'mbstring', 'openssl', 'pcntl', 'pdo_mysql', 'pdo_sqlite', 'redis', 'session', 'sodium', 'swoole', 'zip'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException('Required PHP extension did not load: ' . $extension);
    }
}
if (filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN)) {
    throw new RuntimeException('Session upload progress must stay disabled.');
}
if (ini_get('memory_limit') === '-1') {
    throw new RuntimeException('The image must keep a finite default PHP memory limit.');
}
$db = new PDO('sqlite::memory:');
if ((int) $db->query('SELECT 1')->fetchColumn() !== 1) {
    throw new RuntimeException('SQLite runtime smoke check failed.');
}
$ran = false;
Swoole\Coroutine\run(static function () use (&$ran): void {
    $channel = new Swoole\Coroutine\Channel(1);
    $channel->push('ok');
    $ran = $channel->pop() === 'ok';
});
if (!$ran) {
    throw new RuntimeException('Swoole coroutine smoke check failed.');
}
echo json_encode(['php' => PHP_VERSION, 'swoole' => phpversion('swoole'), 'upload_progress' => false,
    'memory_limit' => ini_get('memory_limit'), 'sqlite' => true, 'swoole_coroutine' => true], JSON_THROW_ON_ERROR), PHP_EOL;
