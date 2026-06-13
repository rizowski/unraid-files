<?php
/**
 * Modern File Viewer - file content + metadata endpoint.
 *
 *   GET ?path=/mnt/...            -> JSON { ok, language, isImage, isBinary,
 *                                          size, content, truncated, owner_*,
 *                                          current_uid, isAdmin, editable,
 *                                          reason, override, mtime }
 *   GET ?path=/mnt/...&raw=1      -> raw bytes streamed with an image MIME type
 *                                    (used by the viewer's <img> tag)
 *
 * All access is confined to /mnt and /boot by mfv_valid_path().
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/TypeDetect.php';

/** Largest text payload returned inline; bigger files are truncated for preview. */
const MFV_MAX_TEXT_BYTES = 2097152;   // 2 MiB
/** Bytes sampled for content sniffing. */
const MFV_SNIFF_BYTES = 65536;

function mfv_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

/** Best-effort image MIME from extension, falling back to magic bytes. */
function mfv_image_mime(string $path, string $sample): string {
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $byExt = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    'ico' => 'image/x-icon', 'avif' => 'image/avif', 'svg' => 'image/svg+xml',
  ];
  if (isset($byExt[$ext])) return $byExt[$ext];
  if (strncmp($sample, "\x89PNG", 4) === 0) return 'image/png';
  if (strncmp($sample, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
  if (strncmp($sample, 'GIF8', 4) === 0) return 'image/gif';
  if (strncmp($sample, 'BM', 2) === 0) return 'image/bmp';
  return 'application/octet-stream';
}

$path = mfv_valid_path($_GET['path'] ?? null);
if ($path === null) {
  mfv_json(['ok' => false, 'error' => 'File not found or outside an allowed share (/mnt, /boot).'], 404);
}

$size = (int)(@filesize($path) ?: 0);
$fh = @fopen($path, 'rb');
if ($fh === false) {
  mfv_json(['ok' => false, 'error' => 'Unable to open file for reading.'], 500);
}
$sample = (string)fread($fh, MFV_SNIFF_BYTES);

$detected = mfv_detect($path, $sample);

// --- raw image streaming mode -------------------------------------------------
if (($_GET['raw'] ?? '') === '1') {
  if (!$detected['isImage']) {
    fclose($fh);
    mfv_json(['ok' => false, 'error' => 'Not an image.'], 415);
  }
  // SVG is served as a downloadable image; the browser renders it in <img>,
  // which does not execute embedded scripts (unlike inline <svg>).
  header('Content-Type: ' . mfv_image_mime($path, $sample));
  header('Content-Length: ' . $size);
  header('Content-Disposition: inline; filename="' . rawurlencode(basename($path)) . '"');
  header('X-Content-Type-Options: nosniff');
  rewind($fh);
  fpassthru($fh);
  fclose($fh);
  exit;
}

// --- metadata + (text) content -----------------------------------------------
$decision = mfv_edit_decision($path);
$stat = @stat($path);
$mtime = $stat ? (int)$stat['mtime'] : 0;

$response = [
  'ok'          => true,
  'path'        => $path,
  'name'        => basename($path),
  'size'        => $size,
  'mtime'       => $mtime,
  'language'    => $detected['language'],
  'isImage'     => $detected['isImage'],
  'isBinary'    => $detected['isBinary'],
  'override'    => $detected['override'],
  'owner_uid'   => $decision['owner_uid'],
  'owner_gid'   => $decision['owner_gid'],
  'owner_label' => mfv_owner_label($decision['owner_uid']),
  'current_uid' => $decision['current_uid'],
  'isAdmin'     => $decision['isAdmin'],
  'editable'    => $decision['editable'],
  'reason'      => $decision['reason'],
  'languages'   => mfv_picker_languages(),
  'rawUrl'      => '/plugins/modern.file.viewer/include/Content.php?raw=1&path=' . rawurlencode($path),
];

if ($detected['isImage'] || $detected['isBinary']) {
  // No inline text payload for images/binaries.
  $response['content'] = null;
  $response['truncated'] = false;
  fclose($fh);
  mfv_json($response);
}

// Text: return up to the cap, flag truncation (editing a truncated file is
// disabled client-side to avoid clobbering the tail).
rewind($fh);
$content = (string)fread($fh, MFV_MAX_TEXT_BYTES);
fclose($fh);
$truncated = $size > MFV_MAX_TEXT_BYTES;

// Guard against invalid UTF-8 reaching the JSON encoder.
if (!mb_check_encoding($content, 'UTF-8')) {
  $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
}

$response['content'] = $content;
$response['truncated'] = $truncated;
$response['editable'] = $response['editable'] && !$truncated;
if ($truncated) {
  $response['reason'] = 'File exceeds ' . (MFV_MAX_TEXT_BYTES / 1048576) . ' MiB; preview truncated, editing disabled.';
}

mfv_json($response);
