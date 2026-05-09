<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cache_dir = '/var/www/html/strava/cache';
if (!is_dir($cache_dir)) mkdir($cache_dir, 0755, true);

$action = $_GET['action'] ?? 'activities';

function get_access_token() {
    $r = file_get_contents('https://www.strava.com/oauth/token', false,
        stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'client_id'     => STRAVA_CLIENT_ID,
                'client_secret' => STRAVA_CLIENT_SECRET,
                'refresh_token' => STRAVA_REFRESH_TOKEN,
                'grant_type'    => 'refresh_token',
            ])
        ]]));
    if (!$r) return null;
    $t = json_decode($r, true);
    return $t['access_token'] ?? null;
}

function strava_get($endpoint, $token) {
    $url = 'https://www.strava.com/api/v3/' . $endpoint;
    $r = file_get_contents($url, false, stream_context_create([
        'http' => ['header' => 'Authorization: Bearer ' . $token]
    ]));
    return $r ? json_decode($r, true) : null;
}

if ($action === 'activities') {
    $cache_file = $cache_dir . '/activities.json';
    $cached = file_exists($cache_file) ? json_decode(file_get_contents($cache_file), true) : [];

    $after = 0;
    if (!empty($cached)) {
        $after = max(array_map(fn($a) => strtotime($a['start_date']), $cached));
        if ((time() - filemtime($cache_file)) < 300) {
            echo json_encode(array_values($cached));
            exit;
        }
    }

    $token = get_access_token();
    if (!$token) { http_response_code(500); echo json_encode(['error' => 'Token error']); exit; }

    $url = $after > 0
        ? 'athlete/activities?per_page=50&after=' . $after
        : 'athlete/activities?per_page=50';

    $new = strava_get($url, $token) ?? [];
    $new_runs = array_filter($new, fn($a) => in_array($a['type'] ?? '', ['Run', 'VirtualRun']));

    if (!empty($new_runs)) {
        $indexed = [];
        foreach ($cached as $a) $indexed[$a['id']] = $a;
        foreach ($new_runs as $a) $indexed[$a['id']] = $a;
        uasort($indexed, fn($a, $b) => strtotime($b['start_date']) - strtotime($a['start_date']));
        $cached = array_values($indexed);
        file_put_contents($cache_file, json_encode($cached));
    } else if (empty($cached)) {
        echo json_encode([]);
        exit;
    }

    echo json_encode($cached);
    exit;
}

if ($action === 'activity') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $cache_file = $cache_dir . '/activity_' . $id . '.json';
    if (file_exists($cache_file)) { echo file_get_contents($cache_file); exit; }
    $token = get_access_token();
    if (!$token) { http_response_code(500); echo json_encode(['error' => 'Token error']); exit; }
    $data = strava_get('activities/' . $id, $token);
    if (!$data) { http_response_code(500); echo json_encode(['error' => 'Strava error']); exit; }
    file_put_contents($cache_file, json_encode($data));
    echo json_encode($data);
    exit;
}

if ($action === 'streams') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $cache_file = $cache_dir . '/streams_' . $id . '.json';
    if (file_exists($cache_file)) { echo file_get_contents($cache_file); exit; }
    $token = get_access_token();
    if (!$token) { http_response_code(500); echo json_encode(['error' => 'Token error']); exit; }
    $data = strava_get('activities/' . $id . '/streams?keys=time,latlng,heartrate,altitude,distance,cadence,velocity_smooth&key_by_type=true', $token);
    if (!$data) { http_response_code(500); echo json_encode(['error' => 'Strava error']); exit; }
    file_put_contents($cache_file, json_encode($data));
    echo json_encode($data);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
