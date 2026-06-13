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

/** Best-effort video MIME from extension, falling back to magic bytes. */
function mfv_video_mime(string $path, string $sample = ''): string {
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $map = mfv_video_ext_mime();
  if (isset($map[$ext])) return $map[$ext];
  // Extensionless / unknown: sniff so the browser still gets a usable type.
  if (strncmp($sample, "\x1A\x45\xDF\xA3", 4) === 0) return 'video/webm';     // matroska/webm
  if (strncmp($sample, 'OggS', 4) === 0) return 'video/ogg';
  if (strncmp($sample, 'FLV', 3) === 0) return 'video/x-flv';
  if (strlen($sample) >= 12 && strncmp($sample, 'RIFF', 4) === 0
      && strncmp(substr($sample, 8, 4), 'AVI ', 4) === 0) return 'video/x-msvideo';
  if (strlen($sample) >= 12 && strncmp(substr($sample, 4, 4), 'ftyp', 4) === 0) return 'video/mp4';
  return 'video/mp4'; // safest default for an HTML5 <video> attempt
}

/** Best-effort audio MIME from extension, falling back to magic bytes. */
function mfv_audio_mime(string $path, string $sample = ''): string {
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $map = mfv_audio_ext_mime();
  if (isset($map[$ext])) return $map[$ext];
  if (strncmp($sample, 'ID3', 3) === 0) return 'audio/mpeg';
  if (strlen($sample) >= 2 && ord($sample[0]) === 0xFF && (ord($sample[1]) & 0xE0) === 0xE0) return 'audio/mpeg';
  if (strncmp($sample, 'fLaC', 4) === 0) return 'audio/flac';
  if (strncmp($sample, 'OggS', 4) === 0) return 'audio/ogg';
  if (strlen($sample) >= 12 && strncmp($sample, 'RIFF', 4) === 0
      && strncmp(substr($sample, 8, 4), 'WAVE', 4) === 0) return 'audio/wav';
  return 'audio/mpeg';
}

/** True if $bin is an executable on PATH. */
function mfv_have(string $bin): bool {
  $out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
  return is_string($out) && trim($out) !== '';
}

/** Run a command, returning raw stdout (may be binary), or null on empty/failure. */
function mfv_run_capture(array $argv): ?string {
  $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>/dev/null';
  $out = @shell_exec($cmd);
  return (is_string($out) && $out !== '') ? $out : null;
}

/** Largest embedded JPEG inside a file (pure PHP; no external tools). */
function mfv_embedded_jpeg(string $path): ?string {
  $data = @file_get_contents($path, false, null, 0, 64 * 1024 * 1024);
  if ($data === false || $data === '') return null;
  $best = null; $bestLen = 0; $offset = 0;
  while (($soi = strpos($data, "\xFF\xD8\xFF", $offset)) !== false) {
    $eoi = strpos($data, "\xFF\xD9", $soi + 3);
    if ($eoi === false) break;
    $jLen = $eoi + 2 - $soi;
    if ($jLen > $bestLen) { $bestLen = $jLen; $best = substr($data, $soi, $jLen); }
    $offset = $eoi + 2;
  }
  return ($best !== null && $bestLen > 2048) ? $best : null;
}

/**
 * Extract a viewable JPEG preview from a camera RAW file. Tries accurate
 * external tools first (exiftool/dcraw/ImageMagick), then a pure-PHP scan for
 * the embedded JPEG so it still works on a box with no extra packages.
 */
function mfv_extract_raw_preview(string $path): ?string {
  if (mfv_have('exiftool')) {
    foreach (['-JpgFromRaw', '-PreviewImage', '-ThumbnailImage'] as $tag) {
      $out = mfv_run_capture(['exiftool', '-b', $tag, $path]);
      if ($out !== null && strncmp($out, "\xFF\xD8\xFF", 3) === 0) return $out;
    }
  }
  if (mfv_have('dcraw')) {
    $out = mfv_run_capture(['dcraw', '-e', '-c', $path]);
    if ($out !== null && strncmp($out, "\xFF\xD8\xFF", 3) === 0) return $out;
  }
  if (mfv_have('convert')) {
    $out = mfv_run_capture(['convert', $path . '[0]', 'jpg:-']);
    if ($out !== null && strncmp($out, "\xFF\xD8\xFF", 3) === 0) return $out;
  }
  return mfv_embedded_jpeg($path);
}

/**
 * Stream a file with HTTP range support. Browsers require 206 Partial Content
 * to start and seek <video>. Handles normal and suffix ranges, 416 on an
 * unsatisfiable range, and streams in chunks so large files never buffer whole.
 */
function mfv_stream_range($fh, int $size, string $mime): void {
  // A long video stream must not be cut off by the script time limit or by
  // output buffering / compression layered on top.
  @set_time_limit(0);
  @ini_set('zlib.output_compression', 'Off');
  while (ob_get_level() > 0) ob_end_clean();

  header('Content-Type: ' . $mime);
  header('Accept-Ranges: bytes');
  header('X-Content-Type-Options: nosniff');

  $start = 0;
  $end = $size - 1;
  $range = $_SERVER['HTTP_RANGE'] ?? '';

  if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
    if ($m[1] === '' && $m[2] !== '') {
      // suffix range: final N bytes
      $start = max(0, $size - (int)$m[2]);
    } else {
      $start = (int)$m[1];
      if ($m[2] !== '') $end = (int)$m[2];
    }
    if ($size === 0 || $start > $end || $start >= $size) {
      http_response_code(416);
      header("Content-Range: bytes */$size");
      fclose($fh);
      exit;
    }
    $end = min($end, $size - 1);
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
  } else {
    http_response_code(200);
  }

  $length = $end - $start + 1;
  header('Content-Length: ' . $length);

  fseek($fh, $start);
  $remaining = $length;
  $chunk = 262144; // 256 KiB
  while ($remaining > 0 && !feof($fh)) {
    $read = $remaining > $chunk ? $chunk : $remaining;
    $buf = fread($fh, $read);
    if ($buf === false || $buf === '') break;
    echo $buf;
    flush();
    $remaining -= strlen($buf);
    if (connection_aborted()) break;
  }
  fclose($fh);
  exit;
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

// --- raw streaming mode (images + video + audio + RAW previews) --------------
if (($_GET['raw'] ?? '') === '1') {
  if ($detected['isVideo']) {
    // Range-aware streaming so <video> can play and seek.
    mfv_stream_range($fh, $size, mfv_video_mime($path, $sample));
  }
  if ($detected['isAudio']) {
    mfv_stream_range($fh, $size, mfv_audio_mime($path, $sample));
  }
  if (!empty($detected['isRaw'])) {
    // Camera RAW: serve the extracted embedded JPEG preview.
    fclose($fh);
    $jpeg = mfv_extract_raw_preview($path);
    if ($jpeg === null) {
      mfv_json(['ok' => false, 'error' => 'No embedded preview found in this RAW file. Install exiftool for best results.'], 415);
    }
    @set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($jpeg));
    header('Content-Disposition: inline; filename="' . rawurlencode(pathinfo($path, PATHINFO_FILENAME)) . '.jpg"');
    header('X-Content-Type-Options: nosniff');
    echo $jpeg;
    exit;
  }
  if (!$detected['isImage']) {
    fclose($fh);
    mfv_json(['ok' => false, 'error' => 'Not a previewable media file.'], 415);
  }
  // SVG is served as a downloadable image; the browser renders it in <img>,
  // which does not execute embedded scripts (unlike inline <svg>).
  header('Content-Type: ' . mfv_image_mime($path, $sample));
  header('Content-Length: ' . $size);
  header('Accept-Ranges: bytes');
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
  'isVideo'     => $detected['isVideo'],
  'isAudio'     => $detected['isAudio'],
  'isRaw'       => $detected['isRaw'],
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

if ($detected['isImage'] || $detected['isVideo'] || $detected['isAudio'] || $detected['isBinary']) {
  // No inline text payload for images/video/audio/binaries.
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
