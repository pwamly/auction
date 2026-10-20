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
  function fmtMoney(n){
    return 'TZS ' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

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

  // ---------- Confirmation on destructive forms ----------
  document.querySelectorAll('form[data-confirm]').forEach(function(f){
    f.addEventListener('submit', function(e){ if(!window.confirm(f.dataset.confirm)) e.preventDefault(); });
  });

  // ---------- Live bid panel ----------
  var panel = document.querySelector('[data-bid-panel]');
  if(!panel) return;
  var auctionId = panel.dataset.auctionId;
  var initialStatus = panel.dataset.phase;
  var $ = function(sel){ return panel.querySelector(sel); };
  var amountInput = $('[name="amount"]');
  var termsBox = $('[name="accept_terms"]');
  var submitBtn = $('[data-bid-submit]');
  var msg = $('[data-bid-msg]');
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
    setText('[data-leader]', s.leader ? s.leader + (s.youLead ? ' (you)' : '') : (s.bids ? '—' : 'No bids yet'));
    setText('[data-min]', fmtMoney(s.minNextBid));
    if(amountInput){ amountInput.placeholder = fmtMoney(s.minNextBid) + ' or more'; amountInput.min = s.minNextBid; }
    var reserve = $('[data-reserve]');
    if(reserve && s.reserveMet !== null){
      reserve.textContent = s.reserveMet ? 'Reserve price met' : 'Reserve price not yet met';
      reserve.className = 'notice ' + (s.reserveMet ? 'good' : '');
    }
    var cd = $('[data-countdown]');
    if(cd) cd.dataset.closes = s.closesAt;
    var lead = $('[data-you-lead]');
    if(lead) lead.hidden = !s.youLead;
    if(termsBox && s.termsAccepted){ var wrap = $('[data-terms-wrap]'); if(wrap) wrap.hidden = true; }
  }

  function poll(){
    if(document.hidden || busy) return;
    api('auction_state').then(applyState).catch(function(){});
  }
  setInterval(poll, 3000);

  function submitBid(){
    if(busy) return;
    msg.textContent = ''; msg.className = 'bid-msg';
    var amount = (amountInput.value || '').replace(/,/g, '').trim();
    if(!amount){ msg.textContent = 'Enter your bid amount.'; msg.className = 'bid-msg error'; return; }
    var termsWrap = $('[data-terms-wrap]');
    var needTerms = termsBox && termsWrap && !termsWrap.hidden;
    if(needTerms && !termsBox.checked){ msg.textContent = 'Please accept the terms and conditions first.'; msg.className = 'bid-msg error'; return; }
    busy = true; submitBtn.disabled = true;
    api('bid', { amount: amount, acceptTerms: needTerms ? termsBox.checked : false }).then(function(body){
      amountInput.value = '';
      msg.textContent = body.extendedTo ? 'Bid placed. The auction was extended because of your late bid.' : 'Bid placed — you are the highest bidder.';
      msg.className = 'bid-msg ok';
      applyState(body.state);
    }).catch(function(err){
      msg.textContent = err.message; msg.className = 'bid-msg error';
      if(err.body && err.body.state) applyState(err.body.state);
    }).then(function(){ busy = false; submitBtn.disabled = false; });
  }
  if(submitBtn){
    submitBtn.addEventListener('click', submitBid);
    amountInput.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); submitBid(); } });
  }
})();
