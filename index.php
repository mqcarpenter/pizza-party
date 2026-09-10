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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/app.css?v=2">

<link rel="manifest" href="manifest.json">
<link rel="shortcut icon" href="favicon.ico">
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
    <div class="wrap row">
      <img class="logo" src="assets/logo.png" alt="" width="44" height="44">
      <div>
        <span class="brandline"><span class="dot"></span>licoricepizzareviews.com</span>
        <h1>Pizza Party
          <span class="thin">Your records, everywhere</span>
        </h1>
      </div>
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
        <button class="tab" type="button" data-tab="stats" aria-selected="false">Stats</button>
      </div>

      <div class="searchrow" id="searchrow">
        <div class="searchwrap">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="9" cy="9" r="6"/><path d="M13.5 13.5L18 18" stroke-linecap="round"/>
          </svg>
          <input class="search" id="q" type="search" placeholder="Search artist, title, label&hellip;" autocomplete="off">
        </div>
        <div class="chips viewchips" id="viewToggle" role="group" aria-label="View">
          <button class="chip" type="button" data-v="list" aria-pressed="true" title="List view">List</button>
          <button class="chip" type="button" data-v="grid" aria-pressed="false" title="Grid view">Grid</button>
        </div>
        <button class="lock open" id="lockBtn" type="button">&mdash;</button>
      </div>

      <div class="sortrow" id="sortrow">
        <label for="sortSelect">Sort</label>
        <select id="sortSelect">
          <option value="artist">Artist</option>
          <option value="year">Year</option>
          <option value="title">Title</option>
        </select>
      </div>

      <div class="chips genrechips hide" id="genreChips"></div>

      <form class="addrow hide" id="addForm">
        <input class="addinput" id="addQuery" type="search"
               placeholder="Search Discogs to add&hellip;" autocomplete="off">
        <button type="submit">Search</button>
      </form>
      <div class="searchresults hide" id="searchResults"></div>
    </div>

    <main id="list"></main>
    <div class="empty hide" id="empty">Nothing here yet.</div>

    <div class="stats hide" id="stats">
      <div class="tiles" id="statTiles"></div>

      <section class="stats-section panel">
        <h2><svg class="vinyl" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10.5"/><circle class="groove" cx="12" cy="12" r="7.2"/><circle class="groove" cx="12" cy="12" r="4.4"/><circle class="hole" cx="12" cy="12" r="1.6"/></svg>By genre</h2>
        <p class="stats-sub">Concentration of your collection by genre.</p>
        <div class="barchart" id="genreChart"></div>
      </section>
      <section class="stats-section panel">
        <h2><svg class="vinyl" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10.5"/><circle class="groove" cx="12" cy="12" r="7.2"/><circle class="groove" cx="12" cy="12" r="4.4"/><circle class="hole" cx="12" cy="12" r="1.6"/></svg>By artist</h2>
        <p class="stats-sub">Your most-represented artists.</p>
        <div class="barchart" id="artistChart"></div>
      </section>
    </div>

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
