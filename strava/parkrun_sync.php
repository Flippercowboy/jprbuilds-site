<?php
/**
 * Syncs parkrun results from Gmail into the parkrun_results table.
 *
 * Searches for emails from parkrun.com, parses each result email,
 * and upserts into the DB. Only processes emails not already stored.
 *
 * Called via proxy.php?action=sync_parkrun&key=BACKFILL_KEY
 * or directly: php parkrun_sync.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Connect to Gmail via IMAP and return mailbox handle.
 */
function imap_connect_gmail(): mixed {
    if (!defined('GMAIL_USER') || !defined('GMAIL_APP_PASSWORD')) {
        throw new RuntimeException('GMAIL_USER and GMAIL_APP_PASSWORD must be defined in config.php');
    }
    $mailbox = '{imap.gmail.com:993/imap/ssl}';
    $conn = imap_open($mailbox, GMAIL_USER, GMAIL_APP_PASSWORD, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if (!$conn) {
        throw new RuntimeException('IMAP connection failed: ' . imap_last_error());
    }
    return $conn;
}

/**
 * Search Gmail for all parkrun result emails and return their UIDs.
 */
function fetch_parkrun_email_ids(mixed $conn): array {
    // parkrun sends from noreply@parkrun.com or results@parkrun.com
    $ids = imap_search($conn, 'FROM "parkrun.com"');
    if (!$ids) {
        // Try subject fallback
        $ids = imap_search($conn, 'SUBJECT "parkrun"');
    }
    return $ids ?: [];
}

/**
 * Get the plain-text body of an email.
 */
function get_email_body(mixed $conn, int $msgNum): string {
    $structure = imap_fetchstructure($conn, $msgNum);
    $body = '';

    if (!isset($structure->parts)) {
        // Single part message
        $body = imap_fetchbody($conn, $msgNum, '1');
        if ($structure->encoding == 3) $body = base64_decode($body);
        elseif ($structure->encoding == 4) $body = quoted_printable_decode($body);
    } else {
        // Multipart - look for text/plain first, then text/html
        foreach ($structure->parts as $i => $part) {
            $partNum = $i + 1;
            if ($part->subtype === 'PLAIN') {
                $body = imap_fetchbody($conn, $msgNum, (string)$partNum);
                if ($part->encoding == 3) $body = base64_decode($body);
                elseif ($part->encoding == 4) $body = quoted_printable_decode($body);
                break;
            }
        }
        // Fallback to HTML if no plain text
        if ($body === '') {
            foreach ($structure->parts as $i => $part) {
                if ($part->subtype === 'HTML') {
                    $body = imap_fetchbody($conn, $msgNum, (string)($i + 1));
                    if ($part->encoding == 3) $body = base64_decode($body);
                    elseif ($part->encoding == 4) $body = quoted_printable_decode($body);
                    $body = strip_tags($body);
                    break;
                }
            }
        }
    }

    return $body;
}

/**
 * Parse a parkrun result email body and header into a structured result.
 * Returns null if the email doesn't look like a results email.
 *
 * parkrun result emails contain lines like:
 *   Daventry parkrun #533
 *   Finish position: 255
 *   Finish time: 00:33:22
 *   You have now completed 104 parkruns
 */
function parse_parkrun_email(string $body, string $subject, string $dateStr): ?array {
    // Normalise whitespace
    $text = preg_replace('/\r\n|\r/', "\n", $body);
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Must contain a finish time to be a results email
    if (!preg_match('/\d{2}:\d{2}:\d{2}/', $text)) return null;

    // --- Event name and number ---
    // "Daventry parkrun #533" or "Burnham and Highbridge parkrun event number 394"
    $event_name   = null;
    $event_number = null;

    if (preg_match('/([A-Za-z][A-Za-z &\-]+?)\s+parkrun\s+#(\d+)/i', $text, $m)) {
        $event_name   = trim($m[1]);
        $event_number = (int)$m[2];
    } elseif (preg_match('/([A-Za-z][A-Za-z &\-]+?)\s+parkrun\s+event\s+number\s+(\d+)/i', $text, $m)) {
        $event_name   = trim($m[1]);
        $event_number = (int)$m[2];
    } elseif (preg_match('/([A-Za-z][A-Za-z &\-]+?)\s+parkrun/i', $subject, $m)) {
        // Fall back to subject for event name, no number
        $event_name = trim($m[1]);
    }

    if (!$event_name) return null;

    // --- Finish time ---
    $finish_time = null;
    if (preg_match('/[Ff]inish\s+[Tt]ime[:\s]+(\d{2}:\d{2}:\d{2})/', $text, $m)) {
        $finish_time = $m[1];
    } elseif (preg_match('/[Tt]ime[:\s]+(\d{2}:\d{2}:\d{2})/', $text, $m)) {
        $finish_time = $m[1];
    } elseif (preg_match('/(\d{2}:\d{2}:\d{2})/', $text, $m)) {
        $finish_time = $m[1];
    }

    if (!$finish_time) return null;

    // Convert HH:MM:SS to seconds
    [$h, $mi, $s] = array_map('intval', explode(':', $finish_time));
    $finish_seconds = $h * 3600 + $mi * 60 + $s;

    // --- Position ---
    $position = null;
    if (preg_match('/[Ff]inish\s+[Pp]osition[:\s]+(\d+)/', $text, $m)) {
        $position = (int)$m[1];
    } elseif (preg_match('/[Pp]osition[:\s]+(\d+)/', $text, $m)) {
        $position = (int)$m[1];
    } elseif (preg_match('/you\s+finished\s+in\s+(\d+)/i', $text, $m)) {
        $position = (int)$m[1];
    }

    // --- parkrun count ---
    $parkrun_count = null;
    if (preg_match('/completed\s+(\d+)\s+parkrun/i', $text, $m)) {
        $parkrun_count = (int)$m[1];
    } elseif (preg_match('/(\d+)\s+parkruns?\s+in\s+total/i', $text, $m)) {
        $parkrun_count = (int)$m[1];
    } elseif (preg_match('/parkrun\s+number\s+(\d+)/i', $text, $m)) {
        $parkrun_count = (int)$m[1];
    } elseif (preg_match('/your\s+(\d+)(st|nd|rd|th)\s+parkrun/i', $text, $m)) {
        $parkrun_count = (int)$m[1];
    }

    // --- Date: parse from email Date header ---
    $run_date = null;
    if ($dateStr) {
        $ts = strtotime($dateStr);
        if ($ts) $run_date = date('Y-m-d', $ts);
    }
    // parkrun results come out Saturday afternoon/evening so date should be same day
    if (!$run_date) return null;

    return [
        'run_date'       => $run_date,
        'event_name'     => $event_name,
        'event_number'   => $event_number ?? 0,
        'finish_time'    => $finish_time,
        'finish_seconds' => $finish_seconds,
        'position'       => $position,
        'parkrun_count'  => $parkrun_count,
    ];
}

/**
 * Main sync function. Returns array of results: ['inserted'=>[], 'updated'=>[], 'skipped'=>int, 'errors'=>[]]
 */
function sync_parkrun_from_gmail(): array {
    $pdo = get_db();
    $conn = imap_connect_gmail();

    // Get all dates already in DB so we can report new vs updated
    $existing = $pdo->query("SELECT run_date, finish_seconds FROM parkrun_results")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);

    $ids = fetch_parkrun_email_ids($conn);

    $inserted = [];
    $updated  = [];
    $skipped  = 0;
    $errors   = [];

    $stmt = $pdo->prepare("INSERT INTO parkrun_results
        (run_date, event_name, event_number, finish_time, finish_seconds, position, parkrun_count)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            event_number=VALUES(event_number),
            finish_time=VALUES(finish_time),
            finish_seconds=VALUES(finish_seconds),
            position=VALUES(position),
            parkrun_count=VALUES(parkrun_count)");

    foreach ($ids as $msgNum) {
        try {
            $header  = imap_headerinfo($conn, $msgNum);
            $subject = isset($header->subject) ? imap_utf8($header->subject) : '';
            $dateStr = $header->date ?? '';

            // Quick filter — skip non-results emails (e.g. newsletters, volunteer emails)
            if (!preg_match('/result|finish|time|parkrun #\d+/i', $subject . ' ' . $dateStr)) {
                // We still parse the body in case subject is generic
            }

            $body   = get_email_body($conn, $msgNum);
            $result = parse_parkrun_email($body, $subject, $dateStr);

            if (!$result) {
                $skipped++;
                continue;
            }

            $isNew = !isset($existing[$result['run_date']]);
            $stmt->execute([
                $result['run_date'],
                $result['event_name'],
                $result['event_number'],
                $result['finish_time'],
                $result['finish_seconds'],
                $result['position'],
                $result['parkrun_count'],
            ]);

            if ($isNew) {
                $inserted[] = $result;
            } else {
                $updated[] = $result;
            }
        } catch (Exception $e) {
            $errors[] = "msg #{$msgNum}: " . $e->getMessage();
        }
    }

    imap_close($conn);

    return [
        'total_emails' => count($ids),
        'inserted'     => $inserted,
        'updated'      => $updated,
        'skipped'      => $skipped,
        'errors'       => $errors,
    ];
}

// ── Entry point ───────────────────────────────────────────────────────────────

if (php_sapi_name() !== 'cli') {
    // Called via web — check key
    $key = $_GET['key'] ?? '';
    if (!defined('BACKFILL_KEY') || $key !== BACKFILL_KEY) {
        http_response_code(403); die('Forbidden');
    }
    header('Content-Type: application/json');
    try {
        echo json_encode(sync_parkrun_from_gmail(), JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    // CLI
    try {
        $result = sync_parkrun_from_gmail();
        echo "Emails found : {$result['total_emails']}\n";
        echo "Inserted     : " . count($result['inserted']) . "\n";
        echo "Updated      : " . count($result['updated']) . "\n";
        echo "Skipped      : {$result['skipped']}\n";
        if ($result['errors']) {
            echo "Errors:\n";
            foreach ($result['errors'] as $e) echo "  $e\n";
        }
        if ($result['inserted']) {
            echo "\nNew results:\n";
            foreach ($result['inserted'] as $r) {
                echo "  {$r['run_date']} {$r['event_name']} #{$r['event_number']} — {$r['finish_time']} pos {$r['position']} (parkrun #{$r['parkrun_count']})\n";
            }
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}
