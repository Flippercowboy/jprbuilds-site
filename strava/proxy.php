<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cache_file = sys_get_temp_dir() . '/strava_' . md5($_GET['endpoint'] ?? '') . '.json';
$cache_ttl = 300;

if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_ttl)) {
    echo file_get_contents($cache_file);
    exit;
}

// Get fresh access token
$token_response = file_get_contents('https://www.strava.com/oauth/token', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'client_id'     => STRAVA_CLIENT_ID,
            'client_secret' => STRAVA_CLIENT_SECRET,
            'refresh_token' => STRAVA_REFRESH_TOKEN,
            'grant_type'    => 'refresh_token',
        ])
    ]
]));

$token = json_decode($token_response, true);
$access_token = $token['access_token'];

// Call Strava API
$endpoint = $_GET['endpoint'] ?? 'athlete/activities';
$params = $_GET;
unset($params['endpoint']);
$url = 'https://www.strava.com/api/v3/' . $endpoint . '?' . http_build_query($params);

$response = file_get_contents($url, false, stream_context_create([
    'http' => ['header' => 'Authorization: Bearer ' . $access_token]
]));

file_put_contents($cache_file, $response);
echo $response;
