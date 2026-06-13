/*
 * Modern File Viewer - client-side detection mirror.
 *
 * The PHP Content.php endpoint is the authority for a file's language (it can
 * sniff content). This client map is a fast optimistic guess used before the
 * fetch resolves, and a mirror of the extension table for consistency.
 */
(function (w) {
  "use strict";

  var EXT_MAP = {
    json: "json", json5: "json", jsonc: "json",
    yml: "yaml", yaml: "yaml",
    toml: "toml",
    ini: "ini", conf: "ini", cfg: "ini", cnf: "ini", env: "ini", properties: "properties",
    xml: "xml", plist: "xml", svg: "xml", plg: "xml",
    md: "markdown", markdown: "markdown", mdown: "markdown",
    html: "html", htm: "html", xhtml: "html",
    css: "css", scss: "scss", less: "less",
    sh: "sh", bash: "sh", zsh: "sh", ksh: "sh", run: "sh",
    py: "python", rb: "ruby", pl: "perl", pm: "perl",
    php: "php", phtml: "php", page: "php",
    js: "javascript", mjs: "javascript", cjs: "javascript",
    jsx: "jsx", ts: "typescript", tsx: "tsx",
    lua: "lua", go: "golang", rs: "rust",
    c: "c_cpp", h: "c_cpp", cpp: "c_cpp", cc: "c_cpp", hpp: "c_cpp",
    java: "java", kt: "kotlin", swift: "swift", sql: "sql",
    dockerfile: "dockerfile",
    service: "ini", timer: "ini", mount: "ini", socket: "ini",
    nginx: "nginx", desktop: "ini",
    txt: "text", log: "text", text: "text", bak: "text", old: "text", sample: "text", example: "text"
  };

  var BASENAME_MAP = {
    dockerfile: "dockerfile", makefile: "makefile", gnumakefile: "makefile",
    jenkinsfile: "groovy", vagrantfile: "ruby", gemfile: "ruby", rakefile: "ruby",
    ".env": "ini", ".gitignore": "text", ".gitconfig": "ini",
    ".bashrc": "sh", ".bash_profile": "sh", ".profile": "sh", ".zshrc": "sh",
    ".editorconfig": "ini", ".npmrc": "ini"
  };

  var IMAGE_EXTS = ["png", "jpg", "jpeg", "gif", "webp", "bmp", "ico", "avif"];

  function basename(path) {
    var p = String(path || "").replace(/\/+$/, "");
    var i = p.lastIndexOf("/");
    return i >= 0 ? p.slice(i + 1) : p;
  }

  function extOf(name) {
    var i = name.lastIndexOf(".");
    return i > 0 ? name.slice(i + 1).toLowerCase() : "";
  }

  // Optimistic guess from the name alone; null if unknown (defer to server).
  function guess(path) {
    var name = basename(path);
    var lower = name.toLowerCase();
    var ext = extOf(lower);
    if (IMAGE_EXTS.indexOf(ext) >= 0) return { isImage: true, language: "" };
    if (BASENAME_MAP[lower]) return { isImage: false, language: BASENAME_MAP[lower] };
    if (ext && EXT_MAP[ext]) return { isImage: false, language: EXT_MAP[ext] };
    return null;
  }

  w.MFVDetect = { guess: guess, basename: basename, EXT_MAP: EXT_MAP, IMAGE_EXTS: IMAGE_EXTS };
})(window);
