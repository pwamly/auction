<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

start_session();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$user = current_user();
$boot = [
    'user'      => $user ? ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']] : null,
    'csrf'      => $_SESSION['csrf'],
    'serverNow' => server_now_ms(),
    'passwordMinLength' => PASSWORD_MIN_LENGTH,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lotline Auctions</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Expanded:wght@700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#F6F4EC; --paper-2:#ECE7D6; --ink:#182B1D; --ink-soft:#51624F;
    --rust:#1E7A37; --rust-dark:#145C29; --moss:#0B6FA4; --alert:#B3261E;
    --gold:#C99A1E; --line:#D6D0B9; --card:#FFFFFF;
  }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      --paper:#12180F; --paper-2:#1A2216; --ink:#E9EDE4; --ink-soft:#A9B6A3;
      --rust:#3FAE5C; --rust-dark:#2E8C46; --moss:#3F9FD6; --alert:#E2554A;
      --gold:#E0B84A; --line:#2C3A28; --card:#1A2216;
    }
  }
  :root[data-theme="dark"]{
    --paper:#12180F; --paper-2:#1A2216; --ink:#E9EDE4; --ink-soft:#A9B6A3;
    --rust:#3FAE5C; --rust-dark:#2E8C46; --moss:#3F9FD6; --alert:#E2554A;
    --gold:#E0B84A; --line:#2C3A28; --card:#1A2216;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{ background:var(--paper); color:var(--ink); font-family:'Archivo', sans-serif; min-height:100vh; -webkit-font-smoothing:antialiased; }
  .tabular{ font-variant-numeric: tabular-nums; }
  a{color:var(--rust);}
  .hidden{ display:none !important; }

  header{ border-bottom:3px solid var(--gold); padding:28px 24px 22px; }
  .header-inner{ max-width:920px; margin:0 auto; display:flex; justify-content:space-between; align-items:flex-end; gap:20px; flex-wrap:wrap; }
  .wordmark{ font-family:'Archivo Expanded', sans-serif; font-weight:800; font-size:26px; letter-spacing:-0.01em; margin:0; }
  .wordmark span{color:var(--rust);}
  .tagline{ margin:4px 0 0; color:var(--ink-soft); font-size:14px; max-width:44ch; }
  .session-box{ text-align:right; font-size:13px; color:var(--ink-soft); }
  .session-box .who{ font-size:15px; font-weight:700; color:var(--ink); margin-bottom:6px; }
  .session-box .role-tag{
    display:inline-block; font-size:10.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:0.04em; padding:2px 7px; border-radius:3px; margin-left:6px;
    background:rgba(30,122,55,0.12); color:var(--rust-dark); vertical-align:middle;
  }
  .logout-btn{
    background:none; border:1px solid var(--line); color:var(--ink-soft); font-family:inherit;
    font-size:12.5px; font-weight:600; cursor:pointer; padding:6px 12px; border-radius:3px;
  }
  .logout-btn:hover{ color:var(--alert); border-color:var(--alert); }

  main{ max-width:920px; margin:0 auto; padding:32px 24px 80px; }

  /* ---------- Auth screen ---------- */
  .auth-wrap{ max-width:420px; margin:40px auto; }
  .auth-card{ background:var(--card); border:1px solid var(--line); border-radius:6px; padding:32px; }
  .auth-tabs{ display:flex; gap:0; margin-bottom:24px; border-bottom:1px solid var(--line); }
  .auth-tab{
    flex:1; text-align:center; padding:10px 0; font-size:13.5px; font-weight:700; cursor:pointer;
    color:var(--ink-soft); border-bottom:2px solid transparent; margin-bottom:-1px;
  }
  .auth-tab.active{ color:var(--rust); border-bottom-color:var(--rust); }
  .auth-field{ margin-bottom:14px; }
  .auth-field label{ display:block; font-size:12px; color:var(--ink-soft); margin-bottom:4px; }
  .auth-field input, .auth-field select{
    font-family:inherit; font-size:14px; padding:10px 11px; border:1px solid var(--line);
    border-radius:3px; background:var(--paper); color:var(--ink); width:100%;
  }
  .auth-submit{
    width:100%; background:var(--rust); color:#fff; border:none; font-weight:700; font-size:14px;
    padding:11px 0; border-radius:3px; cursor:pointer; font-family:inherit; margin-top:6px;
  }
  .auth-submit:hover{ background:var(--rust-dark); }
  .auth-error{ font-size:12.5px; color:var(--alert); margin-top:10px; min-height:14px; }
  .auth-hint{ font-size:12px; color:var(--ink-soft); margin-top:16px; line-height:1.5; border-top:1px dashed var(--line); padding-top:14px; }
  .auth-hint b{ color:var(--ink); }

  /* ---------- Lots list (bidder + admin shared) ---------- */
  .lots-header{ display:flex; justify-content:space-between; align-items:baseline; border-bottom:2px solid var(--ink); padding-bottom:10px; margin-bottom:4px; }
  .lots-header h2{ font-size:15px; text-transform:uppercase; letter-spacing:0.04em; margin:0; font-weight:700; }
  .new-lot-btn{ background:none; border:none; color:var(--rust); font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; padding:4px 0; }
  .new-lot-btn:hover{ text-decoration:underline; }

  .lot{ border-bottom:1px solid var(--line); padding:26px 0; display:grid; grid-template-columns:64px 1fr 220px; gap:20px; }
  @media (max-width:680px){ .lot{ grid-template-columns:1fr; gap:10px; } .lot-num{ font-size:13px; } }
  .lot-num{ font-weight:700; color:var(--ink-soft); font-size:13px; letter-spacing:0.02em; padding-top:3px; }
  .lot-main h3{ margin:0 0 4px; font-size:19px; font-weight:700; }
  .lot-desc{ margin:0 0 12px; color:var(--ink-soft); font-size:14px; line-height:1.5; max-width:52ch; }
  .lot-meta{ display:flex; gap:18px; flex-wrap:wrap; font-size:13px; color:var(--ink-soft); margin-bottom:14px; }
  .lot-meta b{ color:var(--ink); font-weight:600; }

  .bid-history{ margin-top:10px; }
  .bid-history summary{ cursor:pointer; font-size:12.5px; color:var(--moss); font-weight:600; }
  .bid-history ul{ list-style:none; margin:8px 0 0; padding:0; font-size:13px; }
  .bid-history li{ display:flex; justify-content:space-between; padding:4px 0; border-bottom:1px dashed var(--line); color:var(--ink-soft); }
  .bid-history li b{ color:var(--ink); }

  .lot-side{ text-align:left; }
  .status-pill{ display:inline-block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; padding:3px 8px; border-radius:3px; margin-bottom:10px; }
  .status-live{ background:rgba(30,122,55,0.12); color:var(--rust-dark); }
  .status-ending{ background:rgba(179,38,30,0.15); color:var(--alert); }
  .status-closed{ background:rgba(11,111,164,0.15); color:var(--moss); }

  .current-bid-label{ font-size:11px; color:var(--ink-soft); text-transform:uppercase; letter-spacing:0.03em; margin-bottom:2px; }
  .current-bid{ font-size:26px; font-weight:800; margin-bottom:2px; }
  .current-bidder{ font-size:12.5px; color:var(--ink-soft); margin-bottom:14px; }
  .countdown{ font-size:13px; color:var(--ink-soft); margin-bottom:14px; }
  .countdown .time{ font-weight:700; color:var(--ink); }
  .countdown.urgent .time{ color:var(--alert); }

  .bid-form{ display:flex; gap:6px; }
  .bid-form input{ font-family:inherit; font-size:14px; padding:9px 10px; border:1px solid var(--line); border-radius:3px; background:var(--card); color:var(--ink); width:100%; min-width:0; }
  .bid-form button{ font-family:inherit; font-weight:700; font-size:13px; background:var(--rust); color:#fff; border:none; border-radius:3px; padding:9px 14px; cursor:pointer; white-space:nowrap; }
  .bid-form button:hover{ background:var(--rust-dark); }
  .bid-form button:disabled{ background:var(--line); cursor:not-allowed; }
  .bid-error{ font-size:12px; color:var(--alert); margin-top:6px; min-height:14px; }

  .won-banner{ background:rgba(11,111,164,0.12); border:1px solid var(--moss); border-radius:3px; padding:10px 12px; font-size:13.5px; }
  .won-banner b{ display:block; margin-bottom:2px; }

  .admin-actions{ margin-top:10px; }
  .delete-lot-btn{
    background:none; border:1px solid var(--line); color:var(--ink-soft); font-family:inherit;
    font-size:11.5px; font-weight:600; cursor:pointer; padding:5px 9px; border-radius:3px;
  }
  .delete-lot-btn:hover{ color:var(--alert); border-color:var(--alert); }

  /* ---------- New lot form ---------- */
  .new-lot-form{ background:var(--card); border:1px solid var(--line); border-radius:4px; padding:20px; margin:20px 0 32px; display:none; }
  .new-lot-form.open{ display:block; }
  .new-lot-form h3{ margin:0 0 14px; font-size:15px; }
  .form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .form-grid .full{ grid-column:1 / -1; }
  .form-grid label{ display:block; font-size:12px; color:var(--ink-soft); margin-bottom:4px; }
  .form-grid input, .form-grid select{ font-family:inherit; font-size:14px; padding:8px 10px; border:1px solid var(--line); border-radius:3px; background:var(--paper); color:var(--ink); width:100%; }
  .form-actions{ margin-top:16px; display:flex; gap:10px; }
  .btn-primary{ background:var(--rust); color:#fff; border:none; font-weight:700; font-size:13px; padding:9px 16px; border-radius:3px; cursor:pointer; font-family:inherit; }
  .btn-primary:hover{ background:var(--rust-dark); }
  .btn-secondary{ background:none; border:1px solid var(--line); color:var(--ink-soft); font-weight:600; font-size:13px; padding:9px 16px; border-radius:3px; cursor:pointer; font-family:inherit; }

  .empty-state{ padding:40px 0; text-align:center; color:var(--ink-soft); font-size:14px; }

  .sync-banner{
    font-size:12.5px; background:rgba(179,38,30,0.1); color:var(--alert); border:1px solid var(--alert);
    border-radius:4px; padding:9px 12px; margin-bottom:20px;
  }

  /* ---------- Admin tabs ---------- */
  .admin-tabs{ display:flex; gap:24px; border-bottom:1px solid var(--line); margin-bottom:28px; }
  .admin-tab{
    background:none; border:none; border-bottom:2px solid transparent; margin-bottom:-1px; padding:8px 0;
    font-family:inherit; font-size:13.5px; font-weight:700; color:var(--ink-soft); cursor:pointer;
  }
  .admin-tab:hover{ color:var(--ink); }
  .admin-tab.active{ color:var(--rust); border-bottom-color:var(--rust); }

  /* ---------- Reports ---------- */
  .report-filters{ display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin:20px 0 22px; }
  .report-filters label{ display:block; font-size:12px; color:var(--ink-soft); margin-bottom:4px; }
  .report-filters input, .report-filters select{
    font-family:inherit; font-size:13.5px; padding:7px 9px; border:1px solid var(--line);
    border-radius:3px; background:var(--card); color:var(--ink);
  }
  .report-filters .grow{ flex:1 1 180px; }
  .report-filters .grow input{ width:100%; }
  .report-filters .actions{ display:flex; gap:8px; }

  .stat-row{ display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:12px; margin-bottom:24px; }
  .stat{ background:var(--card); border:1px solid var(--line); border-radius:4px; padding:12px 14px; }
  .stat-label{ font-size:11px; text-transform:uppercase; letter-spacing:0.03em; color:var(--ink-soft); margin-bottom:4px; }
  .stat-value{ font-size:22px; font-weight:800; }

  .table-wrap{ overflow-x:auto; border:1px solid var(--line); border-radius:4px; background:var(--card); }
  .report-table{ width:100%; border-collapse:collapse; font-size:13.5px; min-width:760px; }
  .report-table th{
    text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:0.03em; color:var(--ink-soft);
    font-weight:700; padding:10px 12px; border-bottom:2px solid var(--line); background:var(--paper-2); white-space:nowrap;
  }
  .report-table td{ padding:10px 12px; border-bottom:1px solid var(--line); vertical-align:top; }
  .report-table tbody tr:last-child td{ border-bottom:none; }
  .report-table tbody tr:hover td{ background:var(--paper); }
  .report-table .num{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .report-table .muted{ color:var(--ink-soft); }
  .report-table .product{ min-width:180px; }
  .report-table .status-pill{ margin:0; }
  .status-unsold{ background:var(--paper-2); color:var(--ink-soft); }
  .status-cancelled{ background:rgba(179,38,30,0.15); color:var(--alert); }
  .report-note{ font-size:12.5px; color:var(--ink-soft); margin-top:10px; }

  footer{ text-align:center; color:var(--ink-soft); font-size:12px; padding:30px 24px 40px; }
</style>
</head>
<body>

<header>
  <div class="header-inner">
    <div>
      <p class="wordmark">Lot<span>line</span></p>
      <p class="tagline" id="tagline">Sealed-ledger bidding on raw materials and commodity lots.</p>
    </div>
    <div class="session-box hidden" id="sessionBox">
      <div class="who" id="sessionWho"></div>
      <button class="logout-btn" id="logoutBtn" type="button">Log out</button>
    </div>
  </div>
</header>

<main>

  <!-- ============ MODULE 1: AUTH ============ -->
  <div id="screen-auth">
    <div class="auth-wrap">
      <div class="auth-card">
        <div class="auth-tabs">
          <div class="auth-tab active" id="tabLogin" data-tab="login">Log in</div>
          <div class="auth-tab" id="tabSignup" data-tab="signup">Sign up</div>
        </div>

        <div id="loginForm">
          <div class="auth-field">
            <label for="loginUsername">Username</label>
            <input type="text" id="loginUsername" autocomplete="username">
          </div>
          <div class="auth-field">
            <label for="loginPassword">Password</label>
            <input type="password" id="loginPassword" autocomplete="current-password">
          </div>
          <button class="auth-submit" id="loginSubmit" type="button">Log in</button>
          <div class="auth-error" id="loginError"></div>
        </div>

        <div id="signupForm" class="hidden">
          <div class="auth-field">
            <label for="signupUsername">Choose a username</label>
            <input type="text" id="signupUsername" autocomplete="username" placeholder="letters, numbers, underscore">
          </div>
          <div class="auth-field">
            <label for="signupPassword">Choose a password</label>
            <input type="password" id="signupPassword" autocomplete="new-password">
          </div>
          <div class="auth-field">
            <label for="signupConfirm">Confirm password</label>
            <input type="password" id="signupConfirm" autocomplete="new-password">
          </div>
          <button class="auth-submit" id="signupSubmit" type="button">Create bidder account</button>
          <div class="auth-error" id="signupError"></div>
        </div>

        <div class="auth-hint">
          New bidders sign up here and go straight to the auction floor. Admin accounts aren't self-registered — they're created by the site operator.
        </div>
      </div>
    </div>
  </div>

  <!-- ============ MODULE 2: BIDDER (AUCTION) PAGE ============ -->
  <div id="screen-bidder" class="hidden">
    <div id="bidderSyncBanner" class="sync-banner hidden">Can't reach the auction server. Retrying — bids and countdowns may be out of date.</div>
    <div class="lots-header">
      <h2>Open lots</h2>
    </div>
    <div id="bidderLotsContainer"></div>
  </div>

  <!-- ============ MODULE 3: ADMIN PAGE ============ -->
  <div id="screen-admin" class="hidden">
    <div id="adminSyncBanner" class="sync-banner hidden">Can't reach the auction server. Retrying — the list below may be out of date.</div>

    <div class="admin-tabs" role="tablist">
      <button class="admin-tab active" type="button" role="tab" data-admin-tab="manage">Lots</button>
      <button class="admin-tab" type="button" role="tab" data-admin-tab="reports">Reports</button>
    </div>

    <div id="adminManage">
    <div class="lots-header">
      <h2>Manage lots</h2>
      <button class="new-lot-btn" id="toggleNewLot" type="button">+ List a new lot</button>
    </div>

    <div class="new-lot-form" id="newLotForm">
      <h3>List a new lot</h3>
      <div class="form-grid">
        <div class="full">
          <label for="nlName">Product name</label>
          <input type="text" id="nlName" placeholder="e.g. Recycled Rubber Granules, 20t">
        </div>
        <div class="full">
          <label for="nlDesc">Description</label>
          <input type="text" id="nlDesc" placeholder="Short description of the lot">
        </div>
        <div>
          <label for="nlStart">Starting price ($)</label>
          <input type="number" id="nlStart" min="0" step="1" value="100">
        </div>
        <div>
          <label for="nlIncrement">Minimum increment ($)</label>
          <input type="number" id="nlIncrement" min="1" step="1" value="5">
        </div>
        <div>
          <label for="nlDuration">Auction length</label>
          <select id="nlDuration">
<?php foreach (LOT_DURATIONS as $seconds => $label): ?>
            <option value="<?= (int) $seconds ?>"<?= $seconds === 120 ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
<?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn-primary" id="submitNewLot" type="button">List lot</button>
        <button class="btn-secondary" id="cancelNewLot" type="button">Cancel</button>
      </div>
      <div class="auth-error" id="newLotError"></div>
    </div>

    <div id="adminLotsContainer"></div>
    </div>

    <div id="adminReports" class="hidden">
      <div class="lots-header">
        <h2>Auction results</h2>
      </div>

      <div class="report-filters">
        <div>
          <label for="rpFrom">Finished from</label>
          <input type="date" id="rpFrom">
        </div>
        <div>
          <label for="rpTo">to</label>
          <input type="date" id="rpTo">
        </div>
        <div>
          <label for="rpStatus">Outcome</label>
          <select id="rpStatus">
            <option value="">All</option>
            <option value="sold">Sold</option>
            <option value="unsold">Unsold</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
        <div class="grow">
          <label for="rpSearch">Search</label>
          <input type="search" id="rpSearch" placeholder="Product or winner">
        </div>
        <div class="actions">
          <button class="btn-secondary" id="rpReset" type="button">Reset</button>
          <button class="btn-primary" id="rpExport" type="button">Export CSV</button>
        </div>
      </div>

      <div class="stat-row" id="rpStats"></div>
      <div id="rpTableContainer"></div>
      <div class="report-note" id="rpNote"></div>
    </div>
  </div>

</main>

<footer>
  Lotline Auctions · Closing times are set by the auction server clock.
</footer>

<script>
(function(){

  // ================= Server boot data =================
  var BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var API_URL = 'api'; // the htdocs .htaccess maps extensionless URLs to .php
  var POLL_MS = 3000;

  var csrf = BOOT.csrf;
  var session = BOOT.user; // { id, username, role } or null
  // Countdowns use the server clock, corrected for the difference from this computer's clock.
  var clockOffset = BOOT.serverNow - Date.now();
  function now(){ return Date.now() + clockOffset; }

  // ================= API =================
  function apiUrl(action, query){
    var params = new URLSearchParams(query || {});
    params.set('action', action);
    return API_URL + '?' + params.toString();
  }

  // payload => POST with a JSON body; query => extra GET parameters.
  function api(action, payload, query){
    var opts = { method: payload ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if(payload){
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = csrf;
      opts.body = JSON.stringify(payload);
    }
    return fetch(apiUrl(action, query), opts).then(function(res){
      return res.json().catch(function(){ return {}; }).then(function(body){
        if(typeof body.serverNow === 'number') clockOffset = body.serverNow - Date.now();
        if(body.csrf) csrf = body.csrf;
        if(!res.ok){
          var err = new Error(body.error || 'Something went wrong. Try again.');
          err.status = res.status;
          err.body = body;
          throw err;
        }
        return body;
      });
    });
  }

  function handleSessionExpired(err){
    if(err && err.status === 401 && session){
      stopAllSubscriptions();
      session = null;
      routeToSession();
      document.getElementById('loginError').textContent = err.message;
      return true;
    }
    return false;
  }

  // ================= Helpers =================
  function fmtMoney(n){ return '$' + Number(n).toLocaleString(undefined, {maximumFractionDigits:2}); }
  function escapeHtml(str){ var d=document.createElement('div'); d.textContent = str==null?'':String(str); return d.innerHTML; }
  function pad(n){ return String(n).padStart(2,'0'); }
  function fmtDuration(ms){
    if(ms<=0) return '00:00:00';
    var totalSec = Math.floor(ms/1000);
    var h = Math.floor(totalSec/3600), m = Math.floor((totalSec%3600)/60), s = totalSec%60;
    if(h>0) return pad(h)+':'+pad(m)+':'+pad(s);
    return pad(m)+':'+pad(s);
  }

  // ================= Screen routing =================
  function showScreen(name){
    ['auth','bidder','admin'].forEach(function(n){
      document.getElementById('screen-'+n).classList.toggle('hidden', n!==name);
    });
    var sessionBox = document.getElementById('sessionBox');
    if(name === 'auth'){
      sessionBox.classList.add('hidden');
      document.getElementById('tagline').textContent = 'Sealed-ledger bidding on raw materials and commodity lots. Log in or sign up to continue.';
    } else {
      sessionBox.classList.remove('hidden');
      var roleLabel = session.role === 'admin' ? 'Admin' : 'Bidder';
      document.getElementById('sessionWho').innerHTML = escapeHtml(session.username) + '<span class="role-tag">'+roleLabel+'</span>';
      document.getElementById('tagline').textContent = name === 'admin'
        ? 'List new lots and monitor bidding across the floor.'
        : 'Highest bid standing when the clock runs out wins the lot.';
    }
  }

  function routeToSession(){
    if(!session){ showScreen('auth'); return; }
    if(session.role === 'admin'){ showScreen('admin'); setAdminTab('manage'); startAdminSubscription(); }
    else { showScreen('bidder'); startBidderSubscription(); }
  }

  document.getElementById('logoutBtn').addEventListener('click', function(){
    stopAllSubscriptions();
    api('logout', {}).catch(function(){}).then(function(){
      session = null;
      routeToSession();
    });
  });

  // ================= Auth: tabs =================
  document.getElementById('tabLogin').addEventListener('click', function(){ setAuthTab('login'); });
  document.getElementById('tabSignup').addEventListener('click', function(){ setAuthTab('signup'); });
  function setAuthTab(tab){
    document.getElementById('tabLogin').classList.toggle('active', tab==='login');
    document.getElementById('tabSignup').classList.toggle('active', tab==='signup');
    document.getElementById('loginForm').classList.toggle('hidden', tab!=='login');
    document.getElementById('signupForm').classList.toggle('hidden', tab!=='signup');
    document.getElementById('loginError').textContent = '';
    document.getElementById('signupError').textContent = '';
  }

  // ================= Auth: login =================
  document.getElementById('loginSubmit').addEventListener('click', function(){ doLogin(); });
  document.getElementById('loginPassword').addEventListener('keydown', function(e){ if(e.key==='Enter') doLogin(); });

  function doLogin(){
    var errEl = document.getElementById('loginError');
    var btn = document.getElementById('loginSubmit');
    errEl.textContent = '';
    var uname = document.getElementById('loginUsername').value.trim();
    var pass = document.getElementById('loginPassword').value;
    if(!uname || !pass){ errEl.textContent = 'Enter both a username and password.'; return; }

    btn.disabled = true;
    api('login', { username: uname, password: pass }).then(function(body){
      session = body.user;
      document.getElementById('loginUsername').value = '';
      document.getElementById('loginPassword').value = '';
      routeToSession();
    }).catch(function(err){
      errEl.textContent = err.message;
    }).then(function(){ btn.disabled = false; });
  }

  // ================= Auth: signup (bidder only) =================
  document.getElementById('signupSubmit').addEventListener('click', function(){ doSignup(); });
  document.getElementById('signupConfirm').addEventListener('keydown', function(e){ if(e.key==='Enter') doSignup(); });

  function doSignup(){
    var errEl = document.getElementById('signupError');
    var btn = document.getElementById('signupSubmit');
    errEl.textContent = '';
    var uname = document.getElementById('signupUsername').value.trim().toLowerCase();
    var pass = document.getElementById('signupPassword').value;
    var confirmPass = document.getElementById('signupConfirm').value;

    // Quick checks for instant feedback; the server enforces the same rules.
    if(!/^[a-z0-9_-]{3,32}$/.test(uname)){ errEl.textContent = 'Username must be 3–32 characters: letters, numbers, - and _.'; return; }
    if(pass.length < BOOT.passwordMinLength){ errEl.textContent = 'Password must be at least ' + BOOT.passwordMinLength + ' characters.'; return; }
    if(pass !== confirmPass){ errEl.textContent = 'Passwords do not match.'; return; }

    btn.disabled = true;
    api('signup', { username: uname, password: pass, confirm: confirmPass }).then(function(body){
      session = body.user;
      document.getElementById('signupUsername').value = '';
      document.getElementById('signupPassword').value = '';
      document.getElementById('signupConfirm').value = '';
      routeToSession();
    }).catch(function(err){
      errEl.textContent = err.message;
    }).then(function(){ btn.disabled = false; });
  }

  // ================= Shared lot rendering =================
  var lotsCache = [];      // lots as returned by the API
  var lotsSignature = '';  // JSON of the last rendered data, to skip identical re-renders
  var bidErrors = {};      // lotId -> message shown under that lot's bid form

  function currentBid(lot){ return lot.bids.length ? lot.bids[lot.bids.length-1].amount : lot.startingPrice; }
  function minNextBid(lot){ return lot.bids.length ? currentBid(lot) + lot.increment : lot.startingPrice; }
  function timeLeft(lot){ return lot.endTime - now(); }

  function sortedLots(){
    return lotsCache.slice().sort(function(a,b){
      var aClosed = timeLeft(a) <= 0, bClosed = timeLeft(b) <= 0;
      if(aClosed !== bClosed) return aClosed ? 1 : -1;
      return a.endTime - b.endTime;
    });
  }

  function renderLotCard(lot, mode){
    var wrap = document.createElement('div');
    wrap.className = 'lot';

    var remaining = timeLeft(lot);
    var closed = remaining <= 0;
    var urgent = !closed && remaining < 30000;

    var numEl = document.createElement('div');
    numEl.className = 'lot-num tabular';
    numEl.textContent = 'LOT ' + String(lot.id).padStart(3,'0');
    wrap.appendChild(numEl);

    var main = document.createElement('div');
    main.className = 'lot-main';
    var h3 = document.createElement('h3'); h3.textContent = lot.name; main.appendChild(h3);
    var desc = document.createElement('p'); desc.className = 'lot-desc'; desc.textContent = lot.desc; main.appendChild(desc);

    var meta = document.createElement('div');
    meta.className = 'lot-meta';
    meta.innerHTML =
      '<span>Opening price: <b class="tabular">' + fmtMoney(lot.startingPrice) + '</b></span>' +
      '<span>Min. increment: <b class="tabular">' + fmtMoney(lot.increment) + '</b></span>' +
      '<span>Bids placed: <b>' + lot.bids.length + '</b></span>';
    main.appendChild(meta);

    if(lot.bids.length){
      var details = document.createElement('details');
      details.className = 'bid-history';
      details.dataset.lotId = lot.id;
      var summary = document.createElement('summary'); summary.textContent = 'Show bid history';
      details.appendChild(summary);
      var ul = document.createElement('ul');
      lot.bids.slice().reverse().forEach(function(b){
        var li = document.createElement('li');
        var left = document.createElement('span'); left.innerHTML = '<b>' + escapeHtml(b.bidder) + '</b>';
        var right = document.createElement('span'); right.className='tabular'; right.textContent = fmtMoney(b.amount);
        li.appendChild(left); li.appendChild(right); ul.appendChild(li);
      });
      details.appendChild(ul);
      main.appendChild(details);
    }

    if(mode === 'admin'){
      var actions = document.createElement('div');
      actions.className = 'admin-actions';
      var delBtn = document.createElement('button');
      delBtn.className = 'delete-lot-btn';
      delBtn.type = 'button';
      delBtn.textContent = 'Delete lot';
      delBtn.addEventListener('click', function(){
        var msg = timeLeft(lot) > 0
          ? 'Cancel the auction for "' + lot.name + '"? It will be removed from the floor, any bids are void, and it will show as Cancelled in Reports.'
          : 'Remove "' + lot.name + '" from the lots list? Its result stays in Reports.';
        if(!confirm(msg)) return;
        delBtn.disabled = true;
        api('delete_lot', { lotId: lot.id }).then(refreshLots).catch(function(err){
          if(handleSessionExpired(err)) return;
          delBtn.disabled = false;
          alert(err.message);
        });
      });
      actions.appendChild(delBtn);
      main.appendChild(actions);
    }

    wrap.appendChild(main);

    var side = document.createElement('div');
    side.className = 'lot-side';

    var pill = document.createElement('span');
    if(closed){ pill.className='status-pill status-closed'; pill.textContent='Closed'; }
    else if(urgent){ pill.className='status-pill status-ending'; pill.textContent='Ending soon'; }
    else { pill.className='status-pill status-live'; pill.textContent='Live'; }
    side.appendChild(pill);

    var bidLabel = document.createElement('div');
    bidLabel.className = 'current-bid-label';
    bidLabel.textContent = lot.bids.length ? 'Current bid' : 'Opening price';
    side.appendChild(bidLabel);

    var bidAmount = document.createElement('div');
    bidAmount.className = 'current-bid tabular';
    bidAmount.textContent = fmtMoney(currentBid(lot));
    side.appendChild(bidAmount);

    var bidderLine = document.createElement('div');
    bidderLine.className = 'current-bidder';
    bidderLine.textContent = lot.bids.length
      ? (closed ? 'Won by ' : 'Leading: ') + lot.bids[lot.bids.length-1].bidder
      : 'No bids yet';
    side.appendChild(bidderLine);

    if(closed){
      var banner = document.createElement('div');
      banner.className = 'won-banner';
      if(lot.bids.length){
        banner.innerHTML = '<b>Auction closed</b>' + escapeHtml(lot.bids[lot.bids.length-1].bidder) + ' wins this lot at ' + fmtMoney(currentBid(lot)) + '.';
      } else {
        banner.innerHTML = '<b>Auction closed</b>No bids were placed — lot unsold.';
      }
      side.appendChild(banner);
    } else {
      var countdown = document.createElement('div');
      countdown.className = 'countdown' + (urgent?' urgent':'');
      countdown.innerHTML = 'Closes in <span class="time tabular" data-countdown="'+lot.id+'">' + fmtDuration(remaining) + '</span>';
      side.appendChild(countdown);

      if(mode === 'bidder'){
        var form = document.createElement('div'); form.className = 'bid-form';
        var input = document.createElement('input');
        input.type = 'number';
        input.placeholder = fmtMoney(minNextBid(lot)) + ' or more';
        input.min = minNextBid(lot);
        input.step = 1;
        input.dataset.lotId = lot.id;
        var btn = document.createElement('button'); btn.type='button'; btn.textContent='Place bid';
        form.appendChild(input); form.appendChild(btn); side.appendChild(form);

        var err = document.createElement('div'); err.className='bid-error';
        err.textContent = bidErrors[lot.id] || '';
        side.appendChild(err);

        btn.addEventListener('click', function(){ placeBid(lot, input, btn, err); });
        input.addEventListener('keydown', function(e){ if(e.key==='Enter') placeBid(lot, input, btn, err); });
      }
    }

    wrap.appendChild(side);
    return wrap;
  }

  function renderLotsInto(containerId, mode){
    var container = document.getElementById(containerId);

    // Keep what the user typed, which input had focus, and which bid histories were open.
    var typed = {}, focusedLot = null, openHistories = {};
    container.querySelectorAll('.bid-form input').forEach(function(inp){
      typed[inp.dataset.lotId] = inp.value;
      if(inp === document.activeElement) focusedLot = inp.dataset.lotId;
    });
    container.querySelectorAll('details.bid-history[open]').forEach(function(d){ openHistories[d.dataset.lotId] = true; });

    var list = sortedLots();
    container.innerHTML = '';
    if(list.length === 0){
      var empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = mode === 'admin' ? 'No lots yet. List one to get started.' : 'No lots are open right now. Check back soon.';
      container.appendChild(empty);
      return;
    }
    list.forEach(function(lot){ container.appendChild(renderLotCard(lot, mode)); });

    container.querySelectorAll('.bid-form input').forEach(function(inp){
      var id = inp.dataset.lotId;
      if(typed[id]) inp.value = typed[id];
      if(id === focusedLot) inp.focus();
    });
    container.querySelectorAll('details.bid-history').forEach(function(d){
      if(openHistories[d.dataset.lotId]) d.open = true;
    });
  }

  function renderCurrent(){
    if(!session) return;
    if(session.role === 'admin') renderLotsInto('adminLotsContainer','admin');
    else renderLotsInto('bidderLotsContainer','bidder');
  }

  function placeBid(lot, input, btn, errEl){
    bidErrors[lot.id] = '';
    errEl.textContent = '';
    if(timeLeft(lot) <= 0){ bidErrors[lot.id] = 'This lot has already closed.'; renderCurrent(); return; }

    var amount = parseFloat(input.value);
    var minReq = minNextBid(lot);

    if(isNaN(amount)){ errEl.textContent = 'Enter a bid amount.'; return; }
    if(amount < minReq){ errEl.textContent = 'Bid must be at least ' + fmtMoney(minReq) + '.'; return; }
    if(lot.bids.length && lot.bids[lot.bids.length-1].bidder === session.username){
      errEl.textContent = 'You already hold the highest bid on this lot.'; return;
    }

    // The server re-checks every rule under a lock, so simultaneous bids can't overwrite each other.
    btn.disabled = true;
    api('bid', { lotId: lot.id, amount: amount }).then(function(){
      input.value = '';
      btn.disabled = false;
      return refreshLots();
    }).catch(function(err){
      if(handleSessionExpired(err)) return;
      bidErrors[lot.id] = err.message;
      errEl.textContent = err.message;
      btn.disabled = false;
      refreshLots();
    });
  }

  // ================= Polling =================
  var pollInterval = null;
  var tickInterval = null;
  var lastClosedState = {};

  function setSyncBanner(failed){
    document.getElementById('bidderSyncBanner').classList.toggle('hidden', !failed);
    document.getElementById('adminSyncBanner').classList.toggle('hidden', !failed);
  }

  function refreshLots(){
    if(!session) return Promise.resolve();
    return api('lots').then(function(body){
      setSyncBanner(false);
      var sig = JSON.stringify(body.lots);
      if(sig === lotsSignature) return;
      lotsSignature = sig;
      lotsCache = body.lots;
      renderCurrent();
    }).catch(function(err){
      if(handleSessionExpired(err)) return;
      setSyncBanner(true);
    });
  }

  function startSubscription(){
    stopAllSubscriptions();
    setSyncBanner(false);
    renderCurrent();
    refreshLots();
    pollInterval = setInterval(refreshLots, POLL_MS);
    startTicking();
  }
  function startBidderSubscription(){ startSubscription(); }
  function startAdminSubscription(){ startSubscription(); }

  function stopAllSubscriptions(){
    if(pollInterval){ clearInterval(pollInterval); pollInterval = null; }
    if(tickInterval){ clearInterval(tickInterval); tickInterval = null; }
    lotsCache = [];
    lotsSignature = '';
    bidErrors = {};
    lastClosedState = {};
  }

  function startTicking(){
    if(tickInterval) clearInterval(tickInterval);
    tickInterval = setInterval(function(){
      var needsFullRender = false, justClosed = false;
      lotsCache.forEach(function(lot){
        var remaining = timeLeft(lot);
        var state = remaining <= 0 ? 'closed' : (remaining < 30000 ? 'urgent' : 'live');
        if(lastClosedState[lot.id] !== state){
          if(state === 'closed' && lastClosedState[lot.id]) justClosed = true;
          lastClosedState[lot.id] = state;
          needsFullRender = true;
        }
        if(state !== 'closed'){
          var el = document.querySelector('[data-countdown="'+lot.id+'"]');
          if(el) el.textContent = fmtDuration(remaining);
        }
      });
      if(needsFullRender) renderCurrent();
      // Fetch the final bid list as soon as a lot closes.
      if(justClosed){
        refreshLots();
        if(activeAdminTab === 'reports') loadReport();
      }
    }, 1000);
  }

  // ================= Admin: new lot form =================
  var newLotForm = document.getElementById('newLotForm');
  var newLotError = document.getElementById('newLotError');
  document.getElementById('toggleNewLot').addEventListener('click', function(){ newLotForm.classList.toggle('open'); newLotError.textContent = ''; });
  document.getElementById('cancelNewLot').addEventListener('click', function(){ newLotForm.classList.remove('open'); newLotError.textContent = ''; });
  document.getElementById('submitNewLot').addEventListener('click', function(){
    var btn = this;
    var name = document.getElementById('nlName').value.trim();
    var desc = document.getElementById('nlDesc').value.trim();
    var start = parseFloat(document.getElementById('nlStart').value);
    var inc = parseFloat(document.getElementById('nlIncrement').value);
    var dur = parseInt(document.getElementById('nlDuration').value, 10);

    newLotError.textContent = '';
    if(!name){ newLotError.textContent = 'Give the lot a product name.'; return; }
    if(isNaN(start) || start < 0){ newLotError.textContent = 'Enter a valid starting price.'; return; }
    if(isNaN(inc) || inc <= 0){ newLotError.textContent = 'Enter a valid minimum increment.'; return; }

    btn.disabled = true;
    api('create_lot', { name: name, desc: desc, startingPrice: start, increment: inc, duration: dur }).then(function(){
      document.getElementById('nlName').value='';
      document.getElementById('nlDesc').value='';
      document.getElementById('nlStart').value='100';
      document.getElementById('nlIncrement').value='5';
      newLotForm.classList.remove('open');
      return refreshLots();
    }).catch(function(err){
      if(handleSessionExpired(err)) return;
      newLotError.textContent = err.message;
    }).then(function(){ btn.disabled = false; });
  });

  // ================= Admin: tabs =================
  var activeAdminTab = 'manage';
  document.querySelectorAll('[data-admin-tab]').forEach(function(tab){
    tab.addEventListener('click', function(){ setAdminTab(tab.dataset.adminTab); });
  });
  function setAdminTab(name){
    activeAdminTab = name;
    document.querySelectorAll('[data-admin-tab]').forEach(function(tab){
      var on = tab.dataset.adminTab === name;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.getElementById('adminManage').classList.toggle('hidden', name !== 'manage');
    document.getElementById('adminReports').classList.toggle('hidden', name !== 'reports');
    if(name === 'reports') loadReport();
  }

  // ================= Admin: reports =================
  var rpFrom = document.getElementById('rpFrom');
  var rpTo = document.getElementById('rpTo');
  var rpStatus = document.getElementById('rpStatus');
  var rpSearch = document.getElementById('rpSearch');
  var reportRequest = 0;
  var searchTimer = null;

  var STATUS_LABELS = { sold: 'Sold', unsold: 'Unsold', cancelled: 'Cancelled' };

  // Date inputs are in the admin's local time zone; the API filters on exact timestamps.
  function reportQuery(){
    var q = {};
    if(rpFrom.value) q.from = new Date(rpFrom.value + 'T00:00:00').getTime();
    if(rpTo.value) q.to = new Date(rpTo.value + 'T23:59:59.999').getTime();
    if(rpStatus.value) q.status = rpStatus.value;
    if(rpSearch.value.trim()) q.q = rpSearch.value.trim();
    return q;
  }

  function fmtDateTime(ms){
    return new Date(ms).toLocaleString(undefined, { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
  }

  function loadReport(){
    var requestId = ++reportRequest;
    return api('report', null, reportQuery()).then(function(body){
      if(requestId !== reportRequest) return; // a newer filter change already went out
      renderReport(body);
    }).catch(function(err){
      if(handleSessionExpired(err)) return;
      document.getElementById('rpNote').textContent = err.message;
    });
  }

  function renderReport(body){
    var s = body.summary;
    var stats = [
      ['Lots finished', s.lots],
      ['Sold', s.sold],
      ['Unsold', s.unsold],
      ['Cancelled', s.cancelled],
      ['Total sold value', fmtMoney(s.soldValue)],
      ['Bids placed', s.bids]
    ];
    document.getElementById('rpStats').innerHTML = stats.map(function(st){
      return '<div class="stat"><div class="stat-label">' + st[0] + '</div><div class="stat-value tabular">' + escapeHtml(st[1]) + '</div></div>';
    }).join('');

    var container = document.getElementById('rpTableContainer');
    var filtered = Object.keys(reportQuery()).length > 0;
    if(!body.rows.length){
      container.innerHTML = '<div class="empty-state">' + (filtered
        ? 'No finished lots match these filters.'
        : 'No auctions have finished yet. Results appear here once a lot closes.') + '</div>';
    } else {
      var html = '<div class="table-wrap"><table class="report-table"><thead><tr>' +
        '<th>Lot</th><th>Product</th><th>Finished</th><th>Outcome</th>' +
        '<th class="num">Opening</th><th class="num">Winning bid</th><th>Winner</th><th class="num">Bids</th>' +
        '</tr></thead><tbody>';
      body.rows.forEach(function(r){
        html += '<tr>' +
          '<td class="muted tabular">' + String(r.id).padStart(3, '0') + '</td>' +
          '<td class="product">' + escapeHtml(r.name) + '</td>' +
          '<td class="muted" style="white-space:nowrap">' + escapeHtml(fmtDateTime(r.finishedAt)) + '</td>' +
          '<td><span class="status-pill status-' + (r.status === 'sold' ? 'live' : r.status) + '">' + STATUS_LABELS[r.status] + '</span></td>' +
          '<td class="num">' + fmtMoney(r.startingPrice) + '</td>' +
          '<td class="num">' + (r.winningBid === null ? '<span class="muted">—</span>' : '<b>' + fmtMoney(r.winningBid) + '</b>') + '</td>' +
          '<td>' + (r.winner ? escapeHtml(r.winner) : '<span class="muted">—</span>') + '</td>' +
          '<td class="num">' + r.bidCount + (r.bidCount ? ' <span class="muted">(' + r.bidderCount + ' bidder' + (r.bidderCount === 1 ? '' : 's') + ')</span>' : '') + '</td>' +
          '</tr>';
      });
      html += '</tbody></table></div>';
      container.innerHTML = html;
    }

    document.getElementById('rpNote').textContent = body.truncated
      ? 'Showing the latest ' + body.rows.length + ' results. Totals cover all matching lots; export the CSV for the full list.'
      : '';
  }

  [rpFrom, rpTo, rpStatus].forEach(function(el){ el.addEventListener('change', loadReport); });
  rpSearch.addEventListener('input', function(){
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadReport, 300);
  });
  document.getElementById('rpReset').addEventListener('click', function(){
    rpFrom.value = ''; rpTo.value = ''; rpStatus.value = ''; rpSearch.value = '';
    loadReport();
  });
  document.getElementById('rpExport').addEventListener('click', function(){
    window.location.href = apiUrl('report_csv', reportQuery());
  });

  // ================= Boot =================
  setAuthTab('login');
  routeToSession();
})();
</script>

</body>
</html>
