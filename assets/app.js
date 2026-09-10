/* Pizza Party — talks to api/ , state lives in MySQL (synced from Discogs). */
(function () {
  'use strict';

  var API = 'api/';
  var COLLECTION = [];
  var WANTLIST = [];
  var tab = 'collection';           // 'collection' | 'wantlist' | 'stats'
  var query = '';
  var view = 'list';                // 'list' | 'grid'
  var sortBy = 'artist';            // 'artist' | 'year' | 'title'
  var genre = 'all';
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

  function itemGenres(item) {
    return (item.genres || '').split(',').map(function (g) { return g.trim(); }).filter(Boolean);
  }

  function matches(item) {
    if (genre !== 'all' && itemGenres(item).indexOf(genre) === -1) return false;
    if (!query) return true;
    var q = query.toLowerCase();
    return [item.artist, item.title, item.label, item.format].some(function (f) {
      return f && f.toLowerCase().indexOf(q) !== -1;
    });
  }

  function starRow(item, isWant) {
    var rating = item.rating || 0;
    var stars = '';
    for (var i = 1; i <= 5; i++) {
      stars += '<button class="star' + (i <= rating ? ' filled' : '') + '" type="button" data-i="' + i + '" aria-label="Rate ' + i + '">&#9733;</button>';
    }
    return '<div class="stars" data-id="' + item.releaseId + '" data-instance="' + (item.instanceId || '') + '" data-want="' + (isWant ? '1' : '0') + '">' + stars + '</div>';
  }

  function itemCard(item, isWant) {
    var thumb = item.thumb
      ? '<img class="thumb" src="' + esc(item.thumb) + '" alt="" loading="lazy">'
      : '<div class="thumb placeholder"></div>';
    var meta = [item.year, item.format, item.label].filter(Boolean).join(' · ');
    var notes = item.notes ? '<div class="notes">' + esc(item.notes) + '</div>' : '';
    var actions = isWant
      ? '<button class="own" data-id="' + item.releaseId + '" type="button">Add to Collection</button>' +
        '<button class="remove" data-id="' + item.releaseId + '" type="button">Remove</button>'
      : '';
    return (
      '<li class="card" data-id="' + item.releaseId + '" data-artist="' + esc(item.artist || '') + '" data-title="' + esc(item.title || '') + '">' +
        '<div class="card-main">' +
          thumb +
          '<div class="body">' +
            '<div class="artist">' + esc(item.artist || 'Unknown artist') + '</div>' +
            '<div class="title">' + esc(item.title || 'Untitled') + '</div>' +
            '<div class="meta">' + esc(meta) + '</div>' +
            starRow(item, isWant) +
            notes +
          '</div>' +
          '<div class="actions">' +
            actions +
            '<button class="expand" type="button" aria-expanded="false">Details &#9662;</button>' +
          '</div>' +
        '</div>' +
        '<div class="details-panel hide"></div>' +
      '</li>'
    );
  }

  function gridTile(item) {
    var thumb = item.thumb
      ? '<img class="thumb" src="' + esc(item.thumb) + '" alt="" loading="lazy">'
      : '<div class="thumb placeholder"></div>';
    var caption = esc(item.artist || 'Unknown artist') + ' — ' + esc(item.title || 'Untitled');
    return (
      '<li class="tile" data-id="' + item.releaseId + '" title="' + caption + '">' +
        thumb +
      '</li>'
    );
  }

  function buildGenreChips(data) {
    var wrap = document.getElementById('genreChips');
    var counts = {};
    data.forEach(function (item) {
      itemGenres(item).forEach(function (g) { counts[g] = (counts[g] || 0) + 1; });
    });
    var genres = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; });
    if (!genres.length) {
      wrap.classList.add('hide');
      wrap.innerHTML = '';
      return;
    }
    if (genre !== 'all' && !counts[genre]) genre = 'all';
    wrap.classList.remove('hide');
    var html = '<button class="chip" type="button" data-g="all" aria-pressed="' + (genre === 'all') + '">All</button>';
    genres.forEach(function (g) {
      html += '<button class="chip" type="button" data-g="' + esc(g) + '" aria-pressed="' + (genre === g) + '">' +
              esc(g) + ' <span class="chipcount">' + counts[g] + '</span></button>';
    });
    wrap.innerHTML = html;
  }

  function sortItems(data) {
    return data.slice().sort(function (a, b) {
      if (sortBy === 'year') return (a.year || 0) - (b.year || 0) || (a.artist || '').localeCompare(b.artist || '');
      if (sortBy === 'title') return (a.title || '').localeCompare(b.title || '');
      return (a.artist || '').localeCompare(b.artist || '') || (a.year || 0) - (b.year || 0);
    });
  }

  function render() {
    document.getElementById('searchrow').classList.toggle('hide', tab === 'stats');
    document.getElementById('sortrow').classList.toggle('hide', tab === 'stats');
    document.getElementById('stats').classList.toggle('hide', tab !== 'stats');
    if (tab === 'stats') {
      list.innerHTML = '';
      empty.classList.add('hide');
      document.getElementById('genreChips').classList.add('hide');
      renderStats();
      return;
    }

    var full = (tab === 'collection' ? COLLECTION : WANTLIST);
    buildGenreChips(full);
    var data = sortItems(full.filter(matches));
    list.className = view === 'grid' ? 'grid' : '';
    if (!data.length) {
      list.innerHTML = '';
      empty.classList.remove('hide');
      return;
    }
    empty.classList.add('hide');
    list.innerHTML = view === 'grid'
      ? data.map(gridTile).join('')
      : data.map(function (item) { return itemCard(item, tab === 'wantlist'); }).join('');
  }

  /* ---------- stats dashboard ----------
     Single-series magnitude bars (count by genre / count by artist) — one
     hue, no legend needed, sorted descending, capped so it stays readable
     on a phone. Built from the Collection cache: "concentration in style"
     means what you actually own. */

  function barChart(counts, max, kind) {
    var top = Object.keys(counts)
      .map(function (k) { return [k, counts[k]]; })
      .sort(function (a, b) { return b[1] - a[1]; })
      .slice(0, max);
    if (!top.length) return '<p class="stats-empty">No data yet.</p>';
    var peak = top[0][1];
    return top.map(function (row, i) {
      var pct = Math.max(4, Math.round((row[1] / peak) * 100));
      var rankCls = i < 3 ? ' rank-' + (i + 1) : '';
      return (
        '<div class="barchart-row" data-kind="' + kind + '" data-value="' + esc(row[0]) + '" title="See these in your Collection">' +
          '<div class="barchart-rank' + rankCls + '">' + (i + 1) + '</div>' +
          '<div class="barchart-label">' + esc(row[0]) + '</div>' +
          '<div class="barchart-track">' +
            '<div class="barchart-fill' + rankCls + '" style="width:' + pct + '%"></div>' +
          '</div>' +
          '<div class="barchart-value">' + row[1] + '</div>' +
        '</div>'
      );
    }).join('');
  }

  function statTile(value, label) {
    return (
      '<div class="tile-stat">' +
        '<svg class="tile-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10.5"/><circle class="groove" cx="12" cy="12" r="7.2"/><circle class="hole" cx="12" cy="12" r="1.6"/></svg>' +
        '<div class="tile-value">' + value + '</div>' +
        '<div class="tile-label">' + label + '</div>' +
      '</div>'
    );
  }

  function renderStats() {
    var genreCounts = {}, artistCounts = {}, decadeCounts = {};
    COLLECTION.forEach(function (item) {
      itemGenres(item).forEach(function (g) { genreCounts[g] = (genreCounts[g] || 0) + 1; });
      var a = item.artist || 'Unknown artist';
      artistCounts[a] = (artistCounts[a] || 0) + 1;
      if (item.year) {
        var decade = Math.floor(item.year / 10) * 10 + 's';
        decadeCounts[decade] = (decadeCounts[decade] || 0) + 1;
      }
    });

    var topDecade = Object.keys(decadeCounts).sort(function (a, b) {
      return decadeCounts[b] - decadeCounts[a];
    })[0] || '—';

    document.getElementById('statTiles').innerHTML =
      statTile(COLLECTION.length.toLocaleString(), 'Records') +
      statTile(Object.keys(artistCounts).length.toLocaleString(), 'Artists') +
      statTile(Object.keys(genreCounts).length.toLocaleString(), 'Genres') +
      statTile(topDecade, 'Favorite era');

    document.getElementById('genreChart').innerHTML = barChart(genreCounts, 12, 'genre');
    document.getElementById('artistChart').innerHTML = barChart(artistCounts, 12, 'artist');
  }

  document.getElementById('stats').addEventListener('click', function (e) {
    var row = e.target.closest('.barchart-row');
    if (!row) return;
    tab = 'collection';
    genre = row.dataset.kind === 'genre' ? row.dataset.value : 'all';
    query = row.dataset.kind === 'artist' ? row.dataset.value : '';
    document.getElementById('q').value = query;
    [].forEach.call(document.querySelectorAll('#tabs .tab'), function (t) {
      t.setAttribute('aria-selected', t.dataset.tab === 'collection' ? 'true' : 'false');
    });
    document.getElementById('addForm').classList.add('hide');
    document.getElementById('searchResults').classList.add('hide');
    render();
  });

  list.addEventListener('click', async function (e) {
    var removeBtn = e.target.closest('.remove');
    var ownBtn = e.target.closest('.own');
    if (removeBtn) {
      var id = parseInt(removeBtn.dataset.id, 10);
      if (!confirm('Remove this from your wantlist?')) return;
      removeBtn.disabled = true;
      try {
        await gatedPost('wantlist-remove', { releaseId: id });
        WANTLIST = WANTLIST.filter(function (i) { return i.releaseId !== id; });
        render();
        say('Removed.');
      } catch (e2) {
        removeBtn.disabled = false;
        if (e2.message !== 'locked' && e2.code !== 'passkey_required') say('Not removed: ' + e2.message, true);
      }
      return;
    }
    if (ownBtn) {
      var wid = parseInt(ownBtn.dataset.id, 10);
      if (!confirm('Move this from your wantlist into your collection?')) return;
      ownBtn.disabled = true;
      try {
        var d = await gatedPost('wantlist-to-collection', { releaseId: wid });
        WANTLIST = WANTLIST.filter(function (i) { return i.releaseId !== wid; });
        if (d && d.item) COLLECTION.push(d.item);
        render();
        say('Added to your collection.');
      } catch (e2) {
        ownBtn.disabled = false;
        if (e2.message !== 'locked' && e2.code !== 'passkey_required') say('Not added: ' + e2.message, true);
      }
      return;
    }

    var star = e.target.closest('.star');
    if (star) {
      var starsWrap = star.closest('.stars');
      var value = parseInt(star.dataset.i, 10);
      var current = starsWrap.querySelectorAll('.star.filled').length;
      var next = value === current ? 0 : value; // tap the lit star again to clear
      var rid = parseInt(starsWrap.dataset.id, 10);
      var isW = starsWrap.dataset.want === '1';
      [].forEach.call(starsWrap.querySelectorAll('.star'), function (s, idx) {
        s.classList.toggle('filled', idx < next);
      });
      try {
        if (isW) {
          await gatedPost('wantlist-note', { releaseId: rid, rating: next });
          var wi = WANTLIST.find(function (i) { return i.releaseId === rid; });
          if (wi) wi.rating = next || null;
        } else {
          var instanceId = parseInt(starsWrap.dataset.instance, 10);
          await gatedPost('collection-rate', { instanceId: instanceId, rating: next });
          var ci = COLLECTION.find(function (i) { return i.releaseId === rid; });
          if (ci) ci.rating = next || null;
        }
      } catch (e3) {
        [].forEach.call(starsWrap.querySelectorAll('.star'), function (s, idx) {
          s.classList.toggle('filled', idx < current);
        });
        if (e3.message !== 'locked' && e3.code !== 'passkey_required') say('Could not save rating: ' + e3.message, true);
      }
      return;
    }

    var expandBtn = e.target.closest('.expand');
    if (expandBtn) {
      var card = expandBtn.closest('.card');
      var panel = card.querySelector('.details-panel');
      var opening = panel.classList.contains('hide');
      panel.classList.toggle('hide', !opening);
      expandBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening && !panel.dataset.loaded) {
        panel.dataset.loaded = '1';
        await loadDetails(panel, card.dataset.artist, card.dataset.title);
      }
      return;
    }

    var addSimBtn = e.target.closest('.addsimilar');
    if (addSimBtn) {
      var simId = parseInt(addSimBtn.dataset.id, 10);
      addSimBtn.disabled = true;
      try {
        await gatedPost('wantlist-add', { releaseId: simId });
        var w2 = await api('?action=wantlist');
        WANTLIST = w2.items || [];
        addSimBtn.outerHTML = '<span class="already">Added</span>';
        say('Added to your wantlist.');
      } catch (e4) {
        addSimBtn.disabled = false;
        if (e4.message !== 'locked' && e4.code !== 'passkey_required') say('Not added: ' + e4.message, true);
      }
      return;
    }
  });

  /* ---------- Last.fm details panel ----------
     Lazy-loaded on first expand, cached per (artist,title) for the rest of
     the session so re-toggling a panel never re-fetches. */
  var lastfmCache = {};

  async function loadDetails(panel, artist, title) {
    panel.innerHTML = '<p class="details-loading">Loading Last.fm details&hellip;</p>';
    var key = artist + '|' + title;
    try {
      var data = lastfmCache[key];
      if (!data) {
        data = await api('?action=lastfm-detail&artist=' + encodeURIComponent(artist) + '&title=' + encodeURIComponent(title));
        lastfmCache[key] = data;
      }
      panel.innerHTML = renderDetails(data);
    } catch (e) {
      panel.innerHTML = '<p class="details-loading">Could not load Last.fm details: ' + esc(e.message) + '</p>';
    }
  }

  function renderDetails(data) {
    var album = data.album;
    var html = '';
    if (album) {
      var stats = [];
      if (album.listeners != null) stats.push(album.listeners.toLocaleString() + ' listeners');
      if (album.playcount != null) stats.push(album.playcount.toLocaleString() + ' plays');
      html += '<div class="details-stats">' + esc(stats.join(' · ')) + '</div>';
      if (album.tags && album.tags.length) {
        html += '<div class="details-tags">' + album.tags.map(function (t) {
          return '<span class="tagchip">' + esc(t) + '</span>';
        }).join('') + '</div>';
      }
      if (album.summary) {
        html += '<p class="details-summary">' + esc(album.summary) + '</p>';
      }
      if (album.url) {
        html += '<a class="details-link" href="' + esc(album.url) + '" target="_blank" rel="noopener">View on Last.fm &#8599;</a>';
      }
    }
    if (data.similarArtists && data.similarArtists.length) {
      html += '<div class="details-heading">Similar artists</div>';
      html += '<div class="details-tags">' + data.similarArtists.map(function (a) {
        return a.url
          ? '<a class="tagchip link" href="' + esc(a.url) + '" target="_blank" rel="noopener">' + esc(a.name) + '</a>'
          : '<span class="tagchip">' + esc(a.name) + '</span>';
      }).join('') + '</div>';
    }
    if (data.similarAlbums && data.similarAlbums.length) {
      html += '<div class="details-heading">Similar albums</div>';
      html += '<ul class="details-albums">' + data.similarAlbums.map(function (al) {
        var label = esc(al.artist) + ' — ' + esc(al.title);
        var tag = al.tag ? ' <span class="viatag">via #' + esc(al.tag) + '</span>' : '';
        var link;
        if (al.releaseId) {
          link = '<a href="https://www.discogs.com/release/' + al.releaseId + '" target="_blank" rel="noopener">' + label + '</a>';
        } else if (al.url) {
          link = '<a href="' + esc(al.url) + '" target="_blank" rel="noopener">' + label + '</a>';
        } else {
          link = label;
        }
        var already = al.releaseId && (
          WANTLIST.some(function (i) { return i.releaseId === al.releaseId; }) ||
          COLLECTION.some(function (i) { return i.releaseId === al.releaseId; })
        );
        var action = al.releaseId
          ? (already
              ? ' <span class="already">Already added</span>'
              : ' <button class="addsimilar" data-id="' + al.releaseId + '" type="button">Add</button>')
          : '';
        return '<li>' + link + tag + action + '</li>';
      }).join('') + '</ul>';
    }
    return html || '<p class="details-loading">No Last.fm data found for this release.</p>';
  }

  document.getElementById('q').addEventListener('input', function (e) {
    query = e.target.value;
    render();
  });

  document.getElementById('sortSelect').addEventListener('change', function (e) {
    sortBy = e.target.value;
    render();
  });

  document.getElementById('tabs').addEventListener('click', function (e) {
    var b = e.target.closest('.tab');
    if (!b) return;
    tab = b.dataset.tab;
    genre = 'all';
    [].forEach.call(this.querySelectorAll('.tab'), function (t) {
      t.setAttribute('aria-selected', t === b ? 'true' : 'false');
    });
    document.getElementById('addForm').classList.toggle('hide', tab !== 'wantlist');
    if (tab !== 'wantlist') document.getElementById('searchResults').classList.add('hide');
    render();
  });

  document.getElementById('genreChips').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    genre = b.dataset.g;
    render();
  });

  document.getElementById('viewToggle').addEventListener('click', function (e) {
    var b = e.target.closest('.chip');
    if (!b) return;
    view = b.dataset.v;
    [].forEach.call(this.querySelectorAll('.chip'), function (c) {
      c.setAttribute('aria-pressed', c === b ? 'true' : 'false');
    });
    try { localStorage.setItem('pizzaparty.view', view); } catch (ex) {}
    render();
  });

  function searchResultRow(item) {
    var thumb = item.thumb
      ? '<img class="thumb" src="' + esc(item.thumb) + '" alt="" loading="lazy">'
      : '<div class="thumb placeholder"></div>';
    var meta = [item.year, item.format].filter(Boolean).join(' · ');
    var already = WANTLIST.some(function (i) { return i.releaseId === item.releaseId; }) ||
                  COLLECTION.some(function (i) { return i.releaseId === item.releaseId; });
    return (
      '<li class="card searchresult">' +
        '<div class="card-main">' +
          thumb +
          '<div class="body">' +
            '<div class="artist">' + esc(item.artist || 'Unknown artist') + '</div>' +
            '<div class="title">' + esc(item.title || 'Untitled') + '</div>' +
            '<div class="meta">' + esc(meta) + '</div>' +
          '</div>' +
          '<div class="actions">' +
            (already
              ? '<span class="already">Already added</span>'
              : '<button class="addwant" data-id="' + item.releaseId + '" type="button">Add</button>') +
          '</div>' +
        '</div>' +
      '</li>'
    );
  }

  document.getElementById('addForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var input = document.getElementById('addQuery');
    var q = input.value.trim();
    var results = document.getElementById('searchResults');
    if (!q) { say('Enter something to search for.', true); return; }
    var btn = this.querySelector('button');
    btn.disabled = true;
    results.classList.remove('hide');
    results.innerHTML = '<p class="details-loading">Searching Discogs&hellip;</p>';
    try {
      var d = await api('?action=search&q=' + encodeURIComponent(q));
      var items = d.items || [];
      results.innerHTML = items.length
        ? '<ul class="searchlist">' + items.map(searchResultRow).join('') + '</ul>'
        : '<p class="details-loading">No results.</p>';
    } catch (e2) {
      results.innerHTML = '<p class="details-loading">Search failed: ' + esc(e2.message) + '</p>';
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('searchResults').addEventListener('click', async function (e) {
    var btn = e.target.closest('.addwant');
    if (!btn) return;
    var id = parseInt(btn.dataset.id, 10);
    btn.disabled = true;
    try {
      await gatedPost('wantlist-add', { releaseId: id });
      var w = await api('?action=wantlist');
      WANTLIST = w.items || [];
      btn.outerHTML = '<span class="already">Added</span>';
      say('Added to your wantlist.');
      render();
    } catch (e2) {
      btn.disabled = false;
      if (e2.message !== 'locked' && e2.code !== 'passkey_required') say('Not added: ' + e2.message, true);
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
      var v = localStorage.getItem('pizzaparty.view');
      if (v === 'grid' || v === 'list') {
        view = v;
        [].forEach.call(document.querySelectorAll('#viewToggle .chip'), function (c) {
          c.setAttribute('aria-pressed', c.dataset.v === view ? 'true' : 'false');
        });
      }
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
