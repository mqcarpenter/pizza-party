<?php require __DIR__ . '/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1b1410">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Pizza Party">
<meta name="description" content="Browse your Discogs record collection and wantlist.">
<title>Pizza Party</title>
<link rel="stylesheet" href="assets/app.css?v=1">

<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
<meta name="mobile-web-app-capable" content="yes">
</head>
<body>

<div id="gate" class="wrap hide">
  <form class="gate" id="gateForm">
    <div class="mark"></div>
    <h2>Pizza Party</h2>
    <p>Enter the passphrase to view your collection.</p>
    <input type="password" id="gatePass" placeholder="Passphrase" autocomplete="current-password" required>
    <button type="submit">Unlock</button>
    <p class="err" id="gateErr"></p>
  </form>
</div>

<div id="app" class="hide">

  <div class="masthead">
    <div class="wrap">
      <span class="brandline"><span class="dot"></span>Discogs collection &amp; wantlist</span>
      <h1>Pizza Party
        <span class="thin">Your records, everywhere</span>
      </h1>
    </div>
  </div>

  <div class="wrap">

    <div id="connectBanner" class="banner hide">
      Discogs isn't connected yet.
      <a href="api/index.php?action=discogs-connect">Connect your Discogs account</a>
    </div>

    <div class="sticky">
      <div class="tabs" id="tabs" role="tablist">
        <button class="tab" type="button" data-tab="collection" aria-selected="true">Collection</button>
        <button class="tab" type="button" data-tab="wantlist" aria-selected="false">Wantlist</button>
      </div>

      <div class="searchrow">
        <div class="searchwrap">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="9" cy="9" r="6"/><path d="M13.5 13.5L18 18" stroke-linecap="round"/>
          </svg>
          <input class="search" id="q" type="search" placeholder="Search artist, title, label&hellip;" autocomplete="off">
        </div>
        <button class="lock open" id="lockBtn" type="button">&mdash;</button>
      </div>

      <form class="addrow hide" id="addForm">
        <input class="addinput" id="addReleaseId" type="text" inputmode="numeric"
               placeholder="Discogs release ID to add&hellip;" autocomplete="off">
        <button type="submit">Add</button>
      </form>
      <p class="hint hide" id="addHint">Paste the release ID from a discogs.com release URL
        (e.g. discogs.com/release/<b>249504</b>). Search-to-add is coming later.</p>
    </div>

    <main id="list"></main>
    <div class="empty hide" id="empty">Nothing here yet.</div>

    <footer>
      <div id="syncNote">&nbsp;</div>
      <button id="theme" type="button">Toggle theme</button>
    </footer>
  </div>
</div>

<div id="toast" role="status" aria-live="polite"></div>
<script src="assets/app.js?v=1"></script>
</body>
</html>
