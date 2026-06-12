<?php
// Temporary script to fetch Lamoda brands - DELETE AFTER USE
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$url = 'https://www.lamoda.ru/api/v1/brands/list';
$opts = [
    'http' => [
        'method' => 'GET',
        'header' => [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            'Accept: application/json',
        ],
        'timeout' => 30,
    ],
    'ssl' => [
        'verify_peer' => false,
    ]
];
$context = stream_context_create($opts);
$result = file_get_contents($url, false, $context);

if ($result === false) {
    // Try with curl
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$result) {
        echo json_encode(['error' => $err ?: 'Failed to fetch']);
        exit;
    }
}

echo $result;
