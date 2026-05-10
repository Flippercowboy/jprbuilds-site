<?php
/**
 * One-time backfill: pulls all Strava run history into MariaDB.
 *
 * Run from the server:
 *   sudo php /var/www/html/strava/backfill.php
 *
 * Or via browser (requires BACKFILL_KEY defined in config.php):
 *   https://jprbuilds.com/strava/backfill.php?key=YOUR_KEY
 */

set_time_limit(0);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Protect web access
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if (!defined('BACKFILL_KEY') || $key !== BACKFILL_KEY) {
        http_response_code(403); die('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no'); // disable nginx buffering so output streams
}

function say(string $msg): void {
    echo $msg . "\n";
    if (php_sapi_name() !== 'cli') { ob_flush(); }
    flush();
}

// ── Create table if it doesn't exist ────────────────────────────────────────
$pdo = get_db();
$pdo->exec("CREATE TABLE IF NOT EXISTS strava_activities (
    id                   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    name                 VARCHAR(255),
    type                 VARCHAR(50),
    distance             FLOAT,
    moving_time          INT,
    elapsed_time         INT,
    total_elevation_gain FLOAT,
    start_date           DATETIME,
    start_date_local     DATETIME,
    average_speed        FLOAT,
    max_speed            FLOAT,
    average_heartrate    FLOAT,
    max_heartrate        FLOAT,
    suffer_score         INT,
    average_cadence      FLOAT,
    calories             FLOAT,
    pr_count             INT DEFAULT 0,
    summary_polyline     MEDIUMTEXT,
    gear_name            VARCHAR(255),
    gear_distance        FLOAT,
    device_name          VARCHAR(255),
    fetched_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

say("Table ready.");

// ── Get Strava token ─────────────────────────────────────────────────────────
function get_access_token_bf(): ?string {
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

$token = get_access_token_bf();
if (!$token) { say("ERROR: Could not get Strava access token."); exit(1); }
say("Got Strava token. Starting backfill...\n");

// ── Page through all activities ──────────────────────────────────────────────
$page        = 1;
$total_runs  = 0;
$total_pages = 0;

while (true) {
    $url = "https://www.strava.com/api/v3/athlete/activities?per_page=200&page={$page}";
    $raw = file_get_contents($url, false, stream_context_create([
        'http' => ['header' => 'Authorization: Bearer ' . $token]
    ]));

    if (!$raw) { say("ERROR: API request failed on page {$page}."); break; }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data)) {
        say("Page {$page}: no more activities.");
        break;
    }

    $total_pages++;
    $runs = array_filter($data, fn($a) => in_array($a['type'] ?? '', ['Run', 'VirtualRun', 'Ride', 'VirtualRide']));

    if (!empty($runs)) {
        $n = db_upsert_activities($pdo, $runs);
        $total_runs += $n;
        $earliest = min(array_map(fn($a) => $a['start_date'], $runs));
        say("Page {$page}: {$n} runs stored  (earliest: {$earliest})  — total so far: {$total_runs}");
    } else {
        say("Page {$page}: " . count($data) . " activities, none were runs.");
    }

    if (count($data) < 200) {
        say("Last page reached.");
        break;
    }

    $page++;
    sleep(1); // stay well under Strava's 100 req/15 min limit
}

say("\nBackfill complete. {$total_runs} runs stored across {$total_pages} pages.");
