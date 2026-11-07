/**
 * EcoWealth — live page regions
 * -----------------------------
 * React-like refresh behaviour without React:
 *
 *   • a page declares one or more `[data-live-surface]` containers and
 *     the `[data-live-region]` blocks inside them;
 *   • the same URL returns JSON fragments when asked with `X-Live: 1`
 *     (no separate feed route, so pagination links stay correct);
 *   • a successful mutation broadcasts the surface key on
 *     BroadcastChannel, and the other open tabs revalidate;
 *   • a visible tab also polls its ETag every 30s as a safety net for
 *     changes made in another browser or by a CLI job.
 *
 * Resources are deliberately conservative: hidden tabs make no
 * requests, unchanged polls are 304s, responses are single-flight,
 * errors back off, and only the regions that changed are touched.
 *
 * Loaded after app-cart.js. No dependencies.
 */
(function () {
  'use strict';

  var configEl    = document.getElementById('liveConfig');
  var channelName = configEl
    ? (configEl.getAttribute('data-channel') || 'ecowealth:live')
    : 'ecowealth:live';
  var storageKey  = channelName.replace(/[^A-Za-z0-9:_-]/g, '_') + ':message';
  var tabId       = (window.crypto && typeof window.crypto.randomUUID === 'function')
    ? window.crypto.randomUUID()
    : 'tab-' + Math.random().toString(36).slice(2) + '-' + Date.now().toString(36);
  var AbortCtor   = window.AbortController || null;

  var channel           = null;
  var surfaces          = [];
  var mutationInFlight  = 0;
  var started           = false;

  /* ------------------------------------------------------------------
   *  Small helpers
   * ---------------------------------------------------------------- */
  function each(list, callback) {
    Array.prototype.forEach.call(list || [], callback);
  }

  function uiBlocked() {
    return !!document.querySelector('.swal2-container, .modal.show');
  }

  function notify(message, type) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type);
      return;
    }

    var host = document.getElementById('toastContainer');

    if (!host || !window.bootstrap || !window.bootstrap.Toast) {
      if (window.console && console.error) {
        console.error('[ecowealth] ' + message);
      }
      return;
    }

    var tone = type === 'error' ? 'danger' : (type === 'warning' ? 'warning' : 'success');
    var el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + tone + ' border-0 mb-2';
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');

    var wrap = document.createElement('div');
    wrap.className = 'd-flex';

    var body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = String(message == null ? '' : message);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Close');

    wrap.appendChild(body);
    wrap.appendChild(close);
    el.appendChild(wrap);
    host.appendChild(el);

    var toast = new window.bootstrap.Toast(el, { delay: 3500 });
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    toast.show();
  }

  function setStatus(surface, text, tone) {
    var el = surface.el.querySelector('[data-live-status]')
      || document.querySelector('[data-live-status]');

    if (!el) {
      return;
    }

    el.textContent = String(text || '');
    el.className = 'small text-body-secondary'
      + (tone === 'success' ? ' text-success' : '')
      + (tone === 'warning' ? ' text-warning' : '')
      + (tone === 'danger' ? ' text-danger' : '');

    if (surface.statusTimer) {
      window.clearTimeout(surface.statusTimer);
      surface.statusTimer = null;
    }

    if (tone === 'success') {
      surface.statusTimer = window.setTimeout(function () {
        if (el.textContent === String(text || '')) {
          el.textContent = '';
        }
      }, 4000);
    }
  }

  function keyMatches(surfaceKey, messageKey) {
    if (surfaceKey === messageKey) {
      return true;
    }

    return surfaceKey.indexOf(messageKey + ':') === 0
      || messageKey.indexOf(surfaceKey + ':') === 0;
  }

  /* ------------------------------------------------------------------
   *  Cross-tab bus
   * ---------------------------------------------------------------- */
  function setupBus() {
    if (typeof window.BroadcastChannel === 'function') {
      try {
        channel = new window.BroadcastChannel(channelName);
        channel.onmessage = function (event) {
          handleMessage(event.data);
        };
      } catch (error) {
        channel = null;
      }
    }

    /* Fallback for browsers without BroadcastChannel. The event only
     * fires in the *other* tabs, which is exactly what we need. */
    try {
      window.addEventListener('storage', function (event) {
        if (event.key !== storageKey || !event.newValue) {
          return;
        }

        try {
          handleMessage(JSON.parse(event.newValue));
        } catch (error) {
          /* A different app version wrote the key; ignore it. */
        }
      });
    } catch (error) {
      /* Storage can be disabled; polling still works. */
    }
  }

  function broadcast(keys) {
    if (!keys || !keys.length) {
      return;
    }

    var message = {
      v: 1,
      keys: keys.slice(),
      source: tabId,
      at: Date.now()
    };

    if (channel) {
      try {
        channel.postMessage(message);
        return;
      } catch (error) {
        /* Fall through to storage below. */
      }
    }

    try {
      window.localStorage.setItem(storageKey, JSON.stringify({
        v: message.v,
        keys: message.keys,
        source: message.source,
        at: message.at,
        nonce: Math.random()
      }));
    } catch (error) {
      /* Private mode / storage disabled: polling is the fallback. */
    }
  }

  function handleMessage(message) {
    if (!message || message.v !== 1 || !Array.isArray(message.keys) || message.source === tabId) {
      return;
    }

    each(surfaces, function (surface) {
      if (!surface.enabled || surface.stopped) {
        return;
      }

      var matched = message.keys.some(function (key) {
        return keyMatches(surface.key, String(key));
      });

      if (matched) {
        invalidate(surface);
      }
    });
  }

  function publishQueued() {
    if (!configEl) {
      return;
    }

    var keys = [];

    try {
      keys = JSON.parse(configEl.getAttribute('data-publish') || '[]');
    } catch (error) {
      keys = [];
    }

    if (Array.isArray(keys) && keys.length) {
      broadcast(keys);
    }
  }

  function invalidate(surface) {
    if (document.visibilityState === 'hidden') {
      surface.dirty = true;
      return;
    }

    if (surface.refreshTimer) {
      window.clearTimeout(surface.refreshTimer);
    }

    surface.refreshTimer = window.setTimeout(function () {
      surface.refreshTimer = null;
      refresh(surface, { force: false });
    }, 80);
  }

  /* ------------------------------------------------------------------
   *  Surface setup and scheduling
   * ---------------------------------------------------------------- */
  function setupSurface(el) {
    var key = el.getAttribute('data-live-surface') || '';

    if (key === '') {
      return;
    }

    var interval = parseInt(el.getAttribute('data-live-interval'), 10);

    if (isNaN(interval) || interval < 0) {
      interval = 30000;
    }

    var surface = {
      el: el,
      key: key,
      enabled: el.getAttribute('data-live-enabled') !== '0',
      interval: interval,
      feedUrl: el.getAttribute('data-live-feed') || window.location.href,
      etag: el.getAttribute('data-live-etag') || '',
      revision: parseInt(el.getAttribute('data-live-revision'), 10) || 0,
      timer: null,
      refreshTimer: null,
      pendingTimer: null,
      statusTimer: null,
      abort: null,
      inFlight: false,
      queued: false,
      dirty: false,
      stopped: false,
      deferred: false,
      deferredBound: false,
      pending: null,
      lastFetch: 0,
      backoff: 0,
      lastError: false
    };

    surfaces.push(surface);

    if (surface.enabled) {
      scheduleNext(surface);
    }
  }

  function scheduleNext(surface, delay) {
    if (!surface.enabled || surface.stopped) {
      return;
    }

    if (surface.timer) {
      window.clearTimeout(surface.timer);
      surface.timer = null;
    }

    var explicitDelay = delay !== undefined && delay !== null;

    if ((surface.interval <= 0 && !explicitDelay)
      || document.visibilityState === 'hidden'
      || navigator.onLine === false) {
      return;
    }

    var base = explicitDelay ? delay : surface.interval;
    var jitter = 0.85 + (Math.random() * 0.3);
    var wait = Math.max(1000, Math.round(base * jitter));

    surface.timer = window.setTimeout(function () {
      surface.timer = null;
      refresh(surface, { force: false });
    }, wait);
  }

  function stopSurface(surface, message) {
    surface.stopped = true;

    if (surface.timer) {
      window.clearTimeout(surface.timer);
      surface.timer = null;
    }
    if (surface.refreshTimer) {
      window.clearTimeout(surface.refreshTimer);
      surface.refreshTimer = null;
    }
    if (surface.pendingTimer) {
      window.clearInterval(surface.pendingTimer);
      surface.pendingTimer = null;
    }
    if (surface.abort) {
      surface.abort.abort();
      surface.abort = null;
    }

    surface.pending = null;
    surface.lastError = true;
    setStatus(surface, message, 'danger');
    notify(message, 'error');
  }

  /* ------------------------------------------------------------------
   *  Fetch + apply
   * ---------------------------------------------------------------- */
  function refresh(surface, options) {
    options = options || {};

    if (!surface.enabled || surface.stopped) {
      return;
    }

    if (navigator.onLine === false) {
      surface.dirty = true;
      surface.lastError = true;
      setStatus(surface, 'Offline — live updates paused.', 'warning');
      return;
    }

    if (document.visibilityState === 'hidden' && !options.force) {
      surface.dirty = true;
      return;
    }

    if (surface.inFlight) {
      surface.queued = true;
      return;
    }

    if (uiBlocked() && !options.force) {
      surface.dirty = true;
      scheduleNext(surface, 1000);
      return;
    }

    var controller = AbortCtor ? new AbortCtor() : null;
    var headers = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Live': '1'
    };

    if (surface.etag && !options.force) {
      headers['If-None-Match'] = surface.etag;
    }

    var request = {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: headers
    };

    if (controller) {
      request.signal = controller.signal;
    }

    surface.inFlight = true;
    surface.abort = controller;
    surface.dirty = false;

    fetch(surface.feedUrl, request)
      .then(function (response) {
        if (response.status === 304) {
          surface.lastFetch = Date.now();
          surface.backoff = 0;

          if (surface.lastError) {
            surface.lastError = false;
            setStatus(surface, '', '');
          }

          return null;
        }

        if (response.status === 401) {
          stopSurface(surface, 'Your session expired. Refresh the page to sign in again.');
          return null;
        }

        if (response.status === 403) {
          stopSurface(surface, 'You no longer have access to this page.');
          return null;
        }

        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        var type = response.headers.get('Content-Type') || '';

        if (type.indexOf('application/json') === -1) {
          throw new Error('Unexpected response');
        }

        var etag = response.headers.get('ETag') || '';

        return response.json().then(function (payload) {
          if (!payload || payload.ok !== true) {
            throw new Error((payload && payload.message) || 'Refresh failed');
          }

          if (surface.stopped) {
            return null;
          }

          surface.lastFetch = Date.now();
          surface.backoff = 0;

          if (surface.lastError) {
            surface.lastError = false;
            setStatus(surface, '', '');
          }

          surface.etag = etag || payload.etag || surface.etag;
          surface.revision = payload.revision !== undefined
            ? payload.revision
            : surface.revision;

          /* This payload supersedes anything waiting to be applied. */
          if (surface.pendingTimer) {
            window.clearInterval(surface.pendingTimer);
            surface.pendingTimer = null;
          }
          surface.pending = null;

          if (uiBlocked() || mutationInFlight > 0) {
            deferApply(surface, payload);
          } else {
            applyPayload(surface, payload);
          }

          return null;
        });
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }

        surface.backoff = surface.backoff
          ? Math.min(surface.backoff * 2, 300000)
          : 30000;
        surface.lastError = true;

        setStatus(surface, 'Live updates paused — retrying…', 'warning');

        if (window.console && console.error) {
          console.error('[ecowealth] live refresh failed:', error);
        }
      })
      .then(function () {
        surface.inFlight = false;
        surface.abort = null;

        if (surface.stopped) {
          return;
        }

        if (document.visibilityState === 'hidden') {
          surface.dirty = true;
          return;
        }

        if (surface.queued) {
          surface.queued = false;
          refresh(surface, { force: true });
          return;
        }

        if (surface.pending) {
          return; /* The pending-apply checker restarts the timer. */
        }

        scheduleNext(surface, surface.backoff || undefined);
      });
  }

  function deferApply(surface, payload) {
    if (surface.stopped) {
      return;
    }

    surface.pending = payload;

    if (surface.pendingTimer) {
      return;
    }

    surface.pendingTimer = window.setInterval(function () {
      if (document.visibilityState === 'hidden' || uiBlocked() || mutationInFlight > 0) {
        return;
      }

      flushPending();
    }, 400);
  }

  function flushPending() {
    each(surfaces, function (surface) {
      if (surface.stopped) {
        if (surface.pendingTimer) {
          window.clearInterval(surface.pendingTimer);
          surface.pendingTimer = null;
        }
        surface.pending = null;
        return;
      }

      if (!surface.pending) {
        return;
      }

      if (surface.pendingTimer) {
        window.clearInterval(surface.pendingTimer);
        surface.pendingTimer = null;
      }

      var payload = surface.pending;
      surface.pending = null;
      applyPayload(surface, payload);
      scheduleNext(surface);
    });
  }

  function applyPayload(surface, payload) {
    var fragments = payload.fragments || {};
    var changed = false;
    var active = document.activeElement;

    /* A previous payload may have deferred one region while focus was
     * inside it. The current payload is the newer truth. */
    surface.deferred = false;

    Object.keys(fragments).forEach(function (key) {
      var current = regionFor(surface, key);

      if (!current) {
        return;
      }

      if (key === 'product-rows' || current.tagName === 'TBODY') {
        var nextBody = parseRows(fragments[key]);

        if (!nextBody) {
          return;
        }

        var rowsChanged = patchRows(current, nextBody);

        if (rowsChanged === -1) {
          surface.deferred = true;
        } else if (rowsChanged > 0) {
          changed = true;
        }

        return;
      }

      var next = parseRegion(fragments[key]);

      if (!next || current.outerHTML === next.outerHTML) {
        return;
      }

      if (current.contains(active)) {
        surface.deferred = true;
        return;
      }

      current.replaceWith(next);
      changed = true;
    });

    if (changed) {
      setStatus(surface, 'Updated just now', 'success');
    }

    if (surface.deferred) {
      bindDeferred(surface);
    }

    if (payload.meta && payload.meta.canonical) {
      var currentUrl = window.location.pathname + window.location.search;

      if (payload.meta.canonical !== currentUrl) {
        try {
          window.history.replaceState(null, '', payload.meta.canonical);
        } catch (error) {
          /* History can be unavailable in some embedded contexts. */
        }

        surface.feedUrl = window.location.href;
        surface.etag = '';
      }
    }
  }

  function regionFor(surface, key) {
    var regions = surface.el.querySelectorAll('[data-live-region]');

    for (var i = 0; i < regions.length; i++) {
      if (regions[i].getAttribute('data-live-region') === key
        && regions[i].closest('[data-live-surface]') === surface.el) {
        return regions[i];
      }
    }

    return null;
  }

  function parseRegion(html) {
    var template = document.createElement('template');
    template.innerHTML = String(html || '');

    return template.content.firstElementChild;
  }

  function parseRows(html) {
    var template = document.createElement('template');

    /* Table tags outside a table context are dropped by the parser. */
    template.innerHTML = '<table>' + String(html || '') + '</table>';

    return template.content.querySelector('tbody');
  }

  function keyedRows(tbody) {
    var rows = [];

    each(tbody.children, function (row) {
      if (row.hasAttribute('data-live-key')) {
        rows.push(row);
      }
    });

    return rows;
  }

  /**
   * Patch a <tbody> by row key. Unchanged rows keep their DOM nodes, so
   * hover, selection and focus survive a background refresh.
   *
   * @returns {number} -1 when a bulk replace was deferred because focus
   *                   was inside the region, otherwise changed-row count.
   */
  function patchRows(current, next) {
    var active = document.activeElement;
    var focusRow = active && active.closest ? active.closest('[data-live-key]') : null;
    var focusKey = focusRow ? focusRow.getAttribute('data-live-key') : null;
    var focusRole = active ? active.getAttribute('data-live-focus') : null;

    var currentKeyed = keyedRows(current);
    var nextKeyed = keyedRows(next);

    /* Empty-state / unkeyed table: a wholesale replace is the only option.
     * If focus is inside it, let the user finish first. */
    if (currentKeyed.length === 0 || nextKeyed.length === 0) {
      if (current.innerHTML.trim() === next.innerHTML.trim()) {
        return 0;
      }

      if (current.contains(active)) {
        return -1;
      }

      current.innerHTML = next.innerHTML;
      return 1;
    }

    var currentByKey = new Map();
    var nextByKey = new Map();

    currentKeyed.forEach(function (row) {
      currentByKey.set(row.getAttribute('data-live-key'), row);
    });

    nextKeyed.forEach(function (row) {
      nextByKey.set(row.getAttribute('data-live-key'), row);
    });

    var changed = 0;
    var updated = [];

    /* Remove rows that disappeared. */
    currentKeyed.forEach(function (row) {
      var key = row.getAttribute('data-live-key');

      if (!nextByKey.has(key)) {
        if (row.parentNode) {
          row.parentNode.removeChild(row);
        }
        changed++;
      }
    });

    /* Replace changed rows, append genuinely new ones. */
    nextKeyed.forEach(function (nextRow) {
      var key = nextRow.getAttribute('data-live-key');
      var existing = currentByKey.get(key);

      if (existing && existing.parentNode === current) {
        if (existing.outerHTML !== nextRow.outerHTML) {
          existing.parentNode.replaceChild(nextRow, existing);
          currentByKey.set(key, nextRow);
          changed++;
          updated.push(nextRow);
        }
      } else {
        current.appendChild(nextRow);
        currentByKey.set(key, nextRow);
        changed++;
        updated.push(nextRow);
      }
    });

    /* Sort-order changes are rare; only pay for a reorder when needed. */
    var desired = nextKeyed.map(function (row) {
      return row.getAttribute('data-live-key');
    });

    var actual = Array.prototype.map.call(current.children, function (row) {
      return row.getAttribute('data-live-key');
    });

    if (desired.join('|') !== actual.join('|')) {
      nextKeyed.forEach(function (nextRow) {
        var row = currentByKey.get(nextRow.getAttribute('data-live-key'));

        if (row) {
          current.appendChild(row);
        }
      });

      changed++;
    }

    /* A row replacement can drop the focused button. Put focus back on
     * the same action in the same row, without scrolling the page. */
    if (focusKey !== null && focusRole !== null) {
      var focusTarget = null;
      var rowForFocus = null;

      each(current.children, function (row) {
        if (row.getAttribute('data-live-key') === focusKey) {
          rowForFocus = row;
        }
      });

      if (rowForFocus) {
        focusTarget = rowForFocus.querySelector('[data-live-focus="' + focusRole + '"]');
      }

      if (focusTarget && typeof focusTarget.focus === 'function') {
        focusTarget.focus({ preventScroll: true });
      }
    }

    updated.forEach(function (row) {
      row.classList.add('is-live-updated');
      window.setTimeout(function () {
        row.classList.remove('is-live-updated');
      }, 1600);
    });

    return changed;
  }

  function bindDeferred(surface) {
    if (surface.deferredBound) {
      return;
    }

    surface.deferredBound = true;

    var handler = function () {
      window.setTimeout(function () {
        surface.deferredBound = false;
        document.removeEventListener('focusout', handler, true);

        if (!surface.stopped && !uiBlocked()) {
          refresh(surface, { force: false });
        }
      }, 120);
    };

    document.addEventListener('focusout', handler, true);
  }

  /* ------------------------------------------------------------------
   *  Mutations: status/delete stay on the page
   * ---------------------------------------------------------------- */
  function closestSurface(el) {
    var node = el && el.closest ? el.closest('[data-live-surface]') : null;

    if (!node) {
      return null;
    }

    for (var i = 0; i < surfaces.length; i++) {
      if (surfaces[i].el === node) {
        return surfaces[i];
      }
    }

    return null;
  }

  function showWarning(title, text) {
    if (window.Swal) {
      window.Swal.fire({ icon: 'warning', title: title, text: text });
      return;
    }

    window.alert(text);
  }

  function confirmDelete(name, onConfirm) {
    if (window.Swal) {
      window.Swal.fire({
        icon: 'warning',
        title: 'Delete product?',
        text: 'Delete "' + name + '" permanently?',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancel'
      }).then(function (result) {
        if (result.isConfirmed) {
          onConfirm();
        }
      });
      return;
    }

    if (window.confirm('Delete "' + name + '" permanently?')) {
      onConfirm();
    }
  }

  function submitLiveForm(form, action) {
    var surface = closestSurface(form) || surfaces[0];

    if (!surface) {
      form.submit();
      return;
    }

    var buttons = form.querySelectorAll('button');
    var previous = [];

    each(buttons, function (button) {
      previous.push(button.disabled);
      button.disabled = true;
    });

    form.setAttribute('data-live-busy', '1');
    mutationInFlight++;

    fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new FormData(form)
    })
      .then(function (response) {
        var type = response.headers.get('Content-Type') || '';

        if (type.indexOf('application/json') === -1) {
          throw new Error('Session expired');
        }

        return response.json().then(function (payload) {
          payload._status = response.status;
          return payload;
        });
      })
      .then(function (payload) {
        if (!payload || payload.ok !== true) {
          notify((payload && payload.message) || 'The update failed.', 'error');

          if (payload && payload._status === 404) {
            refresh(surface, { force: true });
          }
          return;
        }

        notify(payload.message || 'Saved.', 'success');

        /* A no-op status toggle has nothing to broadcast, but the local
         * row may have been stale; reconcile it with one silent refresh. */
        if (payload.changed === false) {
          refresh(surface, { force: true });
          return;
        }

        broadcast([surface.key]);
        refresh(surface, { force: true });
      })
      .catch(function (error) {
        notify(
          error && error.message === 'Session expired'
            ? 'Your session expired. Refresh the page to sign in again.'
            : 'Could not reach the server. Please try again.',
          'error'
        );

        if (window.console && console.error) {
          console.error('[ecowealth] live form failed:', error);
        }
      })
      .then(function () {
        mutationInFlight = Math.max(0, mutationInFlight - 1);
        form.removeAttribute('data-live-busy');

        each(buttons, function (button, index) {
          button.disabled = previous[index] === true;
        });

        flushPending();
      });
  }

  function handleLiveForm(form) {
    if (form.getAttribute('data-live-busy') === '1') {
      return;
    }

    var action = form.getAttribute('data-live-action');

    if (form.getAttribute('data-blocked') === '1') {
      showWarning(
        'Cannot delete',
        form.getAttribute('data-blocked-reason') || 'This product cannot be deleted.'
      );
      return;
    }

    if (action === 'delete') {
      confirmDelete(form.getAttribute('data-name') || 'this product', function () {
        submitLiveForm(form, action);
      });
      return;
    }

    submitLiveForm(form, action);
  }

  function onLiveSubmit(event) {
    var form = event.target;

    if (!form || !form.matches || !form.matches('[data-live-action]')) {
      return;
    }

    event.preventDefault();
    handleLiveForm(form);
  }

  /* ------------------------------------------------------------------
   *  Page lifecycle
   * ---------------------------------------------------------------- */
  function onVisibilityChange() {
    if (document.visibilityState === 'hidden') {
      each(surfaces, function (surface) {
        if (surface.timer) {
          window.clearTimeout(surface.timer);
          surface.timer = null;
        }

        if (surface.abort) {
          surface.abort.abort();
          surface.abort = null;
        }

        if (surface.pendingTimer) {
          window.clearInterval(surface.pendingTimer);
          surface.pendingTimer = null;
        }

        surface.dirty = true;
      });

      return;
    }

    each(surfaces, function (surface) {
      if (!surface.enabled || surface.stopped) {
        return;
      }

      if (surface.dirty || Date.now() - surface.lastFetch > 5000) {
        refresh(surface, { force: false });
      }

      scheduleNext(surface);

      if (surface.pending) {
        deferApply(surface, surface.pending);
      }
    });
  }

  function onFocus() {
    if (document.visibilityState === 'hidden') {
      return;
    }

    each(surfaces, function (surface) {
      if (!surface.enabled || surface.stopped) {
        return;
      }

      /* Visibility already handles tab switches; a window focus is a
       * weaker signal, so do not turn every click into a poll. */
      if (Date.now() - surface.lastFetch > Math.max(surface.interval, 30000)) {
        refresh(surface, { force: false });
      }
    });
  }

  function onOnline() {
    each(surfaces, function (surface) {
      if (!surface.enabled || surface.stopped) {
        return;
      }

      surface.dirty = false;
      refresh(surface, { force: true });
      scheduleNext(surface);
    });
  }

  function onOffline() {
    each(surfaces, function (surface) {
      if (surface.timer) {
        window.clearTimeout(surface.timer);
        surface.timer = null;
      }

      surface.dirty = true;
      surface.lastError = true;
      setStatus(surface, 'Offline — live updates paused.', 'warning');
    });
  }

  function onPageShow(event) {
    if (!event.persisted) {
      return;
    }

    each(surfaces, function (surface) {
      if (surface.enabled && !surface.stopped) {
        refresh(surface, { force: true });
        scheduleNext(surface);
      }
    });
  }

  function onPageHide() {
    each(surfaces, function (surface) {
      if (surface.timer) {
        window.clearTimeout(surface.timer);
        surface.timer = null;
      }
      if (surface.refreshTimer) {
        window.clearTimeout(surface.refreshTimer);
        surface.refreshTimer = null;
      }
      if (surface.pendingTimer) {
        window.clearInterval(surface.pendingTimer);
        surface.pendingTimer = null;
      }
      surface.pending = null;
      if (surface.abort) {
        surface.abort.abort();
        surface.abort = null;
      }
    });
  }

  /* ------------------------------------------------------------------
   *  Boot
   * ---------------------------------------------------------------- */
  function init() {
    if (started) {
      return;
    }
    started = true;

    setupBus();
    publishQueued();

    each(document.querySelectorAll('[data-live-surface]'), setupSurface);

    document.addEventListener('visibilitychange', onVisibilityChange);
    document.addEventListener('submit', onLiveSubmit);
    window.addEventListener('focus', onFocus);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    window.addEventListener('pageshow', onPageShow);
    window.addEventListener('pagehide', onPageHide);

    /* A small public API for future page scripts that already know a
     * surface changed (e.g. the cart adding an item). */
    window.EcoLive = {
      refresh: function (key) {
        each(surfaces, function (surface) {
          if (surface.key === key) {
            refresh(surface, { force: true });
          }
        });
      },
      invalidate: function (key) {
        broadcast([key]);
        each(surfaces, function (surface) {
          if (keyMatches(surface.key, key)) {
            invalidate(surface);
          }
        });
      }
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
