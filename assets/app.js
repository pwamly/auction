(function(){
  'use strict';

  var base = (document.querySelector('link[rel="stylesheet"][href*="assets/app.css"]').getAttribute('href') || '').split('assets/app.css')[0];
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var serverNowMeta = document.querySelector('meta[name="server-now"]');
  // Countdowns follow the server clock, corrected for this computer's clock difference.
  var clockOffset = serverNowMeta ? Number(serverNowMeta.content) - Date.now() : 0;
  function now(){ return Date.now() + clockOffset; }

  function fmtLeft(ms){
    if(ms <= 0) return '0s';
    var s = Math.floor(ms/1000), d = Math.floor(s/86400), h = Math.floor(s%86400/3600), m = Math.floor(s%3600/60), sec = s%60;
    if(d > 0) return d + 'd ' + h + 'h ' + m + 'm';
    if(h > 0) return h + 'h ' + m + 'm ' + String(sec).padStart(2,'0') + 's';
    return m + 'm ' + String(sec).padStart(2,'0') + 's';
  }
  function fmtNumber(n){ return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 }); }
  function fmtMoney(n){ return 'TZS ' + fmtNumber(n); }

  // ---------- Sidebar drawer (small screens) ----------
  function setSidebar(open){ document.body.classList.toggle('sidebar-open', open); }
  document.querySelectorAll('[data-sidebar-open]').forEach(function(b){ b.addEventListener('click', function(){ setSidebar(true); }); });
  document.querySelectorAll('[data-sidebar-close]').forEach(function(b){ b.addEventListener('click', function(){ setSidebar(false); }); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape') setSidebar(false); });

  // ---------- Landing page top menu (phones) ----------
  var frontBtn = document.querySelector('[data-front-menu]');
  if(frontBtn){
    frontBtn.addEventListener('click', function(){
      var open = document.getElementById('frontLinks').classList.toggle('open');
      frontBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // ---------- Pop-up forms: <button data-open="dialogId"> opens <dialog id="dialogId"> ----------
  function openModal(id){
    var d = document.getElementById(id);
    if(!d || typeof d.showModal !== 'function') return null;
    d.showModal();
    var first = d.querySelector('.modal-body input:not([type=hidden]), .modal-body select, .modal-body textarea, .modal-body button');
    if(first) first.focus();
    return d;
  }
  document.addEventListener('click', function(e){
    var opener = e.target.closest('[data-open]');
    if(opener){ e.preventDefault(); openModal(opener.dataset.open); return; }
    var closer = e.target.closest('[data-close]');
    if(closer){ var dlg = closer.closest('dialog'); if(dlg) dlg.close(); return; }
    // Clicking the dark backdrop closes the dialog.
    if(e.target.tagName === 'DIALOG' && e.target.classList.contains('modal')) e.target.close();
  });
  // Re-open a dialog after a failed server-side validation (?modal=id, or <dialog data-autoopen>).
  var reopen = new URLSearchParams(location.search).get('modal');
  if(reopen) openModal(reopen);
  document.querySelectorAll('dialog[data-autoopen]').forEach(function(d){ openModal(d.id); });

  // ---------- Dismissible messages ----------
  document.querySelectorAll('[data-dismiss]').forEach(function(btn){
    btn.addEventListener('click', function(){ btn.closest('.flash').remove(); });
  });

  // ---------- Countdowns: <span data-countdown data-closes="ms" data-opens="ms"> ----------
  function tickCountdowns(){
    document.querySelectorAll('[data-countdown]').forEach(function(el){
      var opens = Number(el.dataset.opens || 0), closes = Number(el.dataset.closes || 0), t = now();
      var text, urgent = false;
      if(opens && t < opens){ text = 'Opens in <b>' + fmtLeft(opens - t) + '</b>'; }
      else if(closes && t < closes){ text = 'Closes in <b>' + fmtLeft(closes - t) + '</b>'; urgent = closes - t < 30*60*1000; }
      else { text = '<b>Bidding closed</b>'; }
      el.innerHTML = text;
      el.classList.toggle('urgent', urgent);
      var box = el.closest('.clock');
      if(box) box.classList.toggle('urgent-box', urgent);
    });
  }
  tickCountdowns();
  setInterval(tickCountdowns, 1000);

  // ---------- Photo gallery ----------
  document.querySelectorAll('[data-gallery]').forEach(function(g){
    var main = g.querySelector('.gallery-main img');
    g.querySelectorAll('.gallery-thumbs button').forEach(function(btn){
      btn.addEventListener('click', function(){
        main.src = btn.dataset.src;
        main.alt = btn.dataset.alt || '';
        g.querySelectorAll('.gallery-thumbs button').forEach(function(b){ b.classList.toggle('active', b === btn); });
      });
    });
  });

  // ---------- Conditional fields: data-show-if="radioName:value" ----------
  var conditional = document.querySelectorAll('[data-show-if]');
  function updateConditional(){
    conditional.forEach(function(el){
      var parts = el.dataset.showIf.split(':');
      var checked = document.querySelector('input[name="' + parts[0] + '"]:checked');
      el.hidden = !checked || checked.value !== parts[1];
    });
  }
  if(conditional.length){
    document.addEventListener('change', function(e){ if(e.target.type === 'radio') updateConditional(); });
    updateConditional();
  }

  // ---------- Money inputs: thousands separators while typing ----------
  function attachMoneyFormat(input){
    function format(){
      var raw = input.value.replace(/[^\d.]/g, '');
      var parts = raw.split('.');
      var whole = parts[0].replace(/^0+(?=\d)/, '');
      var formatted = whole ? Number(whole).toLocaleString('en-US') : '';
      if(parts.length > 1) formatted += '.' + parts[1].slice(0, 2);
      input.value = formatted;
    }
    input.addEventListener('input', format);
    if(input.value) format(); // pre-filled values (edit forms)
  }
  document.querySelectorAll('[data-money]').forEach(attachMoneyFormat);

  // ---------- Live bid panel ----------
  var panel = document.querySelector('[data-bid-panel]');
  if(!panel) return;
  var auctionId = panel.dataset.auctionId;
  var initialStatus = panel.dataset.phase;
  var $ = function(sel){ return panel.querySelector(sel); };
  var amountInput = $('[name="amount"]');
  var submitBtn = $('[data-bid-submit]');
  var msg = $('[data-bid-msg]');
  var dialog = document.getElementById('bidConfirm');
  var termsWrap = dialog ? dialog.querySelector('[data-terms-wrap]') : null;
  var termsBox = dialog ? dialog.querySelector('[name="accept_terms"]') : null;
  var minBid = amountInput ? Number(amountInput.dataset.minValue) : 0;
  var busy = false;

  function api(action, payload){
    var opts = { credentials:'same-origin', headers:{ 'Accept':'application/json' } };
    if(payload){
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = csrf;
      opts.body = JSON.stringify(payload);
    }
    return fetch(base + 'api?action=' + action + '&id=' + encodeURIComponent(auctionId), opts).then(function(res){
      return res.json().catch(function(){ return {}; }).then(function(body){
        if(typeof body.serverNow === 'number') clockOffset = body.serverNow - Date.now();
        if(!res.ok){ var e = new Error(body.error || 'Something went wrong. Try again.'); e.body = body; throw e; }
        return body;
      });
    });
  }

  function setText(sel, text){ var el = $(sel); if(el) el.textContent = text; }

  function applyState(s){
    // A status change (opened, closed, suspended…) changes the whole page: reload it.
    if(s.phase !== initialStatus){ window.location.reload(); return; }
    setText('[data-current-label]', s.bids ? 'Current highest bid' : 'Starting price');
    setText('[data-current]', fmtMoney(s.currentBid));
    setText('[data-bids]', s.bids);
    setText('[data-bidders]', s.bidders);
    setText('[data-leader]', s.leader ? s.leader + (s.youLead ? ' (you)' : '') : (s.bids ? 'log in to see' : 'no bids yet'));
    setText('[data-min]', fmtMoney(s.minNextBid));
    if(amountInput && s.minNextBid !== minBid){
      minBid = s.minNextBid;
      amountInput.placeholder = fmtNumber(minBid);
    }
    var reserve = $('[data-reserve]');
    if(reserve && s.reserveMet !== null){
      reserve.textContent = s.reserveMet ? 'Reserve price met' : 'Reserve price not yet met';
      reserve.className = 'notice ' + (s.reserveMet ? 'good' : '');
    }
    var cd = $('[data-countdown]');
    if(cd) cd.dataset.closes = s.closesAt;
    var lead = $('[data-you-lead]');
    if(lead) lead.hidden = !s.youLead;
    if(termsWrap && s.termsAccepted) termsWrap.hidden = true;
  }

  function poll(){
    if(document.hidden || busy) return;
    api('auction_state').then(applyState).catch(function(){});
  }
  setInterval(poll, 3000);

  if(!submitBtn || !dialog) return;
  attachMoneyFormat(amountInput);

  function showError(text){ msg.textContent = text; msg.className = 'bid-msg error'; }
  function amountValue(){ return Number((amountInput.value || '').replace(/,/g, '')); }
  var dialogError = dialog.querySelector('[data-dialog-error]');

  // Step 1: check the amount, then confirm in a pop-up (with the terms on a first bid).
  function review(){
    msg.textContent = ''; msg.className = 'bid-msg';
    var amount = amountValue();
    if(!amount){ showError('Enter your bid amount.'); amountInput.focus(); return; }
    if(amount < minBid){ showError('The minimum bid is ' + fmtMoney(minBid) + '.'); amountInput.focus(); return; }
    dialog.querySelector('[data-confirm-amount]').textContent = fmtMoney(amount);
    dialogError.textContent = '';
    dialog.showModal();
    dialog.querySelector('[data-bid-confirm]').focus();
  }

  // Step 2: send it.
  function send(){
    if(busy) return;
    var needTerms = termsWrap && !termsWrap.hidden;
    if(needTerms && !termsBox.checked){ dialogError.textContent = 'Please accept the terms and conditions to continue.'; return; }
    busy = true;
    var confirmBtn = dialog.querySelector('[data-bid-confirm]');
    confirmBtn.disabled = true;
    api('bid', { amount: String(amountValue()), acceptTerms: needTerms ? termsBox.checked : false }).then(function(body){
      dialog.close();
      amountInput.value = '';
      msg.textContent = body.extendedTo ? '✓ Bid placed. Closing was extended because of your late bid.' : '✓ Bid placed — you are the highest bidder.';
      msg.className = 'bid-msg ok';
      applyState(body.state);
    }).catch(function(err){
      dialog.close();
      showError(err.message);
      if(err.body && err.body.state) applyState(err.body.state);
    }).then(function(){ busy = false; confirmBtn.disabled = false; });
  }

  submitBtn.addEventListener('click', review);
  dialog.querySelector('[data-bid-confirm]').addEventListener('click', send);
  amountInput.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); review(); } });
})();
