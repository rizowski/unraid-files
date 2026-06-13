/*
 * Modern File Viewer - viewer UI + runtime hook into the file manager Browse view.
 *
 * Strategy (defensive, version-drift tolerant):
 *   1. Only activate on the file-manager Browse view.
 *   2. Take over file previews by intercepting clicks on file entries in the
 *      listing (capture phase). For a supported path we open our own modal and
 *      stop the event; anything we cannot extract a path for is left alone, so
 *      the stock viewer keeps working.
 *   3. If the stock EZView preview symbol is present we also wrap it as a
 *      secondary seam, again falling back to the original on anything we do not
 *      handle. We never modify any built-in file on disk.
 *
 * The modal is fully self-contained, so it does not depend on EZView's DOM.
 * The server (Content.php) is the authority on a file's type and edit-ability.
 */
(function (w, d) {
  "use strict";

  var MFV = w.MFV || {};
  var BASE = MFV.base || "/plugins/modern.file.viewer";

  // Selectors used to recognise the Browse view and file rows. These are the
  // one place that depends on file-manager internals; confirm against the
  // installed dynamix.file.manager on a target box if a future Unraid release
  // changes them. Each is tried in turn.
  var CFG = {
    browseMarkers: ["#fileList", ".fileManager", "[id^='Browse']", "table.tablesorter"],
    fileLinkSelectors: ["a[href*='/mnt/']", "a[href*='/boot/']", "[data-path]", "[data-file]"]
  };

  var PATH_RE = /(\/(?:mnt|boot)\/[^"'?#]+)/;

  /* ------------------------------------------------------------------ utils */

  function qs(sel, root) { return (root || d).querySelector(sel); }

  function isBrowsePage() {
    for (var i = 0; i < CFG.browseMarkers.length; i++) {
      if (qs(CFG.browseMarkers[i])) return true;
    }
    // URL fallback: the file manager Browse view.
    return /\/Browse\b/i.test(w.location.pathname) || /Shares\/Browse/i.test(w.location.href);
  }

  function formatBytes(n) {
    if (!n && n !== 0) return "";
    var u = ["B", "KB", "MB", "GB", "TB"], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(1)) + " " + u[i];
  }

  // Does this element (or near ancestor) look like a directory entry rather
  // than a file? We must not hijack folder navigation.
  function looksLikeDir(node) {
    var cls = (node.className && node.className.baseVal) || node.className || "";
    if (typeof cls === "string" && /\b(dir|folder|directory|parent)\b/i.test(cls)) return true;
    if (node.getAttribute) {
      var t = node.getAttribute("data-type") || node.getAttribute("type");
      if (t && /dir|folder/i.test(t)) return true;
    }
    return false;
  }

  // Pull a /mnt or /boot file path out of a clicked element or its ancestors.
  // Returns null for directory entries and trailing-slash (folder) paths so
  // the file manager keeps handling navigation itself.
  function extractPath(el) {
    var node = el, hops = 0;
    while (node && hops < 6) {
      if (node.getAttribute) {
        if (looksLikeDir(node)) return null;
        var dp = node.getAttribute("data-path") || node.getAttribute("data-file");
        var cand = null;
        if (dp && PATH_RE.test(dp)) cand = PATH_RE.exec(dp)[1];
        if (!cand) {
          var href = node.getAttribute("href");
          if (href) {
            try { href = decodeURIComponent(href); } catch (e) {}
            if (PATH_RE.test(href)) cand = PATH_RE.exec(href)[1];
          }
        }
        if (cand) {
          if (cand.charAt(cand.length - 1) === "/") return null;   // folder path
          return cand;
        }
      }
      node = node.parentNode; hops++;
    }
    return null;
  }

  function api(path) {
    return BASE + "/include/" + path;
  }

  function getJSON(url) {
    return fetch(url, { credentials: "same-origin" }).then(function (r) { return r.json(); });
  }

  function postForm(url, fields) {
    var body = new URLSearchParams();
    body.set("csrf_token", MFV.csrf || "");
    Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": MFV.csrf || "" },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  /* ------------------------------------------------------------------ modal */

  var modal = null;   // current modal state

  function closeModal() {
    if (modal) {
      if (modal.editor) { try { modal.editor.destroy(); } catch (e) {} }
      if (modal.el && modal.el.parentNode) modal.el.parentNode.removeChild(modal.el);
      d.removeEventListener("keydown", onKey, true);
      modal = null;
    }
  }

  function onKey(e) {
    if (!modal) return;
    if (e.key === "Escape") {
      if (modal.editing) { e.stopPropagation(); cancelEdit(); }
      else closeModal();
    }
  }

  function el(tag, cls, html) {
    var n = d.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }

  function buildShell(title) {
    var overlay = el("div", "mfv-overlay");
    var box = el("div", "mfv-modal");
    var header = el("div", "mfv-header");
    var titleEl = el("div", "mfv-title");
    titleEl.textContent = title;
    var meta = el("div", "mfv-meta");
    var actions = el("div", "mfv-actions");
    var closeBtn = el("button", "mfv-btn mfv-close", "&times;");
    closeBtn.title = "Close (Esc)";
    closeBtn.addEventListener("click", closeModal);
    header.appendChild(titleEl);
    header.appendChild(meta);
    header.appendChild(actions);
    header.appendChild(closeBtn);
    var bodyEl = el("div", "mfv-body");
    box.appendChild(header);
    box.appendChild(bodyEl);
    overlay.appendChild(box);
    overlay.addEventListener("mousedown", function (e) { if (e.target === overlay) closeModal(); });
    d.body.appendChild(overlay);
    d.addEventListener("keydown", onKey, true);
    return { el: overlay, header: header, meta: meta, actions: actions, body: bodyEl, title: titleEl };
  }

  /* ------------------------------------------------------------------ image */

  function renderImage(shell, info) {
    var wrap = el("div", "mfv-imgwrap");
    var img = el("img", "mfv-img");
    img.src = info.rawUrl;
    img.alt = info.name;
    var scale = 1;
    function apply() { img.style.transform = "scale(" + scale + ")"; }
    function zoom(f) { scale = Math.min(8, Math.max(0.1, scale * f)); apply(); }

    var bar = el("div", "mfv-imgbar");
    var zin = el("button", "mfv-btn", "+"); zin.title = "Zoom in";
    var zout = el("button", "mfv-btn", "&minus;"); zout.title = "Zoom out";
    var zreset = el("button", "mfv-btn", "Reset");
    var dl = el("a", "mfv-btn", "Download"); dl.href = info.rawUrl; dl.setAttribute("download", info.name);
    zin.addEventListener("click", function () { zoom(1.25); });
    zout.addEventListener("click", function () { zoom(0.8); });
    zreset.addEventListener("click", function () { scale = 1; apply(); });
    bar.appendChild(zout); bar.appendChild(zin); bar.appendChild(zreset); bar.appendChild(dl);

    img.addEventListener("wheel", function (e) { e.preventDefault(); zoom(e.deltaY < 0 ? 1.1 : 0.9); }, { passive: false });
    img.addEventListener("load", function () {
      shell.meta.textContent = img.naturalWidth + " x " + img.naturalHeight + " px  -  " + formatBytes(info.size);
    });

    wrap.appendChild(img);
    shell.body.appendChild(wrap);
    shell.actions.appendChild(bar);
  }

  /* ------------------------------------------------------------------- text */

  function makeEditor(container, language, content, readOnly) {
    var ed = w.ace.edit(container);
    try { w.ace.config.set("basePath", MFV.acePath); } catch (e) {}
    ed.setReadOnly(!!readOnly);
    ed.setOptions({ fontSize: "13px", showPrintMargin: false, useWorker: false, wrap: true });
    ed.session.setValue(content || "");
    setMode(ed, language);
    return ed;
  }

  function setMode(ed, language) {
    if (!language || language === "text") { ed.session.setMode("ace/mode/text"); return; }
    ed.session.setMode("ace/mode/" + language);
  }

  function renderText(shell, info) {
    // Language picker.
    var picker = el("select", "mfv-picker");
    var langs = info.languages || { text: "Plain text" };
    Object.keys(langs).forEach(function (k) {
      var o = el("option"); o.value = k; o.textContent = langs[k];
      if (k === (info.language || "text")) o.selected = true;
      picker.appendChild(o);
    });
    var pickerWrap = el("label", "mfv-pickerwrap");
    pickerWrap.appendChild(document.createTextNode("Type "));
    pickerWrap.appendChild(picker);
    var pathOnly = el("input"); pathOnly.type = "checkbox"; pathOnly.className = "mfv-pathonly";
    var pathOnlyLabel = el("label", "mfv-pathonly-label");
    pathOnlyLabel.appendChild(pathOnly);
    pathOnlyLabel.appendChild(document.createTextNode(" this path only"));
    pathOnlyLabel.title = "Save the type for this exact file instead of every file with this name";
    var savedNote = el("span", "mfv-savednote");

    // Editor.
    var edHost = el("div", "mfv-editor");
    shell.body.appendChild(edHost);
    var editor = makeEditor(edHost, info.language, info.content, true);

    picker.addEventListener("change", function () {
      var lang = picker.value;
      setMode(editor, lang);                       // instant re-highlight
      postForm(api("SetType.php"), {
        path: info.path,
        language: lang,
        scope: pathOnly.checked ? "path" : "basename"
      }).then(function (res) {
        savedNote.textContent = res && res.ok ? "saved" : (res && res.error ? "save failed" : "save failed");
        savedNote.className = "mfv-savednote " + (res && res.ok ? "ok" : "err");
        setTimeout(function () { savedNote.textContent = ""; }, 2500);
      });
    });

    shell.actions.appendChild(pickerWrap);
    shell.actions.appendChild(pathOnlyLabel);
    shell.actions.appendChild(savedNote);

    // Edit / Save / Cancel.
    var editBtn = el("button", "mfv-btn mfv-primary", "Edit");
    var saveBtn = el("button", "mfv-btn mfv-primary mfv-hidden", "Save");
    var cancelBtn = el("button", "mfv-btn mfv-hidden", "Cancel");
    var banner = el("div", "mfv-banner mfv-hidden");

    modal.editor = editor;
    modal.original = info.content;

    editBtn.addEventListener("click", function () { startEdit(info, editor, banner, editBtn, saveBtn, cancelBtn); });
    cancelBtn.addEventListener("click", function () { cancelEdit(); });
    saveBtn.addEventListener("click", function () { doSave(info, editor, banner, editBtn, saveBtn, cancelBtn); });

    modal._ui = { editBtn: editBtn, saveBtn: saveBtn, cancelBtn: cancelBtn, banner: banner };

    if (info.editable) {
      shell.actions.appendChild(editBtn);
      shell.actions.appendChild(saveBtn);
      shell.actions.appendChild(cancelBtn);
    } else {
      var ro = el("span", "mfv-readonly");
      ro.textContent = "view only";
      ro.title = info.reason || "";
      shell.actions.appendChild(ro);
    }
    shell.body.appendChild(banner);

    var bits = [];
    bits.push(formatBytes(info.size));
    if (info.owner_label) bits.push("owner " + info.owner_label);
    if (info.truncated) bits.push("truncated");
    shell.meta.textContent = bits.join("  -  ");

    if (info.truncated) {
      var warn = el("div", "mfv-banner mfv-warn");
      warn.textContent = "Large file: showing the first part only; highlighting may be limited and editing is disabled.";
      shell.body.insertBefore(warn, edHost);
    }
  }

  function startEdit(info, editor, banner, editBtn, saveBtn, cancelBtn) {
    modal.editing = true;
    editor.setReadOnly(false);
    editor.focus();
    banner.textContent = "Editing as " + (info.isAdmin ? "administrator" : "uid " + info.current_uid) +
      " - changes preserve the file's owner and permissions.";
    banner.className = "mfv-banner mfv-editing";
    editBtn.classList.add("mfv-hidden");
    saveBtn.classList.remove("mfv-hidden");
    cancelBtn.classList.remove("mfv-hidden");
  }

  function cancelEdit() {
    if (!modal || !modal.editing) return;
    var ui = modal._ui;
    modal.editor.session.setValue(modal.original);
    modal.editor.setReadOnly(true);
    modal.editing = false;
    ui.banner.className = "mfv-banner mfv-hidden";
    ui.saveBtn.classList.add("mfv-hidden");
    ui.cancelBtn.classList.add("mfv-hidden");
    ui.editBtn.classList.remove("mfv-hidden");
  }

  function doSave(info, editor, banner, editBtn, saveBtn, cancelBtn) {
    var content = editor.getValue();
    saveBtn.disabled = true;
    banner.textContent = "Saving...";
    banner.className = "mfv-banner mfv-editing";
    postForm(api("Save.php"), { path: info.path, content: content, mtime: info.mtime })
      .then(function (res) {
        saveBtn.disabled = false;
        if (res && res.ok) {
          modal.original = content;
          info.mtime = res.mtime || info.mtime;
          editor.setReadOnly(true);
          modal.editing = false;
          banner.className = "mfv-banner mfv-hidden";
          saveBtn.classList.add("mfv-hidden");
          cancelBtn.classList.add("mfv-hidden");
          editBtn.classList.remove("mfv-hidden");
          flash("Saved");
        } else {
          banner.textContent = "Save failed: " + ((res && res.error) || "unknown error");
          banner.className = "mfv-banner mfv-warn";
        }
      })
      .catch(function (e) {
        saveBtn.disabled = false;
        banner.textContent = "Save failed: " + e;
        banner.className = "mfv-banner mfv-warn";
      });
  }

  function flash(msg) {
    var f = el("div", "mfv-flash"); f.textContent = msg;
    d.body.appendChild(f);
    setTimeout(function () { f.classList.add("show"); }, 10);
    setTimeout(function () { f.classList.remove("show"); }, 1800);
    setTimeout(function () { if (f.parentNode) f.parentNode.removeChild(f); }, 2200);
  }

  /* ------------------------------------------------------------------ open */

  function openViewer(path) {
    closeModal();
    var shell = buildShell(MFVDetect.basename(path));
    modal = { el: shell.el, editor: null, editing: false, original: null };
    var loading = el("div", "mfv-loading", "Loading...");
    shell.body.appendChild(loading);

    getJSON(api("Content.php") + "?path=" + encodeURIComponent(path))
      .then(function (info) {
        if (loading.parentNode) loading.parentNode.removeChild(loading);
        if (!info || !info.ok) {
          shell.body.appendChild(el("div", "mfv-error", (info && info.error) || "Failed to load file."));
          return;
        }
        if (info.isImage) { renderImage(shell, info); return; }
        if (info.isBinary) {
          var box = el("div", "mfv-binary");
          box.appendChild(el("p", null, "Binary file - no text preview."));
          var dl = el("a", "mfv-btn", "Download"); dl.href = info.rawUrl || (api("Content.php") + "?raw=1&path=" + encodeURIComponent(path));
          dl.setAttribute("download", info.name);
          box.appendChild(dl);
          shell.meta.textContent = formatBytes(info.size);
          shell.body.appendChild(box);
          return;
        }
        if (!w.ace) {
          shell.body.appendChild(el("div", "mfv-error",
            "The bundled ACE editor was not found. Is the built-in file manager installed?"));
          return;
        }
        renderText(shell, info);
      })
      .catch(function (e) {
        if (loading.parentNode) loading.parentNode.removeChild(loading);
        shell.body.appendChild(el("div", "mfv-error", "Failed to load file: " + e));
      });
  }
  w.MFVViewer = { open: openViewer, close: closeModal };

  /* ------------------------------------------------------------------ hooks */

  function onClickCapture(e) {
    if (modal) return;                       // our modal already owns the screen
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var path = extractPath(e.target);
    if (!path) return;                       // not a file entry we recognise -> leave stock behaviour
    e.preventDefault();
    e.stopPropagation();
    openViewer(path);
  }

  function wrapEZView() {
    if (!w.EZView || typeof w.EZView.preview !== "function" || w.EZView.__mfvWrapped) return;
    var orig = w.EZView.preview;
    w.EZView.__mfvWrapped = true;
    w.EZView.preview = function () {
      try {
        var args = Array.prototype.slice.call(arguments);
        var path = null;
        for (var i = 0; i < args.length; i++) {
          if (typeof args[i] === "string" && PATH_RE.test(args[i])) { path = PATH_RE.exec(args[i])[1]; break; }
        }
        if (path) { openViewer(path); return; }
      } catch (err) { /* fall through to stock */ }
      return orig.apply(this, arguments);
    };
  }

  function install() {
    if (!isBrowsePage()) return;
    // Primary seam: intercept file clicks in capture phase.
    d.addEventListener("click", onClickCapture, true);
    // Secondary seam: wrap EZView if/when it appears.
    wrapEZView();
    var tries = 0;
    var iv = setInterval(function () {
      wrapEZView();
      if (++tries > 20 || (w.EZView && w.EZView.__mfvWrapped)) clearInterval(iv);
    }, 500);
  }

  if (d.readyState === "loading") d.addEventListener("DOMContentLoaded", install);
  else install();
})(window, document);
