<?php

/**
 * @file   views/partials/address_picker.php
 * @brief  Shared Region → Province → City → Barangay picker backed by the
 *         psgc.cloud v2 API (see tmp/address_book/api_address.html). Include
 *         once per page, then wire a trigger with PsgcPicker.attach({...}).
 *         Presented as a Bootstrap modal stacked above the parent modal.
 */
?>
<style>
#psgcPickerModal .psgc-dialog { max-width: 560px; }
#psgcPickerModal .psgc-top { align-items: center; gap: .5rem; }
#psgcPickerModal .psgc-back { flex: none; width: 34px; height: 34px; padding: 0; border: 0; border-radius: 50%; background: transparent; color: var(--primary, #2563eb); font-size: 1.4rem; line-height: 1; cursor: pointer; }
#psgcPickerModal .psgc-back[hidden] { display: none; }
#psgcPickerModal .psgc-top .modal-title { margin: 0 auto 0 0; font-size: 1rem; }
#psgcPickerModal .psgc-search { position: sticky; top: -1rem; z-index: 2; margin: -1rem -1rem .5rem; padding: 1rem; background: var(--bs-modal-bg, #fff); }
#psgcPickerModal .psgc-search input { width: 100%; height: 40px; padding: 0 .75rem; border: 1px solid #e2e2e2; border-radius: 10px; background: #f7f7f7; font: inherit; }
#psgcPickerModal .psgc-search input:focus { outline: 2px solid var(--primary, #2563eb); outline-offset: 1px; background: #fff; }
#psgcPickerModal .psgc-cap { display: flex; justify-content: space-between; align-items: center; padding: 0 0 .25rem; color: #9a9a9a; font-size: .8rem; }
#psgcPickerModal .psgc-cap button { border: 0; background: transparent; color: var(--primary, #2563eb); cursor: pointer; }
#psgcPickerModal #psgcTrail { margin: 0; padding: 0 0 .5rem; list-style: none; }
#psgcPickerModal #psgcTrail li { padding: .2rem 0; }
#psgcPickerModal #psgcTrail li button { border: 0; background: transparent; padding: 0; color: #1f1f1f; text-align: left; cursor: pointer; }
#psgcPickerModal #psgcTrail li.now { color: #9a9a9a; }
#psgcPickerModal #psgcSec { margin: 0; padding: .5rem 0 .25rem; color: #9a9a9a; font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
#psgcPickerModal #psgcList { margin: 0; padding: 0; list-style: none; }
#psgcPickerModal #psgcList li { display: flex; align-items: stretch; border-bottom: 1px solid #eee; }
#psgcPickerModal #psgcList li i { flex: 0 0 40px; display: grid; place-items: center; color: #b5b5b5; font-style: normal; }
#psgcPickerModal #psgcList li button, #psgcPickerModal #psgcList li .psgc-msg { flex: 1; padding: .7rem .5rem .7rem 0; border: 0; background: transparent; text-align: left; font: inherit; color: inherit; cursor: pointer; }
#psgcPickerModal #psgcList li .psgc-msg { color: #9a9a9a; cursor: default; padding-left: .25rem; }
#psgcPickerModal #psgcList li .psgc-msg button { color: var(--primary, #2563eb); text-decoration: underline; background: transparent; border: 0; cursor: pointer; padding: 0; }
</style>
<div class="modal fade" id="psgcPickerModal" tabindex="-1" aria-labelledby="psgcTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable psgc-dialog">
    <div class="modal-content">
      <div class="modal-header psgc-top">
        <button type="button" class="psgc-back" id="psgcBack" aria-label="Back" hidden>&#8249;</button>
        <h5 class="modal-title" id="psgcTitle">Select location</h5>
        <button type="button" class="btn-close" id="psgcClose" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="psgc-search"><input id="psgcQ" type="search" placeholder="Search region" autocomplete="off" aria-label="Search"></label>
        <div id="psgcCrumbs" hidden>
          <div class="psgc-cap"><span>Selected</span><button type="button" id="psgcReset">Reset</button></div>
          <ol id="psgcTrail"></ol>
        </div>
        <h6 id="psgcSec"></h6>
        <ul id="psgcList"></ul>
      </div>
    </div>
  </div>
</div>
<script>
window.PsgcPicker = (function () {
  var API = 'https://psgc.cloud/api/v2';
  var LEVEL = { region: 'Region', province: 'Province', city: 'City', barangay: 'Barangay' };
  var GROUPS = [
    ['Metro Manila', ['13']],
    ['North Luzon', ['01', '02', '03', '14']],
    ['South Luzon', ['04', '17', '05']],
    ['Visayas', ['06', '07', '08', '18']],
    ['Mindanao', ['09', '10', '11', '12', '16', '19']]
  ];
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };
  var byName = function (a, b) { return a.name.localeCompare(b.name); };

  function buildGroups(regions) {
    var left = new Set(regions);
    var out = GROUPS.map(function (g) {
      var name = g[0], prefixes = g[1];
      var rs = regions.filter(function (r) { return prefixes.indexOf(String(r.code).slice(0, 2)) !== -1; });
      rs.forEach(function (r) { left.delete(r); });
      return { name: name, regions: rs, flat: name === 'Metro Manila' };
    }).filter(function (g) { return g.regions.length; });
    left.forEach(function (r) { out.push({ name: r.name, regions: [r], flat: false }); });
    return out.sort(byName);
  }

  var cache = new Map();
  function getList(path) {
    if (!cache.has(path)) cache.set(path, (async function () {
      var url = API + path, rows = [];
      while (url) {
        var res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(res.status);
        var j = await res.json(), next = j.links && j.links.next;
        rows = rows.concat(j.data || []);
        url = next ? new URL(next, url).href : null;
      }
      return rows.sort(byName);
    })().catch(function (e) { cache.delete(path); throw e; }));
    return cache.get(path);
  }

  var order = function (d) { return d.region && d.region.flat ? ['region', 'city', 'barangay'] : ['region', 'province', 'city', 'barangay']; };

  var groups = null, draft = {}, step = '', rows = [], shown = [], seq = 0, open = false, onPickCb = null;
  var modalEl = null, bsModal = null, bodyEl = null, savedBodyPad = null;

  async function load(s) {
    if (s === 'region') return groups || (groups = buildGroups(await getList('/regions')));
    var d = draft;
    var paths;
    if (s === 'province') {
      paths = d.region.regions.map(function (r) { return '/regions/' + r.code + '/provinces'; });
    } else if (s === 'city') {
      paths = [d.region.flat
        ? '/regions/' + d.region.regions[0].code + '/cities-municipalities'
        : '/provinces/' + d.province.code + '/cities-municipalities'];
    } else {
      paths = ['/cities-municipalities/' + d.city.code + '/barangays'];
    }
    var lists = await Promise.all(paths.map(getList));
    return lists.reduce(function (acc, l) { return acc.concat(l); }, []).sort(byName);
  }

  function paint(msg) {
    var t = $('psgcQ').value.trim().toLowerCase();
    shown = rows.filter(function (r) { return r.name.toLowerCase().indexOf(t) !== -1; });
    var list = $('psgcList');
    if (msg) { list.innerHTML = '<li><span class="psgc-msg">' + esc(msg) + '</span></li>'; return; }
    if (!shown.length) { list.innerHTML = '<li><span class="psgc-msg">No matches</span></li>'; return; }
    var last = '';
    list.innerHTML = shown.map(function (r, i) {
      var L = r.name[0].toUpperCase(), first = L !== last; last = L;
      return '<li><i aria-hidden="true">' + (first ? esc(L) : '') + '</i><button type="button" data-i="' + i + '">' + esc(r.name) + '</button></li>';
    }).join('');
  }

  async function render() {
    var ord = order(draft), done = ord.filter(function (k) { return draft[k]; });
    step = ord.find(function (k) { return !draft[k]; });
    if (!step) return commit();
    var my = ++seq;
    $('psgcQ').value = '';
    $('psgcQ').placeholder = 'Search ' + LEVEL[step].toLowerCase();
    $('psgcCrumbs').hidden = !done.length;
    $('psgcTrail').innerHTML = done.map(function (k) {
      return '<li><button type="button" data-k="' + k + '">' + esc(draft[k].name) + '</button></li>';
    }).join('') + '<li class="now" aria-current="step">Select ' + LEVEL[step] + '</li>';
    $('psgcSec').textContent = LEVEL[step];
    $('psgcBack').hidden = !done.length;
    if (bodyEl) bodyEl.scrollTop = 0;
    rows = []; paint('Loading…');
    try {
      rows = await load(step);
      if (my !== seq) return;
      if (step === 'barangay' && !rows.length) return commit();
      paint();
    } catch (e) {
      if (my !== seq) return;
      $('psgcList').innerHTML = '<li><span class="psgc-msg">Couldn\u2019t load this list. <button type="button" id="psgcRetry">Try again</button></span></li>';
    }
  }

  function commit() {
    var picked = {
      region: draft.region || null,
      province: draft.province || null,
      city: draft.city || null,
      barangay: draft.barangay || null
    };
    close();
    if (typeof onPickCb === 'function') onPickCb(picked);
  }

  function initModal() {
    if (bsModal) return;
    modalEl = $('psgcPickerModal');
    bodyEl = modalEl.querySelector('.modal-body');
    bsModal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false });
    modalEl.addEventListener('shown.bs.modal', function () {
      modalEl.style.zIndex = '1065';
      var bds = document.querySelectorAll('.modal-backdrop');
      if (bds.length) bds[bds.length - 1].style.zIndex = '1060';
      savedBodyPad = document.body.style.paddingRight;
      $('psgcQ').focus();
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
      open = false;
      if (document.querySelector('.modal.show')) {
        document.body.classList.add('modal-open');
        document.body.style.paddingRight = savedBodyPad || '';
      }
    });
  }

  function openPicker(cb) {
    onPickCb = cb || null;
    draft = {};
    open = true;
    initModal();
    bsModal.show();
    render();
  }

  function close() {
    open = false;
    if (bsModal) bsModal.hide();
  }

  function bind() {
    if (modalEl && modalEl.dataset.bound) return;
    modalEl = $('psgcPickerModal');
    if (!modalEl || modalEl.dataset.bound) return;
    modalEl.dataset.bound = '1';

    $('psgcList').addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      if (b.id === 'psgcRetry') return render();
      if (b.dataset.i != null) { draft[step] = shown[b.dataset.i]; render(); }
    });
    $('psgcTrail').addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      var ord = order(draft);
      ord.slice(ord.indexOf(b.dataset.k)).forEach(function (k) { delete draft[k]; });
      render();
    });
    $('psgcBack').addEventListener('click', function () {
      var ord = order(draft), done = ord.filter(function (k) { return draft[k]; });
      if (done.length) { delete draft[done[done.length - 1]]; render(); }
    });
    $('psgcReset').addEventListener('click', function () { draft = {}; render(); });
    $('psgcClose').addEventListener('click', close);
    $('psgcQ').addEventListener('input', function () { if (rows.length) paint(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && open) close();
    });
    load('region').catch(function () {});
  }

  function attach(opts) {
    bind();
    opts.trigger.addEventListener('click', function () { openPicker(opts.onPick); });
  }

  return { attach: attach, open: openPicker, close: close };
})();
</script>
