<?php

// Router for `php -S`: answers each request with the next scripted reply from
// $ARIANET_MOCK_DIR/replies.json and records the request in seen.json.

$dir = getenv('ARIANET_MOCK_DIR');
$replies = json_decode((string) file_get_contents($dir . '/replies.json'), true) ?: [];
$seen = json_decode((string) @file_get_contents($dir . '/seen.json'), true) ?: [];

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}
$seen[] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
];
file_put_contents($dir . '/seen.json', json_encode($seen));

$reply = array_shift($replies);
file_put_contents($dir . '/replies.json', json_encode($replies));
if ($reply === null) {
    http_response_code(500);
    echo '{"code":"unexpected_request"}';

    return true;
}
http_response_code($reply['status']);
header('Content-Type: application/json');
foreach ($reply['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}
echo $reply['body'] ?? '{}';

return true;
