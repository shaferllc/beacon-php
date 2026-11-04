<?php

// Router for `php -S` used by CurlTransportTest.
header('Content-Type: application/json');

if (str_ends_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/rate-limited')) {
    http_response_code(429);
    header('Retry-After: 7');
    echo json_encode(['message' => 'Too many requests.', 'code' => 'rate_limited']);

    return;
}

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'body' => json_decode(file_get_contents('php://input'), true),
]);
