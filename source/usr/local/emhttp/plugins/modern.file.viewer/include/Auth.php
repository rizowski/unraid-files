<?php
/**
 * Modern File Viewer - shared auth, path validation, and the edit gate.
 *
 * The webGUI runs as root and its login is the box's administrator. Per the
 * design decision, the active rule is "admin can edit anything under /mnt or
 * /boot". The ownership comparison is still computed and exposed so the UI can
 * explain view-only state, and so a future non-admin/API-role session can be
 * restricted to files it owns without changing callers.
 */

/** Roots a path is allowed to resolve under. */
const MFV_ALLOWED_ROOTS = ['/mnt/', '/boot/'];

/** Parse emhttp's var.ini once (CSRF token, etc.). */
function mfv_var(): array {
  static $var = null;
  if ($var === null) {
    $var = @parse_ini_file('/usr/local/emhttp/state/var.ini') ?: [];
  }
  return $var;
}

/**
 * Validate a user-supplied path. Returns the canonical realpath if it resolves
 * to an existing file under an allowed root, otherwise null. realpath() also
 * collapses ".." and resolves symlinks, so a symlink escaping the roots fails.
 */
function mfv_valid_path(?string $path): ?string {
  if ($path === null || $path === '') return null;
  $real = realpath($path);
  if ($real === false || !is_file($real)) return null;
  foreach (MFV_ALLOWED_ROOTS as $root) {
    if (strncmp($real, $root, strlen($root)) === 0) return $real;
  }
  return null;
}

/**
 * Validate the parent directory of a not-yet-existing path (for atomic writes
 * we need the directory under an allowed root). Returns canonical file path or null.
 */
function mfv_valid_target(?string $path): ?string {
  if ($path === null || $path === '') return null;
  $dir = realpath(dirname($path));
  if ($dir === false || !is_dir($dir)) return null;
  foreach (MFV_ALLOWED_ROOTS as $root) {
    if (strncmp($dir, $root, strlen($root)) === 0) {
      return $dir . '/' . basename($path);
    }
  }
  return null;
}

/** Best-effort current webGUI user. Legacy login is the root administrator. */
function mfv_current_user(): array {
  $name = $_SERVER['REMOTE_USER'] ?? ($_SESSION['unraid_user'] ?? 'root');
  $uid  = 0;
  $info = @posix_getpwnam((string)$name);
  if (is_array($info) && isset($info['uid'])) $uid = (int)$info['uid'];
  // The PHP process itself runs as root on the webGUI.
  $procUid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
  return ['name' => (string)$name, 'uid' => $uid, 'procUid' => $procUid];
}

/** Is the current session an administrator (root-equivalent)? */
function mfv_is_admin(): bool {
  $u = mfv_current_user();
  // The legacy webGUI session is the administrator; the process runs as root.
  return $u['procUid'] === 0 || $u['uid'] === 0 || $u['name'] === 'root';
}

/** Verify the emhttp CSRF token on a mutating request; exit 403 on mismatch. */
function mfv_require_csrf(): void {
  $var = mfv_var();
  $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  $expected = $var['csrf_token'] ?? '';
  if ($expected === '' || !hash_equals((string)$expected, (string)$token)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
  }
}

/**
 * Decide whether the current user may edit $realPath and why.
 * @return array{editable:bool,isAdmin:bool,owner_uid:int,owner_gid:int,current_uid:int,reason:string}
 */
function mfv_edit_decision(string $realPath): array {
  $stat = @stat($realPath);
  $ownerUid = $stat ? (int)$stat['uid'] : -1;
  $ownerGid = $stat ? (int)$stat['gid'] : -1;
  $user = mfv_current_user();
  $isAdmin = mfv_is_admin();

  if ($isAdmin) {
    $editable = true;
    $reason = 'Administrator: editing allowed.';
  } else {
    // Forward-looking: a mapped non-admin user may edit only files it owns.
    $editable = ($user['uid'] >= 0 && $user['uid'] === $ownerUid);
    $reason = $editable
      ? 'You own this file.'
      : sprintf('Owned by uid %d; you are uid %d and not the owner.', $ownerUid, $user['uid']);
  }

  return [
    'editable'    => $editable,
    'isAdmin'     => $isAdmin,
    'owner_uid'   => $ownerUid,
    'owner_gid'   => $ownerGid,
    'current_uid' => $user['uid'],
    'reason'      => $reason,
  ];
}

/** Human-readable owner "name (uid)" for display. */
function mfv_owner_label(int $uid): string {
  $info = @posix_getpwuid($uid);
  $name = is_array($info) && isset($info['name']) ? $info['name'] : (string)$uid;
  return sprintf('%s (%d)', $name, $uid);
}
