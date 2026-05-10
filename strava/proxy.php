<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

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
    try {
        $pdo   = get_db();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM strava_activities")->fetchColumn();

        // Throttle API calls to once every 5 minutes
        $check_file = $cache_dir . '/activities_checked.txt';
        $throttled  = file_exists($check_file) && (time() - filemtime($check_file)) < 300;

        if (!$throttled) {
            $token = get_access_token();
            if ($token) {
                // Always fetch latest 50 — catches new activities AND name/data edits on recent ones
                $recent = strava_get('athlete/activities?per_page=50', $token) ?? [];
                $runs   = array_filter($recent, fn($a) => in_array($a['type'] ?? '', ['Run', 'VirtualRun', 'Ride', 'VirtualRide']));
                if (!empty($runs)) db_upsert_activities($pdo, $runs);
                file_put_contents($check_file, time());
            }
        }

        $rows = $pdo->query("SELECT * FROM strava_activities ORDER BY start_date DESC")->fetchAll();
        echo json_encode(array_map('db_row_to_activity', $rows));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
    }
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

if ($action === 'weather') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

    $weather_file = $cache_dir . '/weather_' . $id . '.json';
    if (file_exists($weather_file)) { echo file_get_contents($weather_file); exit; }

    $act_file = $cache_dir . '/activity_' . $id . '.json';
    if (file_exists($act_file)) {
        $act = json_decode(file_get_contents($act_file), true);
    } else {
        $token = get_access_token();
        if (!$token) { http_response_code(500); echo json_encode(['error' => 'Token error']); exit; }
        $act = strava_get('activities/' . $id, $token);
        if (!$act) { http_response_code(500); echo json_encode(['error' => 'Strava error']); exit; }
        file_put_contents($act_file, json_encode($act));
    }

    $latlng = $act['start_latlng'] ?? [];
    if (count($latlng) < 2) { echo json_encode(['error' => 'no_gps']); exit; }

    $lat   = $latlng[0];
    $lng   = $latlng[1];
    $local = $act['start_date_local'] ?? '';
    $date  = substr($local, 0, 10);
    $hour  = intval(substr($local, 11, 2));

    $days_ago = (time() - strtotime($date)) / 86400;
    $target   = substr($local, 0, 13) . ':00'; // e.g. "2026-05-09T09:00"

    if ($days_ago > 5) {
        // Archive API: returns exactly 24 entries for the requested day
        $api_url = "https://archive-api.open-meteo.com/v1/archive?latitude={$lat}&longitude={$lng}&start_date={$date}&end_date={$date}&hourly=temperature_2m,apparent_temperature,relativehumidity_2m,windspeed_10m,winddirection_10m,weathercode&timezone=auto";
    } else {
        // Forecast API: past_days alone (no start/end date — they conflict), search by timestamp
        $api_url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lng}&past_days=7&forecast_days=1&hourly=temperature_2m,apparent_temperature,relativehumidity_2m,windspeed_10m,winddirection_10m,weathercode&timezone=auto";
    }

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'jprbuilds/1.0',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!$raw) { echo json_encode(['error' => 'api_fail']); exit; }

    $wd = json_decode($raw, true);
    $h  = $wd['hourly'] ?? [];
    if (empty($h['time'])) { echo json_encode(['error' => 'no_data']); exit; }

    // Find the correct hour index
    if ($days_ago > 5) {
        $idx = $hour; // Archive: 24 entries, index == hour
    } else {
        $idx = array_search($target, $h['time']);
        if ($idx === false) { echo json_encode(['error' => 'no_time_match']); exit; }
    }

    $result = [
        'temperature'  => $h['temperature_2m'][$idx]      ?? null,
        'feels_like'   => $h['apparent_temperature'][$idx] ?? null,
        'humidity'     => $h['relativehumidity_2m'][$idx]  ?? null,
        'wind_speed'   => $h['windspeed_10m'][$idx]        ?? null,
        'wind_dir_deg' => $h['winddirection_10m'][$idx]    ?? null,
        'weather_code' => $h['weathercode'][$idx]          ?? null,
    ];

    file_put_contents($weather_file, json_encode($result));
    echo json_encode($result);
    exit;
}

if ($action === 'similar') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    try {
        $pdo  = get_db();
        $self = $pdo->prepare("SELECT distance, type FROM strava_activities WHERE id = ?");
        $self->execute([$id]);
        $act  = $self->fetch();
        if (!$act) { echo json_encode([]); exit; }

        $dist  = (float)$act['distance'];
        $types = in_array($act['type'], ['Run','VirtualRun']) ? ['Run','VirtualRun'] : ['Ride','VirtualRide'];
        $ph    = implode(',', array_fill(0, count($types), '?'));

        $stmt = $pdo->prepare("SELECT id, name, start_date_local, distance, moving_time,
            average_speed, average_heartrate, suffer_score, total_elevation_gain
            FROM strava_activities
            WHERE type IN ({$ph}) AND distance BETWEEN ? AND ? AND id != ?
            ORDER BY start_date DESC LIMIT 5");
        $stmt->execute(array_merge($types, [$dist * 0.85, $dist * 1.15, $id]));
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['distance']             = (float)$r['distance'];
            $r['moving_time']          = (int)$r['moving_time'];
            $r['average_speed']        = $r['average_speed']     ? (float)$r['average_speed']     : null;
            $r['average_heartrate']    = $r['average_heartrate'] ? (float)$r['average_heartrate'] : null;
            $r['suffer_score']         = $r['suffer_score']      ? (int)$r['suffer_score']        : null;
            $r['total_elevation_gain'] = (float)$r['total_elevation_gain'];
        }
        echo json_encode($rows);
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
