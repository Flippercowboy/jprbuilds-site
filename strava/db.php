<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function db_upsert_activities(PDO $pdo, array $activities): int {
    $sql = "INSERT INTO strava_activities
        (id,name,type,distance,moving_time,elapsed_time,total_elevation_gain,
         start_date,start_date_local,average_speed,max_speed,average_heartrate,
         max_heartrate,suffer_score,average_cadence,calories,pr_count,
         summary_polyline,gear_name,gear_distance,device_name)
        VALUES
        (:id,:name,:type,:distance,:moving_time,:elapsed_time,:total_elevation_gain,
         :start_date,:start_date_local,:average_speed,:max_speed,:average_heartrate,
         :max_heartrate,:suffer_score,:average_cadence,:calories,:pr_count,
         :summary_polyline,:gear_name,:gear_distance,:device_name)
        ON DUPLICATE KEY UPDATE
         name=VALUES(name), suffer_score=VALUES(suffer_score),
         calories=VALUES(calories), pr_count=VALUES(pr_count),
         summary_polyline=VALUES(summary_polyline),
         gear_name=VALUES(gear_name), gear_distance=VALUES(gear_distance),
         fetched_at=NOW()";

    $stmt = $pdo->prepare($sql);
    $count = 0;
    foreach ($activities as $a) {
        $stmt->execute([
            ':id'                   => $a['id'],
            ':name'                 => $a['name'] ?? '',
            ':type'                 => $a['type'] ?? '',
            ':distance'             => $a['distance'] ?? 0,
            ':moving_time'          => $a['moving_time'] ?? 0,
            ':elapsed_time'         => $a['elapsed_time'] ?? 0,
            ':total_elevation_gain' => $a['total_elevation_gain'] ?? 0,
            ':start_date'           => substr($a['start_date'] ?? '', 0, 19),
            ':start_date_local'     => substr($a['start_date_local'] ?? '', 0, 19),
            ':average_speed'        => $a['average_speed'] ?? null,
            ':max_speed'            => $a['max_speed'] ?? null,
            ':average_heartrate'    => $a['average_heartrate'] ?? null,
            ':max_heartrate'        => $a['max_heartrate'] ?? null,
            ':suffer_score'         => $a['suffer_score'] ?? null,
            ':average_cadence'      => $a['average_cadence'] ?? null,
            ':calories'             => $a['calories'] ?? null,
            ':pr_count'             => $a['pr_count'] ?? 0,
            ':summary_polyline'     => $a['map']['summary_polyline'] ?? '',
            ':gear_name'            => $a['gear']['name'] ?? null,
            ':gear_distance'        => $a['gear']['distance'] ?? null,
            ':device_name'          => $a['device_name'] ?? null,
        ]);
        $count++;
    }
    return $count;
}

function db_row_to_activity(array $row): array {
    $a = $row;
    $a['map'] = ['summary_polyline' => $row['summary_polyline'] ?? ''];
    if (!empty($row['gear_name'])) {
        $a['gear'] = ['name' => $row['gear_name'], 'distance' => (float)($row['gear_distance'] ?? 0)];
    }
    $a['id']                    = (int)$a['id'];
    $a['distance']              = (float)$a['distance'];
    $a['moving_time']           = (int)$a['moving_time'];
    $a['elapsed_time']          = (int)$a['elapsed_time'];
    $a['total_elevation_gain']  = (float)$a['total_elevation_gain'];
    $a['average_speed']         = $a['average_speed']      !== null ? (float)$a['average_speed']      : null;
    $a['max_speed']             = $a['max_speed']          !== null ? (float)$a['max_speed']          : null;
    $a['average_heartrate']     = $a['average_heartrate']  !== null ? (float)$a['average_heartrate']  : null;
    $a['max_heartrate']         = $a['max_heartrate']      !== null ? (float)$a['max_heartrate']      : null;
    $a['suffer_score']          = $a['suffer_score']       !== null ? (int)$a['suffer_score']         : null;
    $a['average_cadence']       = $a['average_cadence']    !== null ? (float)$a['average_cadence']    : null;
    $a['calories']              = $a['calories']           !== null ? (float)$a['calories']           : null;
    $a['pr_count']              = (int)($a['pr_count'] ?? 0);
    unset($a['summary_polyline'], $a['gear_name'], $a['gear_distance'], $a['fetched_at']);
    return $a;
}
