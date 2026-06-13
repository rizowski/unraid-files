<?php
/**
 * Modern File Viewer - persist a filename -> language override.
 *
 *   POST { path, language, scope, csrf_token }
 *     scope = "basename"  -> store under byBasename[basename(path)]  (default)
 *     scope = "path"      -> store under byPath[realpath(path)]
 *     language = ""       -> remove the existing override for that key
 *
 * Writes /boot/config/plugins/modern.file.viewer/filetypes.json atomically.
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/TypeDetect.php';

function mfv_settype_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  mfv_settype_json(['ok' => false, 'error' => 'POST required.'], 405);
}

mfv_require_csrf();

$path = mfv_valid_path($_POST['path'] ?? null);
if ($path === null) {
  mfv_settype_json(['ok' => false, 'error' => 'File not found or outside an allowed share (/mnt, /boot).'], 404);
}

$language = (string)($_POST['language'] ?? '');
$remove = ($language === '');
if (!$remove && !mfv_is_valid_language($language)) {
  mfv_settype_json(['ok' => false, 'error' => 'Unknown language: ' . $language], 400);
}

$scope = ($_POST['scope'] ?? 'basename') === 'path' ? 'path' : 'basename';

$overrides = mfv_load_overrides();

if ($scope === 'path') {
  $key = $path;                       // already canonical realpath
  if ($remove) unset($overrides['byPath'][$key]);
  else $overrides['byPath'][$key] = $language;
} else {
  $key = basename($path);             // basename only; no path separators
  if ($remove) unset($overrides['byBasename'][$key]);
  else $overrides['byBasename'][$key] = $language;
}

// Atomic write to the flash config.
$dir = dirname(MFV_OVERRIDE_FILE);
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$tmp = @tempnam($dir, '.mfv_ft_');
if ($tmp === false) {
  mfv_settype_json(['ok' => false, 'error' => 'Unable to write override store.'], 500);
}
$json = json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($tmp, $json) === false || !@rename($tmp, MFV_OVERRIDE_FILE)) {
  @unlink($tmp);
  mfv_settype_json(['ok' => false, 'error' => 'Failed to persist override store.'], 500);
}
@chmod(MFV_OVERRIDE_FILE, 0664);

mfv_settype_json([
  'ok'       => true,
  'scope'    => $scope,
  'key'      => $key,
  'language' => $remove ? null : $language,
  'removed'  => $remove,
]);
