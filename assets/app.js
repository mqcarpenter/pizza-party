/* Pizza Party — talks to api/ , state lives in MySQL (synced from Discogs). */
(function () {
  'use strict';

  var API = 'api/';
  var COLLECTION = [];
  var WANTLIST = [];
  var tab = 'collection';           // 'collection' | 'wantlist'
  var query = '';
  var devices = 0, writeWindow = 0, windowTimer = null;
  var list  = document.getElementById('list');
  var empty = document.getElementById('empty');
  var toast = document.getElementById('toast');
  var tid;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function say(msg, bad) {
    toast.textContent = msg;
    toast.classList.toggle('bad', !!bad);
    toast.classList.add('show');
    clearTimeout(tid);
    tid = setTimeout(function () { toast.classList.remove('show'); }, bad ? 15000 : 2600);
  }
  toast.addEventListener('click', function () { toast.classList.remove('show'); });

  async function api(path, opts) {
    var r = await fetch(API + path, Object.assign({ credentials: 'same-origin' }, opts || {}));
    var d = null;
    try { d = await r.json(); } catch (e) {}
    if (r.status === 401) { showGate(); throw new Error('locked'); }
    if (!r.ok) {
      var msg = (d && d.error) || ('HTTP ' + r.status);
      if (d && d.detail) msg += ' — ' + (typeof d.detail === 'string' ? d.detail : JSON.stringify(d.detail));
      if (d && d.where)  msg += ' (' + d.where + ')';
      var err = new Error(msg);
      err.status = r.status;
      err.code = d && d.error;
      throw err;
    }
    return d;
  }

  /* ---------- passkey ----------
     Reading is always open. Adding/removing/editing a wantlist item needs a
     signature from a registered device — on an iPhone, a Face ID prompt. One
     prompt opens a short window (default 15 min). */

  var b64 = {
    enc: function (buf) {
      var b = '', a = new Uint8Array(buf);
      for (var i = 0; i < a.length; i++) b += String.fromCharCode(a[i]);
      return btoa(b).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    },
    dec: function (s) {
      s = String(s).replace(/-/g, '+').replace(/_/g, '/');
      while (s.length % 4) s += '=';
      var raw = atob(s), a = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) a[i] = raw.charCodeAt(i);
      return a.buffer;
    }
  };

  function passkeySupported() {
    return !!(window.PublicKeyCredential && navigator.credentials &&
              navigator.credentials.create && window.isSecureContext);
  }

  function setWriteWindow(secs) {
    writeWindow = secs || 0;
    clearInterval(windowTimer);
    if (writeWindow > 0) {
      windowTimer = setInterval(function () {
        writeWindow -= 1;
        if (writeWindow <= 0) { clearInterval(windowTimer); writeWindow = 0; }
        paintLock();
      }, 1000);
    }
    paintLock();
  }

  function paintLock() {
    var el = document.getElementById('lockBtn');
    if (!el) return;
    if (!devices) {
      el.className = 'lock open';
      el.textContent = 'Unprotected';
      el.title = 'Anyone can edit the wantlist. Register this iPhone to lock writing to it.';
      return;
    }
    if (writeWindow > 0) {
      var m = Math.floor(writeWindow / 60), s = writeWindow % 60;
      el.className = 'lock unlocked';
      el.textContent = 'Unlocked ' + m + ':' + (s < 10 ? '0' : '') + s;
      el.title = 'Tap to lock again now.';
    } else {
      el.className = 'lock locked';
      el.textContent = 'Locked';
      el.title = 'Tap to unlock with Face ID.';
    }
  }

  async function unlockWrites() {
    if (!passkeySupported()) {
      say('This browser cannot use passkeys.', true);
      return false;
    }
    try {
      var o = await api('?action=passkey-auth-options');
      var cred = await navigator.credentials.get({
        publicKey: {
          challenge: b64.dec(o.challenge),
          rpId: o.rpId,
          allowCredentials: (o.allowCredentials || []).map(function (c) {
            return { type: 'public-key', id: b64.dec(c.id) };
          }),
          userVerification: o.userVerification,
          timeout: o.timeout
        }
      });
      if (!cred) return false;
      var d = await api('?action=passkey-auth', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: b64.enc(cred.rawId),
          clientDataJSON: b64.enc(cred.response.clientDataJSON),
          authenticatorData: b64.enc(cred.response.authenticatorData),
          signature: b64.enc(cred.response.signature)
        })
      });
      setWriteWindow(d.writeWindow);
      say('Unlocked.');
      return true;
    } catch (e) {
      if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return false;
      if (e.message !== 'locked') say('Could not unlock: ' + e.message, true);
      return false;
    }
  }

  async function registerDevice(enrollKey) {
    if (!passkeySupported()) {
      say('This browser cannot use passkeys.', true);
      return false;
    }
    try {
      var o = await api('?action=passkey-register-options', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ enroll_key: enrollKey || '' })
      });
      var cred = await navigator.credentials.create({
        publicKey: {
          challenge: b64.dec(o.challenge),
          rp: o.rp,
          user: {
            id: b64.dec(o.user.id),
            name: o.user.name,
            displayName: o.user.displayName
          },
          pubKeyCredParams: o.pubKeyCredParams,
          excludeCredentials: (o.excludeCredentials || []).map(function (c) {
            return { type: 'public-key', id: b64.dec(c.id) };
          }),
          authenticatorSelection: o.authenticatorSelection,
          timeout: o.timeout,
          attestation: o.attestation
        }
      });
      if (!cred) return false;
      var d = await api('?action=passkey-register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          enroll_key: enrollKey || '',
          label: /iPhone|iPad/.test(navigator.userAgent) ? 'iPhone' : 'This device',
          clientDataJSON: b64.enc(cred.response.clientDataJSON),
          attestationObject: b64.enc(cred.response.attestationObject)
        })
      });
      devices += 1;
      setWriteWindow(d.writeWindow);
      say('This device is registered. Only it can edit the wantlist now.');
      return true;
    } catch (e) {
      if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return false;
      if (e && e.name === 'InvalidStateError') {
        say('This device is already registered.', true);
        return false;
      }
      say('Could not register: ' + e.message, true);
      return false;
    }
  }

  /**
   * A write the server refuses for want of a passkey isn't a failure — it's a
   * prompt. Ask for Face ID once, then send the same write again.
   */
  async function gatedPost(action, payload) {
    var opts = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
    try {
      return await api('?action=' + action, opts);
    } catch (e) {
      if (e.code !== 'passkey_required') throw e;
      setWriteWindow(0);
      var ok = await unlockWrites();
      if (!ok) throw e;
      return await api('?action=' + action, opts);
    }
  }

  document.getElementById('lockBtn').addEventListener('click', async function () {
    if (!devices) {
      if (!passkeySupported()) {
        say('Passkeys need HTTPS and a modern browser.', true);
        return;
      }
      if (!confirm('Register this device?\n\nAfter this, only devices you register ' +
                   'can add, remove, or edit wantlist items. Anyone can still view the page.')) return;
      await registerDevice('');
      return;
    }
    if (writeWindow > 0) {
      try { await api('?action=passkey-lock', { method: 'POST' }); } catch (e) {}
      setWriteWindow(0);
      say('Locked.');
      return;
    }
    await unlockWrites();
  });

  /* ---------- rendering ---------- */

  function matches(item) {
    if (!query) return true;
    var q = query.toLowerCase();
    return [item.artist, item.title, item.label, item.format].some(function (f) {
      return f && f.toLowerCase().indexOf(q) !== -1;
    });
  }

  function itemCard(item, isWant) {
    var thumb = item.thumb
      ? '<img class="thumb" src="' + esc(item.thumb) + '" alt="" loading="lazy">'
      : '<div class="thumb placeholder"></div>';
    var meta = [item.year, item.format, item.label].filter(Boolean).join(' · ');
    var notes = item.notes ? '<div class="notes">' + esc(item.notes) + '</div>' : '';
    var actions = isWant
      ? '<button class="remove" data-id="' + item.releaseId + '" type="button">Remove</button>'
      : '';
    return (
      '<li class="card" data-id="' + item.releaseId + '">' +
        thumb +
        '<div class="body">' +
          '<div class="artist">' + esc(item.artist || 'Unknown artist') + '</div>' +
          '<div class="title">' + esc(item.title || 'Untitled') + '</div>' +
          '<div class="meta">' + esc(meta) + '</div>' +
          notes +
        '</div>' +
        '<div class="actions">' + actions + '</div>' +
      '</li>'
    );
  }

  function render() {
    var data = (tab === 'collection' ? COLLECTION : WANTLIST).filter(matches);
    if (!data.length) {
      list.innerHTML = '';
      empty.classList.remove('hide');
      return;
    }
    empty.classList.add('hide');
    list.innerHTML = data.map(function (item) { return itemCard(item, tab === 'wantlist'); }).join('');
  }

  list.addEventListener('click', async function (e) {
    var btn = e.target.closest('.remove');
    if (!btn) return;
    var id = parseInt(btn.dataset.id, 10);
    if (!confirm('Remove this from your wantlist?')) return;
    btn.disabled = true;
    try {
      await gatedPost('wantlist-remove', { releaseId: id });
      WANTLIST = WANTLIST.filter(function (i) { return i.releaseId !== id; });
      render();
      say('Removed.');
    } catch (e2) {
      btn.disabled = false;
      if (e2.message !== 'locked' && e2.code !== 'passkey_required') say('Not removed: ' + e2.message, true);
    }
  });

  document.getElementById('q').addEventListener('input', function (e) {
    query = e.target.value;
    render();
  });

  document.getElementById('tabs').addEventListener('click', function (e) {
    var b = e.target.closest('.tab');
    if (!b) return;
    tab = b.dataset.tab;
    [].forEach.call(this.querySelectorAll('.tab'), function (t) {
      t.setAttribute('aria-selected', t === b ? 'true' : 'false');
    });
    document.getElementById('addForm').classList.toggle('hide', tab !== 'wantlist');
    document.getElementById('addHint').classList.toggle('hide', tab !== 'wantlist');
    render();
  });

  document.getElementById('addForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var input = document.getElementById('addReleaseId');
    var id = parseInt(input.value, 10);
    if (!id || id <= 0) { say('Enter a numeric Discogs release ID.', true); return; }
    if (WANTLIST.some(function (i) { return i.releaseId === id; })) {
      say('Already on your wantlist.');
      return;
    }
    var btn = this.querySelector('button');
    btn.disabled = true;
    try {
      await gatedPost('wantlist-add', { releaseId: id });
      var w = await api('?action=wantlist');
      WANTLIST = w.items || [];
      input.value = '';
      render();
      say('Added.');
    } catch (e2) {
      if (e2.message !== 'locked' && e2.code !== 'passkey_required') say('Not added: ' + e2.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('theme').addEventListener('click', function () {
    var root = document.documentElement;
    var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('pizzaparty.theme', next); } catch (ex) {}
  });

  /* ---------- passphrase gate ---------- */
  function showGate() {
    document.getElementById('app').classList.add('hide');
    document.getElementById('gate').classList.remove('hide');
  }
  document.getElementById('gateForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var err = document.getElementById('gateErr');
    err.textContent = '';
    try {
      var d = await api('?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ passphrase: document.getElementById('gatePass').value })
      });
      if (d && d.ok) {
        document.getElementById('gate').classList.add('hide');
        document.getElementById('app').classList.remove('hide');
        boot();
      }
    } catch (ex) {
      err.textContent = 'Wrong passphrase.';
    }
  });

  function relTime(iso) {
    if (!iso) return 'never';
    var then = new Date(iso.replace(' ', 'T') + 'Z').getTime();
    var mins = Math.round((Date.now() - then) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + ' minute' + (mins === 1 ? '' : 's') + ' ago';
    var hrs = Math.round(mins / 60);
    if (hrs < 24) return hrs + ' hour' + (hrs === 1 ? '' : 's') + ' ago';
    var days = Math.round(hrs / 24);
    return days + ' day' + (days === 1 ? '' : 's') + ' ago';
  }

  /* ---------- boot ---------- */
  async function boot() {
    try {
      var [c, w] = await Promise.all([api('?action=collection'), api('?action=wantlist')]);
      COLLECTION = c.items || [];
      WANTLIST = w.items || [];
      render();
    } catch (e) {
      if (e.message !== 'locked') {
        list.innerHTML = '<div class="empty">Could not load data: ' + esc(e.message) + '</div>';
      }
    }
  }

  (async function () {
    try {
      var t = localStorage.getItem('pizzaparty.theme');
      if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
    } catch (e) {}
    try {
      var s = await api('?action=session');
      devices = s.devices || 0;
      setWriteWindow(s.writeWindow || 0);
      if (s.locked) { showGate(); return; }
      if (!s.discogsConnected) document.getElementById('connectBanner').classList.remove('hide');
      var note = document.getElementById('syncNote');
      if (note && s.sync) {
        note.textContent = s.sync.status === 'ok'
          ? 'Synced from Discogs ' + relTime(s.sync.last_synced_at) + '.'
          : (s.sync.last_synced_at ? 'Last sync failed ' + relTime(s.sync.last_synced_at) + '.' : 'Not synced yet.');
      }
    } catch (e) {}
    document.getElementById('app').classList.remove('hide');
    boot();
  })();

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }

  if (window.navigator.standalone) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[href]');
      if (!a) return;
      var url = new URL(a.href, location.href);
      if (url.origin === location.origin && a.target !== '_blank') {
        e.preventDefault();
        location.href = a.href;
      }
    });
  }
})();
