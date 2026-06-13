<?php
/**
 * Modern File Viewer - shared type detection.
 *
 * Resolves a file to an ACE editor mode (the canonical language id) and a
 * render kind (image / binary / text). Resolution order, first match wins:
 *   1. user override (filetypes.json: byPath then byBasename)
 *   2. extension / basename map
 *   3. content sniffing (magic bytes -> shebang -> structured-text heuristics)
 *   4. fallback to plain "text"
 *
 * Pure logic only: no request handling, no output. Included by Content.php.
 */

const MFV_OVERRIDE_FILE = '/boot/config/plugins/modern.file.viewer/filetypes.json';

/** Extension -> ACE mode. Keys are lowercase, without the leading dot. */
function mfv_ext_map(): array {
  return [
    // config / data
    'json' => 'json', 'json5' => 'json', 'jsonc' => 'json',
    'yml' => 'yaml', 'yaml' => 'yaml',
    'toml' => 'toml',
    'ini' => 'ini', 'conf' => 'ini', 'cfg' => 'ini', 'cnf' => 'ini',
    'env' => 'ini', 'properties' => 'properties',
    'xml' => 'xml', 'plist' => 'xml', 'svg' => 'xml', 'plg' => 'xml',
    'csv' => 'text', 'tsv' => 'text',
    // markup / docs
    'md' => 'markdown', 'markdown' => 'markdown', 'mdown' => 'markdown',
    'html' => 'html', 'htm' => 'html', 'xhtml' => 'html',
    'css' => 'css', 'scss' => 'scss', 'less' => 'less',
    // scripts / code
    'sh' => 'sh', 'bash' => 'sh', 'zsh' => 'sh', 'ksh' => 'sh', 'run' => 'sh',
    'py' => 'python', 'rb' => 'ruby', 'pl' => 'perl', 'pm' => 'perl',
    'php' => 'php', 'phtml' => 'php', 'page' => 'php',
    'js' => 'javascript', 'mjs' => 'javascript', 'cjs' => 'javascript',
    'jsx' => 'jsx', 'ts' => 'typescript', 'tsx' => 'tsx',
    'lua' => 'lua', 'go' => 'golang', 'rs' => 'rust',
    'c' => 'c_cpp', 'h' => 'c_cpp', 'cpp' => 'c_cpp', 'cc' => 'c_cpp', 'hpp' => 'c_cpp',
    'java' => 'java', 'kt' => 'kotlin', 'swift' => 'swift',
    'sql' => 'sql',
    'dockerfile' => 'dockerfile',
    'service' => 'ini', 'timer' => 'ini', 'mount' => 'ini', 'socket' => 'ini',
    'nginx' => 'nginx', 'desktop' => 'ini',
    // text / logs
    'txt' => 'text', 'log' => 'text', 'text' => 'text',
    'bak' => 'text', 'old' => 'text', 'sample' => 'text', 'example' => 'text',
  ];
}

/** Exact lowercase basename -> ACE mode (for files whose name carries the type). */
function mfv_basename_map(): array {
  return [
    'dockerfile' => 'dockerfile',
    'makefile' => 'makefile', 'gnumakefile' => 'makefile',
    'jenkinsfile' => 'groovy', 'vagrantfile' => 'ruby', 'gemfile' => 'ruby', 'rakefile' => 'ruby',
    '.env' => 'ini', '.gitignore' => 'text', '.gitconfig' => 'ini', '.gitattributes' => 'text',
    '.bashrc' => 'sh', '.bash_profile' => 'sh', '.profile' => 'sh', '.zshrc' => 'sh',
    '.editorconfig' => 'ini', '.npmrc' => 'ini', '.dockerignore' => 'text',
    'go.mod' => 'golang', 'go.sum' => 'text',
  ];
}

/** ACE modes the language picker offers (curated common subset). */
function mfv_picker_languages(): array {
  return [
    'text' => 'Plain text', 'json' => 'JSON', 'yaml' => 'YAML', 'toml' => 'TOML',
    'ini' => 'INI / conf', 'xml' => 'XML', 'markdown' => 'Markdown', 'html' => 'HTML',
    'css' => 'CSS', 'sh' => 'Shell', 'dockerfile' => 'Dockerfile', 'nginx' => 'nginx',
    'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'python' => 'Python',
    'php' => 'PHP', 'ruby' => 'Ruby', 'perl' => 'Perl', 'lua' => 'Lua', 'sql' => 'SQL',
    'c_cpp' => 'C / C++', 'golang' => 'Go', 'rust' => 'Rust', 'java' => 'Java',
    'properties' => 'Properties', 'makefile' => 'Makefile', 'groovy' => 'Groovy',
  ];
}

function mfv_is_valid_language(string $mode): bool {
  return isset(mfv_picker_languages()[$mode]) || in_array($mode, array_values(mfv_ext_map()), true);
}

/** Load the override store; tolerates a missing or corrupt file. */
function mfv_load_overrides(): array {
  $base = ['version' => 1, 'byBasename' => [], 'byPath' => []];
  if (!is_readable(MFV_OVERRIDE_FILE)) return $base;
  $json = json_decode((string)file_get_contents(MFV_OVERRIDE_FILE), true);
  if (!is_array($json)) return $base;
  return [
    'version'    => $json['version'] ?? 1,
    'byBasename' => is_array($json['byBasename'] ?? null) ? $json['byBasename'] : [],
    'byPath'     => is_array($json['byPath'] ?? null) ? $json['byPath'] : [],
  ];
}

/** Returns the override language for $path, or null. byPath beats byBasename. */
function mfv_lookup_override(string $path): ?string {
  $ov = mfv_load_overrides();
  if (isset($ov['byPath'][$path]) && is_string($ov['byPath'][$path])) {
    return $ov['byPath'][$path];
  }
  $base = basename($path);
  if (isset($ov['byBasename'][$base]) && is_string($ov['byBasename'][$base])) {
    return $ov['byBasename'][$base];
  }
  return null;
}

/** Image magic-byte sniff. Returns true if $bytes begins with a known image header. */
function mfv_is_image_magic(string $bytes): bool {
  $len = strlen($bytes);
  if ($len < 4) return false;
  if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) return true;      // png
  if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) return true;             // jpeg
  if (strncmp($bytes, "GIF87a", 6) === 0 || strncmp($bytes, "GIF89a", 6) === 0) return true; // gif
  if (strncmp($bytes, "BM", 2) === 0) return true;                       // bmp
  if (strncmp($bytes, "\x00\x00\x01\x00", 4) === 0) return true;         // ico
  if ($len >= 12 && strncmp($bytes, "RIFF", 4) === 0 && strncmp(substr($bytes, 8, 4), "WEBP", 4) === 0) return true; // webp
  if ($len >= 12 && strncmp(substr($bytes, 4, 4), "ftyp", 4) === 0) {    // avif / heif (ISO-BMFF)
    $brand = substr($bytes, 8, 4);
    if (in_array($brand, ['avif', 'avis', 'mif1', 'heic'], true)) return true;
  }
  return false;
}

/** Heuristic: does this byte sample look like binary (non-text) data? */
function mfv_looks_binary(string $bytes): bool {
  if ($bytes === '') return false;
  if (strpos($bytes, "\x00") !== false) return true;            // NUL byte -> binary
  $sample = substr($bytes, 0, 8000);
  $nonPrintable = 0;
  $len = strlen($sample);
  for ($i = 0; $i < $len; $i++) {
    $c = ord($sample[$i]);
    // allow tab(9) lf(10) cr(13) and printable >= 32; count the rest
    if ($c < 9 || ($c > 13 && $c < 32)) $nonPrintable++;
  }
  return $len > 0 && ($nonPrintable / $len) > 0.10;
}

/** Parse a "#!" shebang into an ACE mode, or null. */
function mfv_sniff_shebang(string $text): ?string {
  if (strncmp($text, '#!', 2) !== 0) return null;
  $line = strtok($text, "\n");
  if ($line === false) return null;
  $line = strtolower($line);
  if (strpos($line, 'python') !== false) return 'python';
  if (strpos($line, 'node') !== false) return 'javascript';
  if (strpos($line, 'perl') !== false) return 'perl';
  if (strpos($line, 'ruby') !== false) return 'ruby';
  if (strpos($line, 'php') !== false) return 'php';
  if (strpos($line, 'lua') !== false) return 'lua';
  if (preg_match('/\b(ba|z|k|d)?sh\b/', $line)) return 'sh';
  if (strpos($line, '/env') !== false || strpos($line, 'sh') !== false) return 'sh';
  return 'sh';
}

/** Structured-text heuristics on a decoded sample. Returns a mode or null. */
function mfv_sniff_structure(string $text): ?string {
  $t = ltrim($text);
  if ($t === '') return null;
  if (strncasecmp($t, '<?xml', 5) === 0) return 'xml';
  if ($t[0] === '<' && preg_match('/^<[a-zA-Z!?]/', $t)) return 'xml';
  if ($t[0] === '{' || $t[0] === '[') {
    if (json_decode($t) !== null || strtolower(trim($t)) === 'null') return 'json';
  }
  // YAML document markers or "key: value" lines.
  if (preg_match('/^---\s*$/m', $t)) return 'yaml';
  $lines = preg_split('/\r?\n/', $t);
  $kvColon = 0; $kvEquals = 0; $section = 0; $checked = 0;
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === '' || $ln[0] === '#' || $ln[0] === ';') continue;
    if (++$checked > 40) break;
    if (preg_match('/^\[[^\]]+\]$/', $ln)) { $section++; continue; }
    if (preg_match('/^[\w.\-]+\s*=\s*/', $ln)) $kvEquals++;
    elseif (preg_match('/^[\w.\-]+\s*:\s+\S/', $ln)) $kvColon++;
  }
  if ($section > 0 && $kvEquals > 0) return 'ini';     // [section] + key=value -> ini/toml family
  if ($kvEquals > 0 && $kvEquals >= $kvColon) return 'ini';
  if ($kvColon > 0) return 'yaml';
  return null;
}

/**
 * Full detection for a file.
 * @param string $path    absolute, already-validated path
 * @param string $sample  the first chunk of file bytes (raw)
 * @return array{language:string,isImage:bool,isBinary:bool,override:bool}
 */
function mfv_detect(string $path, string $sample): array {
  // 1. user override wins outright
  $override = mfv_lookup_override($path);
  if ($override !== null && mfv_is_valid_language($override)) {
    return ['language' => $override, 'isImage' => false, 'isBinary' => false, 'override' => true];
  }

  $base = strtolower(basename($path));
  $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

  // 2a. image by extension or magic bytes. SVG renders as an image too; it is
  // streamed as image/svg+xml and shown in an <img>, which does not execute
  // embedded scripts (unlike inline <svg>).
  $imageExts = ['png','jpg','jpeg','gif','webp','bmp','ico','avif','svg'];
  if (in_array($ext, $imageExts, true) || mfv_is_image_magic($sample)) {
    return ['language' => '', 'isImage' => true, 'isBinary' => false, 'override' => false];
  }

  // 2b. extension / basename map
  $byName = mfv_basename_map();
  if (isset($byName[$base])) {
    return ['language' => $byName[$base], 'isImage' => false, 'isBinary' => false, 'override' => false];
  }
  $byExt = mfv_ext_map();
  if ($ext !== '' && isset($byExt[$ext])) {
    return ['language' => $byExt[$ext], 'isImage' => false, 'isBinary' => false, 'override' => false];
  }

  // 3. content sniffing for unknown / extensionless
  if (mfv_looks_binary($sample)) {
    return ['language' => '', 'isImage' => false, 'isBinary' => true, 'override' => false];
  }
  $mode = mfv_sniff_shebang($sample) ?? mfv_sniff_structure($sample);
  if ($mode !== null) {
    return ['language' => $mode, 'isImage' => false, 'isBinary' => false, 'override' => false];
  }

  // 4. fallback: readable plain text (replaces "Unsupported preview")
  return ['language' => 'text', 'isImage' => false, 'isBinary' => false, 'override' => false];
}
