<?php

/**
 * Create the configured PostgreSQL database if it is missing (local/dev).
 * Uses Laravel config; does not print credentials.
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$default = (string) config('database.default');
$c = config('database.connections.'.$default);
if (! is_array($c) || ($c['driver'] ?? '') !== 'pgsql') {
    fwrite(STDERR, "Default connection is not pgsql; nothing to create.\n");
    exit(0);
}

$host = (string) ($c['host'] ?? '127.0.0.1');
$port = (string) ($c['port'] ?? '5432');
$name = (string) ($c['database'] ?? '');
$user = (string) ($c['username'] ?? '');
$pass = (string) ($c['password'] ?? '');

if ($name === '') {
    fwrite(STDERR, "DB database name is empty.\n");
    exit(1);
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $host, $port);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$exists = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
$exists->execute(['name' => $name]);
if ($exists->fetchColumn()) {
    fwrite(STDOUT, "Database already exists.\n");
    exit(0);
}

$quoted = '"'.str_replace('"', '""', $name).'"';
$pdo->exec('CREATE DATABASE '.$quoted);
fwrite(STDOUT, "Created PostgreSQL database.\n");
