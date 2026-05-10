<?php
/**
 * One-time import: creates parkrun_results table and inserts all historical results.
 *
 * Run from server:
 *   sudo php /var/www/html/strava/parkrun_import.php
 *
 * Or via browser (requires BACKFILL_KEY in config.php):
 *   https://jprbuilds.com/strava/parkrun_import.php?key=YOUR_KEY
 */

set_time_limit(0);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if (!defined('BACKFILL_KEY') || $key !== BACKFILL_KEY) {
        http_response_code(403); die('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
}

function say(string $msg): void {
    echo $msg . "\n";
    if (php_sapi_name() !== 'cli') { ob_flush(); }
    flush();
}

$pdo = get_db();

$pdo->exec("CREATE TABLE IF NOT EXISTS parkrun_results (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_date        DATE NOT NULL,
    event_name      VARCHAR(100) NOT NULL,
    event_number    INT NOT NULL,
    finish_time     VARCHAR(8) NOT NULL,
    finish_seconds  INT NOT NULL,
    position        INT,
    parkrun_count   INT,
    UNIQUE KEY uq_date_event (run_date, event_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

say("Table ready. Inserting results...\n");

// [run_date, event_name, event_number, finish_time, finish_seconds, position, parkrun_count]
$results = [
    ['2019-09-21', 'Daventry',              259, '00:29:17', 1757,  155,  1],
    ['2019-10-05', 'Daventry',              261, '00:25:24', 1524,   91,  2],
    ['2019-10-12', 'Daventry',              262, '00:29:32', 1772,  144,  3],
    ['2019-11-02', 'Burnham and Highbridge',227, '00:28:36', 1716, null,  4],
    ['2019-11-09', 'Daventry',              266, '00:28:34', 1714,  134,  5],
    ['2020-01-11', 'Batemans Bay',          191, '00:30:42', 1842, null,  6],
    ['2021-07-24', 'Daventry',              284, '00:26:03', 1563,   64,  7],
    ['2021-07-31', 'Daventry',              285, '00:25:54', 1554,   61,  8],
    ['2021-08-28', 'Daventry',              289, '00:24:08', 1448,   50,  9],
    ['2021-09-11', 'Daventry',              291, '00:24:07', 1447,   42, 10],
    ['2021-09-18', 'Daventry',              292, '00:23:50', 1430,   46, 11],
    ['2021-09-25', 'Daventry',              293, '00:32:32', 1952,  120, 12],
    ['2021-10-02', 'Burnham and Highbridge',256, '00:24:36', 1476, null, 13],
    ['2021-10-16', 'Daventry',              296, '00:30:48', 1848,  138, 14],
    ['2021-10-23', 'Daventry',              297, '00:30:09', 1809,  123, 15],
    ['2021-11-13', 'Daventry',              300, '00:26:12', 1572,   55, 16],
    ['2021-11-20', 'Daventry',              301, '00:31:18', 1878,  112, 17],
    ['2022-01-01', 'Daventry',              306, '00:28:54', 1734,  100, 18],
    ['2022-01-08', 'Daventry',              307, '00:28:31', 1711,   85, 19],
    ['2022-04-23', 'Daventry',              321, '00:31:43', 1903,  133, 20],
    ['2022-05-07', 'Daventry',              323, '00:29:38', 1778,  122, 21],
    ['2022-05-14', 'Daventry',              324, '00:31:48', 1908,  130, 22],
    ['2022-05-21', 'Burnham and Highbridge',288, '00:26:38', 1598, null, 23],
    ['2022-05-28', 'Daventry',              326, '00:31:08', 1868,  141, 24],
    ['2022-06-04', 'Daventry',              327, '00:30:28', 1828,  153, 25],
    ['2022-06-11', 'Daventry',              328, '00:29:28', 1768,  102, 26],
    ['2022-06-18', 'Daventry',              329, '00:27:40', 1660,   77, 27],
    ['2022-06-25', 'Burnham and Highbridge',293, '00:25:51', 1551, null, 28],
    ['2022-09-03', 'Daventry',              340, '00:29:30', 1770,  111, 29],
    ['2022-09-24', 'Daventry',              343, '00:25:46', 1546,   52, 30],
    ['2022-10-22', 'Daventry',              347, '00:25:15', 1515,   44, 31],
    ['2022-11-05', 'Burnham and Highbridge',312, '00:24:33', 1473, null, 32],
    ['2022-11-12', 'Daventry',              350, '00:24:24', 1464,   47, 33],
    ['2022-11-19', 'Daventry',              351, '00:25:33', 1533,   63, 34],
    ['2022-12-31', 'Daventry',              357, '00:30:42', 1842,  131, 35],
    ['2023-01-14', 'Daventry',              359, '00:29:42', 1782,  125, 36],
    ['2023-01-21', 'Daventry',              360, '00:27:37', 1657,   90, 37],
    ['2023-01-28', 'Batemans Bay',          287, '00:26:31', 1591, null, 38],
    ['2023-02-04', 'Burley Griffin',        303, '00:26:36', 1596, null, 39],
    ['2023-07-08', 'Daventry',              383, '00:36:48', 2208,  185, 40],
    ['2023-07-15', 'Daventry',              384, '00:30:03', 1803,   99, 41],
    ['2023-07-22', 'Daventry',              385, '00:26:48', 1608,   76, 42],
    ['2023-07-29', 'Daventry',              386, '00:27:47', 1667,   94, 43],
    ['2023-10-21', 'Daventry',              398, '00:34:51', 2091,  155, 44],
    ['2023-10-28', 'Burnham and Highbridge',362, '00:29:17', 1757, null, 45],
    ['2023-11-11', 'Daventry',              401, '00:29:09', 1749,  132, 46],
    ['2023-11-18', 'Daventry',              402, '00:30:57', 1857,  148, 47],
    ['2023-11-25', 'Daventry',              403, '00:30:16', 1816,  124, 48],
    ['2023-12-30', 'Daventry',              409, '00:31:42', 1902,  159, 49],
    ['2024-01-01', 'Daventry',              410, '00:30:36', 1836,  162, 50],
    ['2024-01-06', 'Daventry',              411, '00:29:18', 1758,  165, 51],
    ['2024-01-20', 'Daventry',              413, '00:29:00', 1740,  135, 52],
    ['2024-02-24', 'Daventry',              418, '00:29:17', 1757,  127, 53],
    ['2024-03-02', 'Daventry',              419, '00:28:01', 1681,  101, 54],
    ['2024-03-09', 'Daventry',              420, '00:27:44', 1664,   85, 55],
    ['2024-06-22', 'Burnham and Highbridge',394, '00:37:03', 2223, null, 56],
    ['2025-01-04', 'Daventry',              464, '00:34:11', 2051,  281, 57],
    ['2025-01-18', 'Daventry',              465, '00:30:45', 1845,  180, 58],
    ['2025-02-08', 'Daventry',              468, '00:32:13', 1933,  187, 59],
    ['2025-02-22', 'Daventry',              470, '00:30:37', 1837,  162, 60],
    ['2025-03-01', 'Daventry',              471, '00:31:32', 1892,  195, 61],
    ['2025-03-08', 'Daventry',              472, '00:28:03', 1683,  120, 62],
    ['2025-03-15', 'Daventry',              473, '00:29:18', 1758,  161, 63],
    ['2025-03-22', 'Daventry',              474, '00:28:11', 1691,  106, 64],
    ['2025-03-29', 'Burnham and Highbridge',433, '00:27:32', 1652, null, 65],
    ['2025-04-05', 'Daventry',              476, '00:30:15', 1815,  163, 66],
    ['2025-04-12', 'Daventry',              477, '00:28:08', 1688,  127, 67],
    ['2025-04-19', 'Daventry',              478, '00:30:09', 1809,  193, 68],
    ['2025-04-26', 'Daventry',              479, '00:28:05', 1685,  131, 69],
    ['2025-05-03', 'Daventry',              480, '00:26:30', 1590,  103, 70],
    ['2025-05-10', 'Burnham and Highbridge',439, '00:30:45', 1845, null, 71],
    ['2025-05-17', 'Daventry',              482, '00:24:18', 1458,   62, 72],
    ['2025-05-31', 'Daventry',              484, '00:25:05', 1505,   64, 73],
    ['2025-06-07', 'Daventry',              485, '00:23:34', 1414,   46, 74],
    ['2025-06-14', 'Daventry',              486, '00:24:55', 1495,   68, 75],
    ['2025-06-21', 'Daventry',              487, '00:25:38', 1538,   90, 76],
    ['2025-06-28', 'Daventry',              488, '00:27:33', 1653,  100, 77],
    ['2025-07-05', 'Daventry',              489, '00:23:54', 1434,   33, 78],
    ['2025-07-12', 'Daventry',              490, '00:25:25', 1525,   67, 79],
    ['2025-07-19', 'Daventry',              491, '00:26:37', 1597,   76, 80],
    ['2025-08-02', 'Daventry',              493, '00:24:29', 1469,   61, 81],
    ['2025-08-09', 'Daventry',              494, '00:23:58', 1438,   52, 82],
    ['2025-08-16', 'Daventry',              495, '00:24:28', 1468,   52, 83],
    ['2025-08-23', 'Daventry',              496, '00:23:48', 1428,   48, 84],
    ['2025-09-13', 'Daventry',              499, '00:25:32', 1532,   44, 85],
    ['2025-09-27', 'Daventry',              501, '00:26:33', 1593,  109, 86],
    ['2025-10-04', 'Daventry',              502, '00:26:12', 1572,   77, 87],
    ['2025-10-11', 'Daventry',              503, '00:26:31', 1591,   75, 88],
    ['2025-10-18', 'Daventry',              504, '00:28:05', 1685,   93, 89],
    ['2026-01-10', 'Daventry',              516, '00:29:41', 1781,  186, 90],
    ['2026-01-17', 'Daventry',              517, '00:30:00', 1800,  147, 91],
    ['2026-01-24', 'Daventry',              518, '00:31:18', 1878,  171, 92],
    ['2026-02-07', 'Daventry',              520, '00:30:37', 1837,  137, 93],
    ['2026-02-14', 'Daventry',              521, '00:28:49', 1729,  108, 94],
    ['2026-02-21', 'Burnham and Highbridge',480, '00:34:49', 2089, null, 95],
    ['2026-03-07', 'Daventry',              524, '00:30:13', 1813,  171, 96],
    ['2026-03-14', 'Daventry',              525, '00:29:47', 1787,  162, 97],
    ['2026-03-21', 'Daventry',              526, '00:35:44', 2144,  250, 98],
    ['2026-03-28', 'Daventry',              527, '00:32:25', 1945,  208, 99],
    ['2026-04-04', 'Burnham and Highbridge',486, '00:34:18', 2058, null,100],
    ['2026-04-18', 'Daventry',              530, '00:28:34', 1714,  150,101],
    ['2026-04-25', 'Daventry',              531, '00:34:25', 2065,  252,102],
    ['2026-05-02', 'Daventry',              532, '00:34:01', 2041,  271,103],
    ['2026-05-09', 'Daventry',              533, '00:33:22', 2002,  255,104],
];

$stmt = $pdo->prepare("INSERT INTO parkrun_results
    (run_date, event_name, event_number, finish_time, finish_seconds, position, parkrun_count)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        finish_time=VALUES(finish_time), finish_seconds=VALUES(finish_seconds),
        position=VALUES(position), parkrun_count=VALUES(parkrun_count)");

$count = 0;
foreach ($results as $r) {
    $stmt->execute($r);
    $count++;
}

say("Done. {$count} parkrun results stored.");
