<?php
// Temporary server capability check - delete after use
header('Content-Type: application/json');
echo json_encode([
    'php_version'    => PHP_VERSION,
    'imap_loaded'    => extension_loaded('imap'),
    'curl_loaded'    => extension_loaded('curl'),
    'openssl_loaded' => extension_loaded('openssl'),
    'allow_url_fopen'=> ini_get('allow_url_fopen'),
]);
