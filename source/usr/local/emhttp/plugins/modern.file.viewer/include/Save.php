<?php
/**
 * Modern File Viewer - gated file save.
 *
 *   POST { path, content, csrf_token, mtime? }
 *
 * Re-validates CSRF, path confinement, and the edit permission server-side
 * (never trusting the client). Writes atomically (temp file in the same
 * directory + rename) and preserves the original owner / group / mode so share
 * files are not silently flipped to root. If mtime is supplied and the file has
 * changed since it was loaded, the write is refused as a concurrent-edit guard.
 */

require_once __DIR__ . '/Auth.php';

function mfv_save_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  mfv_save_json(['ok' => false, 'error' => 'POST required.'], 405);
}

mfv_require_csrf();

$path = mfv_valid_path($_POST['path'] ?? null);
if ($path === null) {
  mfv_save_json(['ok' => false, 'error' => 'File not found or outside an allowed share (/mnt, /boot).'], 404);
}

$decision = mfv_edit_decision($path);
if (!$decision['editable']) {
  mfv_save_json(['ok' => false, 'error' => 'Not permitted: ' . $decision['reason']], 403);
}

if (!array_key_exists('content', $_POST)) {
  mfv_save_json(['ok' => false, 'error' => 'Missing content.'], 400);
}
$content = (string)$_POST['content'];

// Concurrent-edit guard.
$stat = @stat($path);
$currentMtime = $stat ? (int)$stat['mtime'] : 0;
$clientMtime = isset($_POST['mtime']) ? (int)$_POST['mtime'] : 0;
if ($clientMtime > 0 && $currentMtime > 0 && $clientMtime !== $currentMtime) {
  mfv_save_json([
    'ok' => false,
    'error' => 'File changed on disk since it was opened. Reload before saving to avoid overwriting changes.',
    'code' => 'stale',
  ], 409);
}

// Capture original metadata to restore after the atomic replace.
$origUid  = $stat ? (int)$stat['uid'] : -1;
$origGid  = $stat ? (int)$stat['gid'] : -1;
$origMode = $stat ? ($stat['mode'] & 0777) : 0644;

$dir = dirname($path);
$tmp = @tempnam($dir, '.mfv_');
if ($tmp === false) {
  mfv_save_json(['ok' => false, 'error' => 'Unable to create a temp file in the target directory.'], 500);
}

$bytes = @file_put_contents($tmp, $content);
if ($bytes === false) {
  @unlink($tmp);
  mfv_save_json(['ok' => false, 'error' => 'Write failed.'], 500);
}

// Restore owner/group/mode onto the temp file before swapping it in.
if ($origUid >= 0) @chown($tmp, $origUid);
if ($origGid >= 0) @chgrp($tmp, $origGid);
@chmod($tmp, $origMode);

if (!@rename($tmp, $path)) {
  @unlink($tmp);
  mfv_save_json(['ok' => false, 'error' => 'Atomic replace failed.'], 500);
}

clearstatcache(true, $path);
$newStat = @stat($path);

mfv_save_json([
  'ok'    => true,
  'bytes' => $bytes,
  'mtime' => $newStat ? (int)$newStat['mtime'] : 0,
]);
