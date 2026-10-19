<?php

// The key and SQLite database travel together in the named Docker volume.
if (! is_file('/data/app-key')) {
    $key = getenv('APP_KEY') ?: 'base64:'.base64_encode(random_bytes(32));
    if (file_put_contents('/data/app-key', $key.PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write /data/app-key. Check volume permissions.');
    }
    chmod('/data/app-key', 0640);
}
if (! is_file('/data/database.sqlite') && ! touch('/data/database.sqlite')) {
    throw new RuntimeException('Cannot create /data/database.sqlite.');
}

// docker exec starts a new process, so Artisan also needs the persisted key through dotenv.
$key = trim(file_get_contents('/data/app-key'));
if (file_put_contents(dirname(__DIR__).'/.env', 'APP_KEY='.$key.PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Cannot write the container environment.');
}
