<?php

// Runs before Composer dependencies exist. Safe to rerun on an existing checkout.
$root = dirname(__DIR__);
if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, 'PHP 8.2 or newer is required. Current: '.PHP_VERSION.PHP_EOL);
    exit(1);
}

$missing = array_values(array_filter(
    ['ctype', 'curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo', 'pdo_sqlite', 'tokenizer', 'xml'],
    fn ($extension) => ! extension_loaded($extension),
));
if ($missing) {
    fwrite(STDERR, 'Enable these PHP extensions: '.implode(', ', $missing).PHP_EOL);
    fwrite(STDERR, 'CLI php.ini: '.(php_ini_loaded_file() ?: '(none)').PHP_EOL);
    exit(1);
}

foreach (['bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
    if (! is_dir($root.'/'.$directory) && ! mkdir($root.'/'.$directory, 0775, true) && ! is_dir($root.'/'.$directory)) {
        throw new RuntimeException('Cannot create '.$directory);
    }
}

$envFile = $root.'/.env';
if (! is_file($envFile)) {
    if (! copy($root.'/.env.example', $envFile)) {
        throw new RuntimeException('Cannot create .env');
    }
    echo 'Created .env from .env.example.'.PHP_EOL;
}
$contents = file_get_contents($envFile);
preg_match('/^APP_KEY[ \t]*=[ \t]*([^\r\n]*)/m', $contents, $match);
if (trim($match[1] ?? '', " \t\"'") === '') {
    $key = 'APP_KEY=base64:'.base64_encode(random_bytes(32));
    $contents = isset($match[0])
        ? preg_replace('/^APP_KEY[ \t]*=[^\r\n]*/m', $key, $contents, 1)
        : $contents.PHP_EOL.$key.PHP_EOL;
    if (file_put_contents($envFile, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write APP_KEY to .env');
    }
    echo 'Generated an application key.'.PHP_EOL;
}

// Laravel's default SQLite path; existing database files are never reset.
$database = $root.'/database/database.sqlite';
if (! is_file($database) && ! touch($database)) {
    throw new RuntimeException('Cannot create the SQLite database.');
}
echo 'Environment ready. PHP '.PHP_VERSION.PHP_EOL;
