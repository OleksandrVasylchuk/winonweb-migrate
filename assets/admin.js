(function () {
  'use strict';

  const C = window.WOWM;
  const root = document.querySelector('[data-wowm]');

  if (!C || !root) {
    return;
  }

  const t = C.i18n;
  const $ = (selector, el = root) => el.querySelector(selector);
  const $$ = (selector, el = root) => Array.from(el.querySelectorAll(selector));
  const icon = (name) => C.icons[name] || '';
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const host = (url) => String(url || '').replace(/^https?:\/\//, '');

  const sprintf = (format, ...args) => {
    let i = 0;
    return format.replace(/%(?:(\d)\$)?s/g, (match, n) => args[n ? n - 1 : i++]);
  };

  const bytes = (n) => {
    if (n === null || n === undefined) {
      return '-';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    n = Number(n);
    while (n >= 1024 && i < units.length - 1) {
      n /= 1024;
      i++;
    }
    return (i ? n.toFixed(n < 10 ? 1 : 0) : n) + ' ' + units[i];
  };

  const duration = (seconds) => {
    seconds = Math.max(0, Math.round(seconds));
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
  };

  const date = (ts) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(ts * 1000));

  const relative = (ts) => {
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const diff = (ts * 1000 - Date.now()) / 1000;
    const units = [
      ['year', 31536000],
      ['month', 2592000],
      ['week', 604800],
      ['day', 86400],
      ['hour', 3600],
      ['minute', 60],
    ];
    for (const [unit, size] of units) {
      if (Math.abs(diff) >= size) {
        return rtf.format(Math.round(diff / size), unit);
      }
    }
    return rtf.format(Math.round(diff), 'second');
  };

  /*
   * Server
   */

  async function api(action, data = {}, files = {}, timeout = 120000) {
    const body = new FormData();
    body.append('action', 'wowm_' + action);
    body.append('nonce', C.nonce);

    Object.entries(data).forEach(([key, value]) => body.append(key, typeof value === 'object' ? JSON.stringify(value) : value));
    Object.entries(files).forEach(([key, value]) => body.append(key, value, key));

    // Some hosts hold a request open forever instead of rejecting it.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    let response;
    let text = '';

    try {
      response = await fetch(C.ajax, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
      text = await response.text();
    } catch (e) {
      const error = new Error(e.name === 'AbortError' ? t.timedOut : t.badResponse);
      error.retry = true;
      throw error;
    } finally {
      clearTimeout(timer);
    }

    let json = null;

    try {
      json = JSON.parse(text);
    } catch (e) {
      // Timeouts, fatals and firewalls answer with HTML.
    }

    if (!json) {
      const error = new Error(`${t.badResponse} (HTTP ${response.status})`);
      error.retry = true;
      error.status = response.status;
      // 413: the web server's body size limit is below PHP's, retrying the same size is pointless.
      error.tooLarge = response.status === 413;
      throw error;
    }

    if (!json.success) {
      throw new Error((json.data && json.data.message) || t.failed);
    }

    return json.data;
  }

  // Chunk upload over XHR instead of fetch, so the bar can move while the bytes
  // are still going out (fetch exposes no upload progress). Same error shape as api().
  function apiUpload(action, data, file, onProgress, timeout = 120000) {
    return new Promise((resolve, reject) => {
      const body = new FormData();
      body.append('action', 'wowm_' + action);
      body.append('nonce', C.nonce);
      Object.entries(data).forEach(([key, value]) => body.append(key, value));
      body.append('chunk', file, 'chunk');

      const xhr = new XMLHttpRequest();
      xhr.open('POST', C.ajax, true);
      xhr.withCredentials = true;
      xhr.timeout = timeout;

      xhr.upload.onprogress = (e) => e.lengthComputable && onProgress && onProgress(e.loaded);

      const fail = (message, extra) => {
        const error = new Error(message);
        error.retry = true;
        Object.assign(error, extra || {});
        reject(error);
      };

      xhr.onload = () => {
        let json = null;
        try {
          json = JSON.parse(xhr.responseText);
        } catch (e) {
          // HTML means a timeout, a fatal or a firewall page.
        }
        if (!json) {
          fail(`${t.badResponse} (HTTP ${xhr.status})`, { status: xhr.status, tooLarge: xhr.status === 413 });
        } else if (!json.success) {
          reject(new Error((json.data && json.data.message) || t.failed));
        } else {
          resolve(json.data);
        }
      };

      xhr.onerror = () => fail(t.badResponse);
      xhr.ontimeout = () => fail(t.timedOut);
      xhr.send(body);
    });
  }

  // Jobs are resumable, so a timeout or a dropped connection just means "ask again".
  async function retry(fn, onRetry, attempts = 6) {
    for (let i = 0; ; i++) {
      try {
        return await fn();
      } catch (error) {
        if (!error.retry || error.tooLarge || i >= attempts) {
          throw error;
        }
        onRetry && onRetry(error, i + 1, attempts);
        await sleep(Math.min(15000, 1000 * 2 ** i));
      }
    }
  }

  /*
   * UI helpers
   */

  function toast(message, type = 'success') {
    const el = document.createElement('div');
    el.className = `wowm-toast wowm-toast--${type}`;
    el.innerHTML = icon(type === 'error' ? 'error' : 'check') + `<span>${esc(message)}</span>`;
    $('[data-toasts]').append(el);
    setTimeout(() => {
      el.classList.add('is-leaving');
      setTimeout(() => el.remove(), 300);
    }, 4500);
  }

  function confirmDialog(text, yesLabel) {
    return new Promise((resolve) => {
      const dialog = $('[data-confirm]');
      const yes = $('[data-confirm-yes]');
      $('[data-confirm-text]').textContent = text;
      yes.textContent = yesLabel || t.delete;
      dialog.hidden = false;
      yes.focus();

      const done = (value) => {
        dialog.hidden = true;
        document.removeEventListener('keydown', onKey);
        resolve(value);
      };
      const onKey = (e) => e.key === 'Escape' && done(false);

      yes.onclick = () => done(true);
      $('[data-confirm-no]').onclick = () => done(false);
      document.addEventListener('keydown', onKey);
    });
  }

  function addPair(container, from = '', to = '') {
    const row = document.createElement('div');
    row.className = 'wowm-pair';
    row.innerHTML = `
      <input type="text" data-from spellcheck="false" placeholder="${esc(t.findPlaceholder)}" value="${esc(from)}">
      ${icon('arrow')}
      <input type="text" data-to spellcheck="false" placeholder="${esc(t.replacePlaceholder)}" value="${esc(to)}">
      <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--icon" aria-label="${esc(t.remove)}">${icon('x')}</button>`;
    row.querySelector('button').addEventListener('click', () => row.remove());
    container.append(row);
    row.querySelector('input').focus();
  }

  function readPairs(container) {
    return $$('.wowm-pair', container)
      .map((row) => ({ from: $('[data-from]', row).value, to: $('[data-to]', row).value }))
      .filter((pair) => pair.from.trim() !== '');
  }

  root.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-pair]');
    if (add) {
      addPair($(`[data-pairs="${add.dataset.addPair}"]`));
    }
  });

  /*
   * Tabs
   */

  const tabs = $$('[data-tab]');

  function showTab(name, focus = false) {
    tabs.forEach((tab) => {
      const selected = tab.dataset.tab === name;
      tab.setAttribute('aria-selected', selected);
      tab.tabIndex = selected ? 0 : -1;
      selected && focus && tab.focus();
    });
    $$('[data-panel]').forEach((panel) => (panel.hidden = panel.dataset.panel !== name));
    history.replaceState(null, '', '#' + name);
  }

  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => showTab(tab.dataset.tab));
    tab.addEventListener('keydown', (e) => {
      const step = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
      if (step) {
        showTab(tabs[(i + step + tabs.length) % tabs.length].dataset.tab, true);
      }
    });
  });

  $$('[data-goto]').forEach((el) => el.addEventListener('click', () => showTab(el.dataset.goto)));

  /*
   * Progress modal
   */

  const modal = $('[data-modal]');
  let running = false;

  window.addEventListener('beforeunload', (e) => {
    if (running) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  function openModal(title) {
    modal.hidden = false;
    $('[data-ring]', modal).classList.remove('is-done', 'is-failed');
    $('[data-ring-icon]', modal).innerHTML = '';
    $('[data-title]', modal).textContent = title;
    $('[data-message]', modal).textContent = '';
    $('[data-steps]', modal).innerHTML = '';
    $('[data-stats]', modal).innerHTML = '';
    $('[data-result]', modal).innerHTML = '';
    $('[data-log]', modal).innerHTML = '';
    $('[data-actions]', modal).innerHTML = '';
    setProgress(0);
  }

  function setProgress(pct) {
    pct = Math.max(0, Math.min(100, pct || 0));
    $('.wowm-ring__fg', modal).setAttribute('stroke-dasharray', `${pct} 100`);
    $('[data-percent]', modal).textContent = Math.floor(pct) + '%';
  }

  function renderState(state, onCancel) {
    setProgress(state.progress);

    const active = (state.steps || []).find((step) => step.status === 'active');
    $('[data-message]', modal).textContent = state.message || (active ? active.label + '…' : '');

    $('[data-steps]', modal).innerHTML = (state.steps || [])
      .map((step) => {
        const mark = step.status === 'done' ? icon('check') : step.status === 'failed' ? icon('x') : '';
        return `<li class="wowm-step wowm-step--${step.status}"><span class="wowm-step__dot">${mark}</span>${esc(step.label)}</li>`;
      })
      .join('');

    const stats = [[t.elapsed, duration(state.elapsed || 0)], ...Object.entries(state.stats || {}).map(([key, value]) => [t[key] || key, value])];
    $('[data-stats]', modal).innerHTML = stats.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('');

    $('[data-log]', modal).innerHTML = (state.log || []).map(([ts, message]) => `<li><time>${new Date(ts * 1000).toLocaleTimeString()}</time>${esc(message)}</li>`).join('');

    const actions = $('[data-actions]', modal);

    if (state.status === 'running' && state.cancellable && onCancel) {
      if (!actions.querySelector('[data-cancel]')) {
        actions.innerHTML = `<button type="button" class="wowm-btn wowm-btn--ghost" data-cancel>${esc(t.cancel)}</button>`;
        actions.querySelector('[data-cancel]').addEventListener('click', (e) => {
          e.currentTarget.disabled = true;
          e.currentTarget.textContent = t.cancelling;
          onCancel();
        });
      }
    } else if (state.status === 'running') {
      actions.innerHTML = '';
    }
  }

  function finishModal(state, { title, result = '', buttons = [] }) {
    const ring = $('[data-ring]', modal);
    const ok = state.status === 'done';

    running = false;
    $('[data-bgnote]', modal).hidden = true;
    ring.classList.add(ok ? 'is-done' : 'is-failed');
    $('[data-ring-icon]', modal).innerHTML = icon(ok ? 'check' : 'x');
    $('[data-title]', modal).textContent = title;
    $('[data-message]', modal).textContent = '';

    if (!ok && state.error) {
      result += `<div class="wowm-result wowm-result--error">${esc(state.error)}</div>`;
    }

    $('[data-result]', modal).innerHTML = result;

    const actions = $('[data-actions]', modal);
    actions.innerHTML = '';

    [...buttons, { label: t.close, ghost: true, onClick: () => (modal.hidden = true) }].forEach((button) => {
      const el = document.createElement(button.href ? 'a' : 'button');
      el.className = `wowm-btn ${button.ghost ? 'wowm-btn--ghost' : 'wowm-btn--primary'}`;
      el.innerHTML = (button.icon ? icon(button.icon) : '') + esc(button.label);
      if (button.href) {
        el.href = button.href;
      } else {
        el.type = 'button';
        el.addEventListener('click', button.onClick);
      }
      actions.append(el);
    });

    actions.lastElementChild.previousElementSibling ? actions.firstElementChild.focus() : actions.lastElementChild.focus();
  }

  /**
   * Start a job and drive it with ticks until it finishes. Resolves with the final state.
   */
  async function runJob(start, title, existing = null) {
    openModal(title);
    running = true;
    $('[data-bgnote]', modal).hidden = false;

    let job;

    try {
      job = existing || (await start());
    } catch (error) {
      return { status: 'failed', error: error.message, steps: [] };
    }

    const auth = { job: job.job, secret: job.secret };
    let state = job;
    let cancelRequested = false;

    const cancel = async () => {
      cancelRequested = true;
      try {
        state = await api('cancel', auth);
      } catch (error) {
        toast(error.message, 'error');
      }
    };

    renderState(state, cancel);

    while (state.status === 'running') {
      try {
        state = await retry(
          () => api('tick', auth),
          () => ($('[data-message]', modal).textContent = t.retrying),
        );
      } catch (error) {
        state = Object.assign({}, state, { status: 'failed', error: error.message });
      }

      renderState(state, cancelRequested ? null : cancel);

      if (state.busy) {
        await sleep(1500);
      }
    }

    return state;
  }

  const downloadUrl = (file) => `${C.ajax}?action=wowm_download&nonce=${encodeURIComponent(C.nonce)}&file=${encodeURIComponent(file)}`;

  function finishExport(state) {
    if (state.status === 'done') {
      finishModal(state, {
        title: t.exportDone,
        result: `<div class="wowm-result wowm-result--success"><strong>${esc(state.result.file)}</strong><br>${esc(bytes(state.result.size))}</div>`,
        buttons: [{ label: t.download, icon: 'download', href: downloadUrl(state.result.file) }],
      });
    } else {
      finishModal(state, { title: state.status === 'cancelled' ? t.cancelled : t.failed });
    }
  }

  /*
   * Export
   */

  let sizes = null;
  let folders = [];
  let measuring = true;
  const excludesField = $('[data-opt="excludes"]');

  const selectedComponents = () => Object.fromEntries($$('[data-components] input').map((input) => [input.value, input.checked]));

  // Folders excluded from "Other files" are plain "/name" lines in the exclusions field.
  const excludeLines = () => excludesField.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  const isExcluded = (name) => excludeLines().includes('/' + name);

  function toggleFolder(name) {
    const lines = excludeLines();
    const line = '/' + name;
    excludesField.value = (lines.includes(line) ? lines.filter((l) => l !== line) : [...lines, line]).join('\n');
    renderFolders();
    updateEstimate();
  }

  function renderFolders() {
    const box = $('[data-folders]');
    const visible = folders.filter((folder) => folder.size > 0 || !folder.done).slice(0, 8);

    box.innerHTML = visible
      .map((folder) => {
        const off = isExcluded(folder.name);
        return `<button type="button" class="wowm-folder${off ? ' is-off' : ''}" data-folder="${esc(folder.name)}" aria-pressed="${!off}" title="${esc(off ? t.includeFolder : t.excludeFolder)}">
          ${icon(folder.dir ? 'other' : 'file')}<span class="wowm-folder__name">${esc(folder.name)}</span><span class="wowm-folder__size">${esc(bytes(folder.size))}${folder.done ? '' : '…'}</span>${icon(off ? 'plus' : 'x')}
        </button>`;
      })
      .join('');
  }

  $('[data-folders]').addEventListener('click', (e) => {
    const button = e.target.closest('[data-folder]');
    if (button) {
      e.preventDefault();
      toggleFolder(button.dataset.folder);
    }
  });

  excludesField.addEventListener('input', () => {
    renderFolders();
    updateEstimate();
  });

  function updateEstimate() {
    const components = selectedComponents();
    const any = Object.values(components).some(Boolean);

    $('[data-export]').disabled = !any;

    if (!any || !sizes) {
      $('[data-estimate]').textContent = any ? '…' : '-';
      return;
    }

    let total = Object.entries(components).reduce((sum, [key, on]) => sum + (on ? sizes[key] || 0 : 0), 0);

    if (components.other) {
      total -= folders.filter((folder) => isExcluded(folder.name)).reduce((sum, folder) => sum + folder.size, 0);
    }

    $('[data-estimate]').textContent = (measuring ? '≥ ' : '≈ ') + bytes(total);
  }

  function renderSizes(result) {
    sizes = result.sizes;
    folders = result.folders || [];
    measuring = !result.done;

    Object.entries(result.sizes).forEach(([key, value]) => {
      const el = $(`[data-size="${key}"]`);
      if (el) {
        const counting = result.partial && result.partial[key];
        el.textContent = bytes(value) + (counting ? ' · ' + t.counting : '');
        el.classList.toggle('is-loading', !!counting);
      }
    });

    renderFolders();
    updateEstimate();
  }

  // Sizes are measured over several short requests and fill in live.
  async function loadSizes() {
    $$('[data-size]').forEach((el) => el.classList.add('is-loading'));
    let restart = 1;

    try {
      for (let i = 0; i < 300; i++) {
        const result = await retry(() => api('sizes', { restart }));
        restart = 0;
        renderSizes(result);

        if (result.done) {
          return;
        }
      }
    } catch (error) {
      $$('[data-size].is-loading').forEach((el) => (el.textContent = ''));
    }
  }

  $$('[data-components] input').forEach((input) => input.addEventListener('change', updateEstimate));

  $('[data-export]').addEventListener('click', async () => {
    const options = {
      components: selectedComponents(),
      excludes: $('[data-opt="excludes"]').value,
      replace: readPairs($('[data-pairs="export"]')),
    };

    $$('input[data-opt]').forEach((input) => (options[input.dataset.opt] = input.checked));
    options.base = $('select[data-opt="base"]').value;

    const state = await runJob(() => api('export_start', { options }), t.exportTitle);
    finishExport(state);
    refreshBackups();
  });

  /*
   * Import: upload
   */

  const drop = $('[data-drop]');
  const fileInput = $('[data-file]');

  function setUploading(on) {
    drop.classList.toggle('is-busy', on);
    $('.wowm-drop__idle', drop).hidden = on;
    $('.wowm-drop__busy', drop).hidden = !on;
    if (!on) {
      $('[data-upload-note]').hidden = true;
    }
  }

  // A sticky note under the upload bar: once the host forces smaller pieces,
  // the explanation stays visible instead of flashing once and disappearing.
  function showNote(text) {
    const note = $('[data-upload-note]');
    note.innerHTML = icon('info') + `<span>${esc(text)}</span>`;
    note.hidden = false;
  }

  drop.addEventListener('click', () => !drop.classList.contains('is-busy') && fileInput.click());
  drop.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      drop.click();
    }
  });

  ['dragenter', 'dragover'].forEach((type) =>
    drop.addEventListener(type, (e) => {
      e.preventDefault();
      drop.classList.add('is-over');
    }),
  );

  ['dragleave', 'drop'].forEach((type) => drop.addEventListener(type, () => drop.classList.remove('is-over')));

  drop.addEventListener('drop', (e) => {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    file && !drop.classList.contains('is-busy') && upload(file);
  });

  fileInput.addEventListener('change', () => fileInput.files[0] && upload(fileInput.files[0]));

  // An interrupted upload is remembered so picking the same file again continues
  // from where the server got to, instead of starting the gigabytes over.
  function savedUpload() {
    try {
      const data = JSON.parse(localStorage.getItem('wowm_upload') || 'null');
      return data && data.id ? data : null;
    } catch (e) {
      return null;
    }
  }

  function rememberUpload(data) {
    try {
      localStorage.setItem('wowm_upload', JSON.stringify(data));
    } catch (e) {
      // Not critical.
    }
  }

  function forgetUpload() {
    try {
      localStorage.removeItem('wowm_upload');
    } catch (e) {
      // Not critical.
    }
    renderResumeHint();
  }

  function renderResumeHint() {
    const idle = $('.wowm-drop__idle', drop);
    let hint = $('[data-resume-hint]', idle);
    const saved = savedUpload();

    if (!saved || !saved.offset) {
      hint && hint.remove();
      return;
    }

    if (!hint) {
      hint = document.createElement('div');
      hint.className = 'wowm-note wowm-note--info wowm-upload-note';
      hint.setAttribute('data-resume-hint', '');
      idle.append(hint);
    }

    hint.innerHTML = `${icon('info')}<span><strong>${esc(t.resumeTitle)}</strong><br>${esc(sprintf(t.resumeText, saved.name, bytes(saved.offset)))} <button type="button" class="wowm-link" data-resume-discard>${esc(t.resumeDiscard)}</button></span>`;
    $('[data-resume-discard]', hint).addEventListener('click', (e) => {
      e.stopPropagation();
      forgetUpload();
    });
  }

  async function upload(file) {
    if (!/\.(wowm|wpress|zip)$/i.test(file.name)) {
      toast(t.badFile, 'error');
      return;
    }

    $('[data-preflight]').hidden = true;
    setUploading(true);
    running = true;
    $('[data-upload-name]').textContent = sprintf(t.uploading, file.name);

    const bar = $('[data-upload-bar]');
    const meta = $('[data-upload-meta]');
    const started = Date.now();

    try {
      const saved = savedUpload();
      const resume = saved && saved.name === file.name && saved.size === file.size && saved.lastModified === file.lastModified ? saved.id : '';
      const start = await api('upload_start', { size: file.size, name: file.name, resume });
      const id = start.upload;
      let offset = Number(start.offset) || 0;
      let result = null;

      if (offset > 0) {
        meta.textContent = sprintf(t.resuming, bytes(offset));
      }

      rememberUpload({ id, name: file.name, size: file.size, lastModified: file.lastModified, offset });

      // Hosts with a strict request size limit or firewall reject big chunks;
      // if a chunk keeps failing, retry it smaller instead of giving up.
      const minChunk = 512 * 1024;
      let chunkSize = C.chunk;

      try {
        // A size that worked here before, so the next upload does not hit the limit again.
        const saved = parseInt(localStorage.getItem('wowm_chunk'), 10);
        if (saved >= minChunk && saved < chunkSize) {
          chunkSize = saved;
          showNote(sprintf(t.chunkNote, bytes(chunkSize)));
        }
      } catch (e) {
        // Storage blocked: start from the default.
      }

      // Big files take a while on shared hosting; say so up front instead of
      // letting a slow bar look like a crash.
      if (file.size > 500 * 1024 * 1024 && $('[data-upload-note]').hidden) {
        showNote(t.bigFileNote);
      }

      // Bar and numbers follow the bytes actually on the wire, including the
      // part of the current piece already sent, so it moves smoothly instead
      // of jumping once per piece.
      const paint = (sent) => {
        const seconds = (Date.now() - started) / 1000;
        const speed = sent / Math.max(seconds, 0.1);
        bar.style.width = Math.min(100, (sent / file.size) * 100) + '%';
        meta.textContent = `${bytes(sent)} / ${bytes(file.size)} · ${bytes(speed)}/s · ${duration((file.size - sent) / Math.max(speed, 1))}`;
      };

      while (!result || (!result.file && !result.convert)) {
        const chunk = file.slice(offset, offset + chunkSize);
        const base = offset;

        try {
          result = await retry(
            () => apiUpload('upload_chunk', { upload: id, offset, total: file.size, name: file.name }, chunk, (loaded) => paint(base + Math.min(loaded, chunk.size))),
            (error, attempt, attempts) => (meta.textContent = sprintf(t.uploadRetry, error.message, attempt, attempts)),
            2,
          );
        } catch (error) {
          if (error.retry && chunkSize > minChunk) {
            chunkSize = Math.max(minChunk, Math.floor(chunkSize / 2));
            showNote(sprintf(t.chunkNote, bytes(chunkSize)));

            try {
              localStorage.setItem('wowm_chunk', String(chunkSize));
            } catch (e) {
              // Not critical.
            }

            continue;
          }

          throw error;
        }

        offset = result.offset;
        paint(offset);
        rememberUpload({ id, name: file.name, size: file.size, lastModified: file.lastModified, offset });

        if (offset >= file.size && !result.file) {
          meta.textContent = t.checking;
        }
      }

      // All-in-One / Duplicator archive: convert to .wowm first, then the usual preflight.
      if (result.convert) {
        const converted = await runJob(() => api('convert_start', { options: { file: result.convert } }), sprintf(t.convertTitle, result.format));

        if (converted.status !== 'done') {
          finishModal(converted, { title: converted.status === 'cancelled' ? t.cancelled : t.failed });
          return;
        }

        modal.hidden = true;
        running = false;
        result.info = await api('inspect', { file: converted.result.file });
      }

      forgetUpload();
      showPreflight(result.info);
      refreshBackups();
    } catch (error) {
      toast(error.message, 'error');
      renderResumeHint();
    } finally {
      running = false;
      setUploading(false);
      bar.style.width = '0';
      fileInput.value = '';
    }
  }

  /*
   * Import: preflight
   */

  function siteCard(kind, name, url) {
    return `<div class="wowm-site wowm-site--${kind}">
      <span class="wowm-site__icon">${icon('globe')}</span>
      <span style="min-width:0"><span class="wowm-site__label">${esc(kind === 'from' ? t.from : t.to)}</span><span class="wowm-site__url" title="${esc(url)}">${esc(name || host(url))}</span><span class="wowm-muted">${esc(host(url))}</span></span>
    </div>`;
  }

  function toggle(key, label, checked, disabled = false) {
    return `<label class="wowm-switch"><input type="checkbox" data-import-opt="${key}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}><span class="wowm-switch__track"></span><span>${esc(label)}</span></label>`;
  }

  function showPreflight(info) {
    const card = $('[data-preflight]');
    const src = info.source;
    const dst = info.target;
    const stats = info.stats || {};
    const hasDb = !!info.components.database;
    const hasFiles = Object.entries(info.components).some(([key, on]) => key !== 'database' && on);
    const components = Object.entries(info.components)
      .filter(([, on]) => on)
      .map(([key]) => `<span class="wowm-tag">${esc(t.components[key] || key)}</span>`)
      .join(' ');

    const facts = [
      [t.created, date(info.created)],
      [t.size, bytes(info.size)],
      [t.wordpress, src.wp === dst.wp ? src.wp : `${src.wp} → ${dst.wp}`],
      [t.php, src.php === dst.php ? src.php : `${src.php} → ${dst.php}`],
      [t.theme, src.theme],
      [t.plugins, (src.plugins || []).length],
    ];

    if (stats.files) {
      facts.push([t.files, Number(stats.files).toLocaleString()]);
    }

    if (stats.tables) {
      facts.push([t.tables, stats.tables]);
    }

    card.innerHTML = `
      <div class="wowm-card__head wowm-card__head--row">
        <div><h2>${esc(t.readyToImport)}</h2><p>${components}</p></div>
        <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--icon" data-preflight-close aria-label="${esc(t.close)}">${icon('x')}</button>
      </div>
      <div class="wowm-route">${siteCard('from', src.name, src.home)}<span class="wowm-route__arrow">${icon('arrow')}</span>${siteCard('to', dst.name, dst.home)}</div>
      <dl class="wowm-facts">${facts.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl>
      <ul class="wowm-notes">${info.notes.map((note) => `<li class="wowm-note wowm-note--${note.level}">${icon(note.level)}<span>${esc(note.text)}</span></li>`).join('')}</ul>
      <div class="wowm-checks">
        <p class="wowm-checks__title">${icon('shield')}${esc(t.checksTitle)}</p>
        <ul>${(info.checks || [])
          .map((check) => `<li class="wowm-check wowm-check--${check.status}">${icon(check.status === 'ok' ? 'check' : check.status === 'fail' ? 'x' : 'warning')}<span><strong>${esc(check.label)}</strong> ${esc(check.detail)}</span></li>`)
          .join('')}</ul>
      </div>
      ${
        info.blocked
          ? ''
          : `<div class="wowm-options">
              ${toggle('import_db', t.importDb, hasDb, !hasDb)}
              ${toggle('import_files', t.importFiles, hasFiles, !hasFiles)}
              ${toggle('keep_dropins', t.keepDropins, true)}
              ${toggle('auto_undo', t.autoUndo, hasDb, !hasDb)}
              ${toggle('pre_backup', sprintf(t.preBackup, Object.entries(info.components).filter(([, on]) => on).map(([key]) => t.components[key] || key).join(', ')), true)}
            </div>
            <details class="wowm-advanced">
              <summary>${icon('chevron')}${esc(t.extraReplace)}</summary>
              <div class="wowm-pairs" data-pairs="import"></div>
              <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--sm" data-add-pair="import">${icon('plus')}${esc(t.addRule)}</button>
            </details>`
      }
      <footer class="wowm-card__foot">
        ${info.blocked ? '<span></span>' : `<label class="wowm-confirm"><input type="checkbox" data-understand>${esc(sprintf(t.understand, host(dst.home)))}</label>`}
        <button type="button" class="wowm-btn wowm-btn--danger wowm-btn--lg" data-start-import disabled>${icon('import')}${esc(t.importNow)}</button>
      </footer>`;

    card.hidden = false;

    const startButton = $('[data-start-import]', card);
    const understand = $('[data-understand]', card);

    $('[data-preflight-close]', card).addEventListener('click', () => (card.hidden = true));
    understand && understand.addEventListener('change', () => (startButton.disabled = !understand.checked));

    startButton.addEventListener('click', () => {
      const options = { file: info.file, replace: readPairs($('[data-pairs="import"]', card)) };
      $$('[data-import-opt]', card).forEach((input) => (options[input.dataset.importOpt] = input.checked && !input.disabled));
      startImport(info, options);
    });

    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function startImport(info, options) {
    // The safety copy only needs what this import is about to overwrite:
    // importing a database + themes archive must not back up 20 GB of media first.
    const touched = Object.fromEntries(
      Object.keys(t.components).map((key) => [key, !!info.components[key] && (key === 'database' ? options.import_db : options.import_files)]),
    );

    if (options.pre_backup && Object.values(touched).some(Boolean)) {
      const backup = await runJob(
        () =>
          api('export_start', {
            options: {
              components: touched,
              exclude_transients: true,
              exclude_spam: true,
              exclude_dropins: true,
              origin: 'pre-import',
              label: sprintf(t.preBackupLabel, host(info.source.home)),
            },
          }),
        t.preBackupTitle,
      );

      if (backup.status !== 'done') {
        finishExport(backup);
        return;
      }
    }

    const state = await runJob(() => api('import_start', { options }), t.importTitle);
    finishImport(state);
  }

  function healthList(checks) {
    return `<ul class="wowm-health">${(checks || [])
      .map((c) => `<li class="wowm-check wowm-check--${c.status}">${icon(c.status === 'ok' ? 'check' : c.status === 'fail' ? 'x' : 'warning')}<span><strong>${esc(c.label)}</strong> ${esc(c.detail)}</span></li>`)
      .join('')}</ul>`;
  }

  function finishImport(state) {
    if (state.status !== 'done') {
      finishModal(state, { title: state.status === 'cancelled' ? t.cancelled : t.failed });
      return;
    }

    const result = state.result || {};
    const broken = !!result.broken;
    let html = '';

    if (result.undone) {
      html += `<div class="wowm-result wowm-result--error"><strong>${esc(t.undoneTitle)}</strong><br>${esc(t.undoneText)}${healthList(result.health || [])}</div>`;
      finishModal(state, { title: t.undoneTitle, result: html, buttons: [{ label: t.viewSite, icon: 'globe', href: result.home || C.home }] });
      $('[data-preflight]').hidden = true;
      return;
    }

    if (result.health && result.health.length) {
      html += `<div class="wowm-result ${broken ? 'wowm-result--error' : 'wowm-result--success'}">
        <strong>${esc(t.healthTitle)}</strong><br>${esc(broken ? t.healthBroken : t.healthOk)}
        ${healthList(result.health)}
        ${broken && result.auto_undo_at ? `<p class="wowm-countdown" data-countdown="${Number(result.auto_undo_at)}"></p>` : ''}
        ${broken && !result.auto_undo_at ? `<p>${esc(t.autoUndoOff)}</p>` : ''}
      </div>`;
    }

    if (!broken) {
      html += `<div class="wowm-result wowm-result--success">${esc(result.db ? t.loginNote : t.filesOnlyNote)}${result.undo ? '<br><br>' + esc(t.undoNote) : ''}</div>`;
    }

    if (result.deactivated && result.deactivated.length) {
      html += `<div class="wowm-result wowm-result--warn"><strong>${esc(t.deactivatedTitle)}</strong><br>${esc(t.deactivatedText)}<ul>${result.deactivated.map((p) => `<li><code>${esc(p)}</code></li>`).join('')}</ul></div>`;
    }

    if (result.undo) {
      html += `<div class="wowm-result wowm-result--note"><strong>${esc(t.recoveryTitle)}</strong><br>${esc(t.recoveryText)} <code>${esc(t.recoveryCli)}</code></div>`;
    }

    const buttons = broken
      ? [
          { label: t.undoNow, icon: 'undo', onClick: () => rollbackAction('undo') },
          { label: t.keepAnyway, ghost: true, onClick: () => rollbackAction(result.auto_undo_at ? 'keep_pending' : 'keep') },
        ]
      : result.db
        ? [{ label: t.login, icon: 'login', href: C.login }]
        : [{ label: t.viewSite, icon: 'globe', href: result.home || C.home }];

    finishModal(state, { title: broken ? t.healthBroken : t.importDone, result: html, buttons });
    $('[data-preflight]').hidden = true;

    if (result.undo) {
      C.rollback = Object.assign({}, C.rollback || {}, {
        created: Math.floor(Date.now() / 1000),
        source: (C.rollback && C.rollback.source) || '',
        health: result.health,
        auto_undo_at: result.auto_undo_at,
      });
      renderRollback();
    }

    startCountdowns();
  }

  // "m:ss" countdowns to an automatic undo; every element with data-countdown ticks.
  let countdownTimer = null;

  function startCountdowns() {
    clearInterval(countdownTimer);

    const tick = () => {
      const els = $$('[data-countdown]');
      if (!els.length) {
        clearInterval(countdownTimer);
        return;
      }
      const now = Math.floor(Date.now() / 1000);
      els.forEach((el) => {
        const left = Math.max(0, Number(el.dataset.countdown) - now);
        el.textContent = sprintf(t.autoUndoIn, `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`);
      });
      if (els.some((el) => Number(el.dataset.countdown) <= now)) {
        // The server has undone the import by now; the login page is the honest next step.
        clearInterval(countdownTimer);
        setTimeout(() => (window.location.href = C.login), 2000);
      }
    };

    tick();
    countdownTimer = setInterval(tick, 1000);
  }

  async function rollbackAction(mode) {
    if (mode === 'undo' && !(await confirmDialog(t.confirmUndo, t.rollbackUndo))) {
      return;
    }

    $$('[data-rollback-mode]').forEach((el) => (el.disabled = true));

    try {
      const result = await api('rollback', { mode });

      if (mode === 'undo') {
        toast(t.undone);
        setTimeout(() => (window.location.href = result.login), 1200);
        return;
      }

      if (mode === 'keep_pending') {
        C.rollback = Object.assign({}, C.rollback || {}, { auto_undo_at: null });
        $$('[data-countdown]').forEach((el) => el.remove());
        modal.hidden = true;
      } else {
        C.rollback = null;
        modal.hidden = true;
      }

      renderRollback();
      toast(mode === 'keep_pending' ? t.saved : t.kept);
    } catch (error) {
      toast(error.message, 'error');
      $$('[data-rollback-mode]').forEach((el) => (el.disabled = false));
    }
  }

  /*
   * Backups
   */

  // "Files to include": everything, or only what changed since a full backup that has a file index.
  function renderBaseSelect(items) {
    const select = $('select[data-opt="base"]');
    const current = select.value;
    const fulls = items.filter((item) => item.kind === 'full' && item.has_index);

    select.innerHTML =
      `<option value="">${esc(t.baseNone)}</option>` +
      fulls.map((item) => `<option value="${esc(item.file)}">${esc(sprintf(t.baseChanges, date(item.created)))}</option>`).join('');
    select.value = fulls.some((item) => item.file === current) ? current : '';
  }

  function renderBackups(data) {
    C.backups = data;
    renderBaseSelect(data.items || []);

    const items = data.items || [];
    $('[data-backups-count]').textContent = items.length || '';
    $('[data-storage]').textContent = data.dir + (data.free !== null ? ' · ' + sprintf(t.free, bytes(data.free)) : '');

    if (!items.length) {
      $('[data-backups]').innerHTML = `<div class="wowm-empty">${icon('backups')}${esc(t.noBackups)}</div>`;
      return;
    }

    const iconFor = { scheduled: 'schedule', uploaded: 'import', 'pre-import': 'shield' };

    $('[data-backups]').innerHTML = items
      .map((item) => {
        const title = item.label || host(item.source) || item.file;
        const base = item.kind === 'differential' ? items.find((other) => other.file === item.base) : null;
        const parts = (item.components || []).map((key) => t.components[key] || key).join(', ');

        return `<div class="wowm-backup">
          <span class="wowm-backup__icon">${icon(iconFor[item.origin] || 'backups')}</span>
          <div class="wowm-backup__main">
            <div class="wowm-backup__title">${esc(title)} <span class="wowm-tag wowm-tag--${esc(item.origin)}">${esc(t.origin[item.origin] || item.origin)}</span>${item.kind === 'differential' ? ` <span class="wowm-tag wowm-tag--diff">${icon('layers')}${esc(t.differential)}</span>` : ''}</div>
            <div class="wowm-backup__meta" title="${esc(item.file)}">${esc(date(item.created))} · ${esc(relative(item.created))}${parts ? ' · ' + esc(parts) : ''}${base ? ' · ' + esc(sprintf(t.basedOn, date(base.created))) : ''}</div>
          </div>
          <span class="wowm-backup__size">${esc(bytes(item.size))}</span>
          <div class="wowm-backup__actions">
            <a class="wowm-btn wowm-btn--ghost wowm-btn--icon" href="${esc(downloadUrl(item.file))}" title="${esc(t.download)}" aria-label="${esc(t.download)}">${icon('download')}</a>
            <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--icon" data-restore="${esc(item.file)}" title="${esc(t.restore)}" aria-label="${esc(t.restore)}">${icon('restore')}</button>
            <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--icon" data-delete="${esc(item.file)}" title="${esc(t.delete)}" aria-label="${esc(t.delete)}">${icon('trash')}</button>
          </div>
        </div>`;
      })
      .join('');
  }

  async function refreshBackups() {
    try {
      renderBackups(await api('backups'));
    } catch (error) {
      // Not critical; the list refreshes on the next action.
    }
  }

  $('[data-backups]').addEventListener('click', async (e) => {
    const restore = e.target.closest('[data-restore]');
    const remove = e.target.closest('[data-delete]');

    if (restore) {
      restore.disabled = true;
      try {
        const info = await api('inspect', { file: restore.dataset.restore });
        showTab('import');
        showPreflight(info);
      } catch (error) {
        toast(error.message, 'error');
      } finally {
        restore.disabled = false;
      }
    }

    const dependents = remove ? (C.backups.items || []).filter((item) => item.base === remove.dataset.delete).length : 0;

    if (remove && (await confirmDialog(dependents ? sprintf(t.confirmDeleteChain, dependents) : t.confirmDelete))) {
      try {
        renderBackups(await api('delete', { file: remove.dataset.delete }));
        toast(t.deleted);
      } catch (error) {
        toast(error.message, 'error');
      }
    }
  });

  /*
   * Schedule
   */

  const form = $('[data-schedule]');

  function fillSchedule({ settings, next }) {
    const radio = form.querySelector(`[name="frequency"][value="${settings.frequency}"]`);
    radio && (radio.checked = true);
    form.elements.hour.value = settings.hour;
    form.elements.retention.value = settings.retention;
    form.elements.full_every.value = settings.full_every;
    $$('[name="components"]', form).forEach((input) => (input.checked = !!settings.components[input.value]));
    $('[data-next-run]', form).textContent = settings.frequency !== 'off' && next ? sprintf(t.nextRun, date(next)) : t.scheduleOff;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const checked = form.querySelector('[name="frequency"]:checked');
    const settings = {
      frequency: checked ? checked.value : 'off',
      hour: form.elements.hour.value,
      retention: form.elements.retention.value,
      full_every: form.elements.full_every.value,
      components: Object.fromEntries($$('[name="components"]', form).map((input) => [input.value, input.checked])),
    };
    const button = form.querySelector('[type="submit"]');

    button.disabled = true;

    try {
      fillSchedule(await api('save_schedule', { settings }));
      toast(t.saved);
    } catch (error) {
      toast(error.message, 'error');
    } finally {
      button.disabled = false;
    }
  });

  /*
   * Undo import
   */

  function renderRollback() {
    const banner = $('[data-rollback]');
    const data = C.rollback;

    if (!data) {
      banner.hidden = true;
      return;
    }

    const checks = Array.isArray(data.health) ? data.health : [];
    const broken = checks.some((c) => c.status === 'fail');
    const pending = !!data.auto_undo_at && Number(data.auto_undo_at) > Math.floor(Date.now() / 1000);

    banner.classList.toggle('wowm-banner--danger', broken);
    banner.innerHTML = `
      <span class="wowm-banner__icon">${icon(broken ? 'error' : 'undo')}</span>
      <div class="wowm-banner__text">
        <strong>${esc(broken ? t.healthBroken : sprintf(t.rollbackTitle, relative(data.created)))}</strong>
        <span>${esc(host(data.source))} → ${esc(host(C.home))}. ${esc(broken ? '' : t.rollbackText)}</span>
        ${checks.length ? healthList(checks) : ''}
        ${pending ? `<span class="wowm-countdown" data-countdown="${Number(data.auto_undo_at)}"></span>` : ''}
        <span class="wowm-muted">${esc(t.recoveryTitle)}: ${esc(t.recoveryText)} <code>${esc(t.recoveryCli)}</code></span>
      </div>
      <div class="wowm-banner__actions">
        <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--sm" data-rollback-mode="recheck">${icon('restore')}${esc(t.recheck)}</button>
        <button type="button" class="wowm-btn wowm-btn--ghost wowm-btn--sm" data-rollback-mode="undo">${icon('undo')}${esc(broken ? t.undoNow : t.rollbackUndo)}</button>
        <button type="button" class="wowm-btn wowm-btn--primary wowm-btn--sm" data-rollback-mode="${pending ? 'keep_pending' : 'keep'}">${icon('check')}${esc(pending ? t.keepAnyway : t.rollbackKeep)}</button>
      </div>`;
    banner.hidden = false;
    startCountdowns();
  }

  $('[data-rollback]').addEventListener('click', async (e) => {
    const button = e.target.closest('[data-rollback-mode]');

    if (!button) {
      return;
    }

    const mode = button.dataset.rollbackMode;

    if (mode === 'recheck') {
      button.disabled = true;
      try {
        const result = await api('health');
        C.rollback = Object.assign({}, C.rollback || {}, { health: result.health });
        renderRollback();
      } catch (error) {
        toast(error.message, 'error');
        button.disabled = false;
      }
      return;
    }

    rollbackAction(mode);
  });

  /*
   * Tools: leftover links + replace
   */

  function initTools() {
    const scan = $('[data-scan]');
    const field = scan.elements.needle;
    const bar = $('[data-scan-bar]');
    const results = $('[data-scan-results]');

    field.placeholder = t.scanNeedle;

    // Default to the address the last import came from.
    if (C.lastImport && C.lastImport.source && C.lastImport.source !== C.home) {
      field.value = C.lastImport.source;
    }

    scan.addEventListener('submit', async (e) => {
      e.preventDefault();

      const needle = field.value.trim();
      const button = scan.querySelector('[type="submit"]');
      let restart = 1;
      let result = null;

      button.disabled = true;
      bar.hidden = false;
      results.innerHTML = '';

      try {
        do {
          result = await retry(() => api('scan_links', { needle, restart }));
          restart = 0;
          $('span', bar).style.width = result.progress + '%';
          button.lastChild.textContent = sprintf(t.scanning, result.progress);
        } while (!result.done);

        renderHits(needle, result.hits);
      } catch (error) {
        toast(error.message, 'error');
      } finally {
        button.disabled = false;
        button.lastChild.textContent = scan.dataset.label || button.lastChild.textContent;
        bar.hidden = true;
      }
    });

    const button = scan.querySelector('[type="submit"]');
    scan.dataset.label = button.lastChild.textContent;

    function renderHits(needle, hits) {
      if (!hits.length) {
        results.innerHTML = `<div class="wowm-result wowm-result--success">${icon('check')} ${esc(t.noHits)}</div>`;
        return;
      }

      const total = hits.reduce((sum, hit) => sum + hit.count, 0);
      const bare = needle.replace(/^https?:/i, '');
      const marked = (sample) => esc(sample).split(esc(bare)).join(`<mark>${esc(bare)}</mark>`);
      const suggestion = /^https?:\/\//i.test(needle) ? C.home : host(C.home);

      results.innerHTML = `
        <h3 class="wowm-hits__title">${esc(sprintf(t.hitsTitle, total.toLocaleString()))}</h3>
        <div class="wowm-hits">
          <div class="wowm-hits__row wowm-hits__row--head"><span>${esc(t.table)}</span><span>${esc(t.column)}</span><span>${esc(t.matches)}</span></div>
          ${hits
            .map(
              (hit) => `<div class="wowm-hits__row">
                <span><code>${esc(hit.table)}</code></span><span><code>${esc(hit.column)}</code></span><span>${esc(hit.count.toLocaleString())}</span>
                <span class="wowm-hits__sample">…${marked(hit.sample)}…</span>
              </div>`,
            )
            .join('')}
        </div>
        <div class="wowm-replace">
          <span class="wowm-replace__from"><code>${esc(needle)}</code></span>
          ${icon('arrow')}
          <input type="text" data-replace-to spellcheck="false" value="${esc(suggestion)}">
          <button type="button" class="wowm-btn wowm-btn--danger" data-replace>${esc(t.replaceYes)}</button>
        </div>`;

      $('[data-replace]', results).addEventListener('click', () => replaceAll(needle, $('[data-replace-to]', results).value.trim()));
    }

    async function replaceAll(from, to) {
      if (!to || to === from || !(await confirmDialog(sprintf(t.confirmReplace, from, to), t.replaceYes))) {
        return;
      }

      const backup = await runJob(
        () =>
          api('export_start', {
            options: {
              components: { database: true },
              exclude_transients: true,
              exclude_spam: true,
              origin: 'pre-import',
              label: sprintf(t.preBackupLabel, from),
            },
          }),
        t.dbBackupTitle,
      );

      if (backup.status !== 'done') {
        finishExport(backup);
        return;
      }

      const state = await runJob(() => api('replace_start', { options: { from, to } }), t.replaceTitle);

      finishModal(state, {
        title: state.status === 'done' ? t.replaceDone : t.failed,
        result: state.status === 'done' ? `<div class="wowm-result wowm-result--success">${esc(sprintf(t.replaceResult, (state.result.changed || 0).toLocaleString()))}</div>` : '',
      });

      refreshBackups();
      state.status === 'done' && scan.requestSubmit();
    }
  }

  /*
   * Boot
   */

  const initial = location.hash.slice(1);
  showTab(['export', 'import', 'backups', 'schedule', 'tools', 'help'].includes(initial) ? initial : 'export');
  renderRollback();
  initTools();
  renderResumeHint();
  renderBackups(C.backups);

  // A job started earlier (tab closed, page reloaded) is still running on the server.
  if (C.activeJob && C.activeJob.id) {
    (async () => {
      const titles = { export: t.exportTitle, import: t.importTitle, convert: sprintf(t.convertTitle, ''), replace: t.replaceTitle };
      const existing = Object.assign({ job: C.activeJob.id, secret: C.activeJob.secret }, C.activeJob.status || {});
      toast(t.reattachText);
      const state = await runJob(null, titles[C.activeJob.type] || t.reattachTitle, existing);

      if (C.activeJob.type === 'import') {
        finishImport(state);
      } else if (C.activeJob.type === 'export') {
        finishExport(state);
        refreshBackups();
      } else {
        finishModal(state, { title: state.status === 'done' ? t.importDone : state.status === 'cancelled' ? t.cancelled : t.failed });
        refreshBackups();
      }
    })();
  }
  fillSchedule(C.schedule);
  updateEstimate();
  loadSizes();
})();
