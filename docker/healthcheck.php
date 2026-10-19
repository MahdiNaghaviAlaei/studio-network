<?php

$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1/up', false, $context);
exit($body !== false && str_contains($http_response_header[0] ?? '', ' 200 ') ? 0 : 1);
