<?php
/*
colorobs.php
============

Minimal storage for ColorChecker OBS Match (index.html): keeps the measured
patch values of the two slots on the server so that image 1 can be measured on
one device (e.g. iPhone) and the OBS screenshot on another (e.g. MacBook).
Each save overwrites the previous file of that slot. No images are stored,
only 24 x 3 patch values plus file name, status text and time.

USAGE
-----
  Save (POST, JSON body, called by index.html):
    POST colorobs.php?save=ref     body: {"v":1,"rgb":[[r,g,b] x 24],"name":"IMG_1234.jpg","msg":"..."}
    POST colorobs.php?save=scr     same for the screenshot slot

  Load (GET):
    GET  colorobs.php?load=ref     -> stored JSON, or 404 {"error":"..."}
    GET  colorobs.php?load=scr

  Test from a shell:
    curl -s 'https://example.org/colorobs/colorobs.php?load=ref'
    curl -s -X POST -H 'Content-Type: application/json' \
         --data @ref.json 'https://example.org/colorobs/colorobs.php?save=ref'

Required inputs
  - JSON body for save: "rgb" = exactly 24 arrays of 3 numbers in 0..1;
    "name" and "msg" optional strings (truncated to 200 / 300 characters).

Setup
  - Place next to index.html. PHP must be able to write the folder "data/"
    (created on first save, with a .htaccess that denies direct web access).
  - Protect the whole folder with HTTP basic auth (see htaccess.example),
    because without it anyone reaching the page can read and overwrite values.

Dependencies
  - PHP >= 7.4, built-in functions only (json, file system).
*/

declare(strict_types=1);

const DATA_DIR  = __DIR__ . '/data';
const MAX_BYTES = 8192;
const SLOTS     = ['ref', 'scr'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_text($v, int $max): string
{
    if (!is_string($v)) {
        return '';
    }
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
    // UTF-8 safe truncation without the mbstring extension
    return preg_match('/^.{0,' . $max . '}/us', trim($v), $m) ? $m[0] : '';
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
        out(500, ['error' => 'Cannot create data folder; check write permissions next to colorobs.php.']);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
}

$isSave = isset($_GET['save']);
$slot   = $isSave ? $_GET['save'] : ($_GET['load'] ?? null);
if (!is_string($slot) || !in_array($slot, SLOTS, true)) {
    out(400, ['error' => 'Use ?save=ref|scr (POST) or ?load=ref|scr (GET).']);
}
$file = DATA_DIR . '/' . $slot . '.json';

// ------------------------------------------------------------------ load
if (!$isSave) {
    if (!is_file($file)) {
        out(404, ['error' => 'Nothing saved yet for this slot.']);
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        out(500, ['error' => 'Stored file cannot be read.']);
    }
    http_response_code(200);
    echo $raw;
    exit;
}

// ------------------------------------------------------------------ save
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['error' => 'Saving needs POST.']);
}
$raw = file_get_contents('php://input', false, null, 0, MAX_BYTES + 1);
if ($raw === false || $raw === '') {
    out(400, ['error' => 'Empty request.']);
}
if (strlen($raw) > MAX_BYTES) {
    out(413, ['error' => 'Request too large.']);
}
$in = json_decode($raw, true);
if (!is_array($in) || !isset($in['rgb']) || !is_array($in['rgb']) || count($in['rgb']) !== 24) {
    out(422, ['error' => 'Expected "rgb" with exactly 24 patches.']);
}
$rgb = [];
foreach (array_values($in['rgb']) as $i => $p) {
    if (!is_array($p) || count($p) !== 3) {
        out(422, ['error' => 'Patch ' . ($i + 1) . ' needs 3 values.']);
    }
    $row = [];
    foreach (array_values($p) as $v) {
        if (!(is_int($v) || is_float($v)) || !is_finite((float)$v) || $v < 0 || $v > 1) {
            out(422, ['error' => 'Patch ' . ($i + 1) . ': values must be numbers between 0 and 1.']);
        }
        $row[] = round((float)$v, 6);
    }
    $rgb[] = $row;
}

ensure_data_dir();
$doc = [
    'v'     => 1,
    'slot'  => $slot,
    'rgb'   => $rgb,
    'name'  => clean_text($in['name'] ?? '', 200),
    'msg'   => clean_text($in['msg'] ?? '', 300),
    'saved' => gmdate('Y-m-d\TH:i:s\Z'),
];
$json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$tmp  = DATA_DIR . '/.' . $slot . '.' . bin2hex(random_bytes(4)) . '.tmp';
if ($json === false || file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
    @unlink($tmp);
    out(500, ['error' => 'Cannot write data file; check write permissions of data/.']);
}
out(200, ['ok' => true, 'slot' => $slot, 'saved' => $doc['saved']]);
