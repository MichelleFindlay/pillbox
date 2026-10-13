// Pillbox progressive enhancements. Every page still works without JavaScript.
(function () {
  'use strict';

  // Remember the user's theme so pages served without it (the offline page) can use it.
  (function () {
    var root = document.documentElement;
    try {
      if (root.hasAttribute('data-palette')) {
        window.localStorage.setItem('pillbox-palette', root.getAttribute('data-palette'));
        window.localStorage.setItem('pillbox-mode', root.getAttribute('data-theme') || 'auto');
      } else {
        root.setAttribute('data-palette', window.localStorage.getItem('pillbox-palette') || 'olive');
        var mode = window.localStorage.getItem('pillbox-mode');
        if (mode === 'light' || mode === 'dark') { root.setAttribute('data-theme', mode); }
      }
    } catch (e) { /* storage unavailable: defaults are fine */ }
  })();

  // Preview the chosen theme / mode straight away on the Account page (saved with the button).
  document.addEventListener('change', function (e) {
    var input = e.target;
    var root = document.documentElement;
    if (input.name === 'theme' && input.closest('#appearance')) {
      root.setAttribute('data-palette', input.value);
    }
    if (input.name === 'color_mode' && input.closest('#appearance')) {
      if (input.value === 'auto') { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', input.value); }
    }
  });

  // Ask before destructive form submissions: <form data-confirm="Are you sure?">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    }
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-print]')) {
      window.print();
    }
    // Copy buttons: <button data-copy="#input-id">
    var copy = e.target.closest('[data-copy]');
    if (copy) {
      var field = document.querySelector(copy.getAttribute('data-copy'));
      if (field) {
        field.select();
        var done = function () { var label = copy.textContent; copy.textContent = 'Copied ✓'; setTimeout(function () { copy.textContent = label; }, 2000); };
        if (navigator.clipboard) { navigator.clipboard.writeText(field.value).then(done, function () { document.execCommand('copy'); done(); }); }
        else { document.execCommand('copy'); done(); }
      }
    }
    var remove = e.target.closest('.remove-row');
    if (remove) {
      var rows = document.querySelectorAll('#schedule-rows .schedule-row');
      var row = remove.closest('.schedule-row');
      if (rows.length > 1) {
        row.remove();
      } else {
        // Keep one row so the form always has a place to enter a dose.
        row.querySelectorAll('input[type=time], input[type=number], input[type=date]').forEach(function (i) { i.value = ''; });
      }
    }
  });

  // "Add another dose time" clones the <template>, giving its fields a fresh index.
  var addRow = document.getElementById('add-row');
  if (addRow) {
    addRow.addEventListener('click', function () {
      var container = document.getElementById('schedule-rows');
      var template = document.getElementById('schedule-template');
      var index = parseInt(container.getAttribute('data-next-index'), 10) || 0;
      container.setAttribute('data-next-index', index + 1);
      var html = template.innerHTML.replace(/__i__/g, 'n' + index);
      container.insertAdjacentHTML('beforeend', html);
      var added = container.lastElementChild;
      var time = added && added.querySelector('input[type=time]');
      if (time) { time.focus(); }
    });
  }

  // Show/hide sections depending on a checkbox or radio:
  // data-show-when="<input id> [<input id>...]" (shown if any is checked) / data-hide-when="<input id>"
  function syncToggles() {
    document.querySelectorAll('[data-show-when], [data-hide-when]').forEach(function (el) {
      var showIds = (el.getAttribute('data-show-when') || '').split(' ').filter(Boolean);
      var hideId = el.getAttribute('data-hide-when');
      var inputs = (showIds.length ? showIds : [hideId]).map(function (id) { return document.getElementById(id); }).filter(Boolean);
      if (!inputs.length) { return; }
      var anyChecked = inputs.some(function (i) { return i.checked; });
      el.hidden = showIds.length ? !anyChecked : anyChecked;
    });
  }
  document.addEventListener('change', syncToggles);
  syncToggles();

  // Breathing exercise: a circle that grows and shrinks with each phase.
  document.querySelectorAll('[data-breathe]').forEach(function (box) {
    var circle = box.querySelector('.breathe-circle'), phaseEl = box.querySelector('.breathe-phase'), countEl = box.querySelector('.breathe-count');
    var start = box.querySelector('[data-breathe-start]'), stop = box.querySelector('[data-breathe-stop]');
    var names = ['Breathe in', 'Hold', 'Breathe out', 'Hold'], timer = null;
    function end() {
      clearInterval(timer); timer = null;
      phaseEl.textContent = 'Well done'; countEl.textContent = '';
      circle.className = 'breathe-circle'; start.hidden = false; stop.hidden = true;
    }
    start.addEventListener('click', function () {
      var checked = box.querySelector('input[name=pattern]:checked');
      var steps = (checked ? checked.value : box.getAttribute('data-breathe')).split(',').map(Number);
      var phase = -1, left = 0, rounds = 0;
      start.hidden = true; stop.hidden = false;
      function next() {
        do { phase = (phase + 1) % 4; if (phase === 0) { rounds++; } } while (!steps[phase]);
        if (rounds > 8) { end(); return; }
        left = steps[phase];
        phaseEl.textContent = names[phase];
        circle.className = 'breathe-circle ' + ['grow', 'full', 'shrink', 'small'][phase];
        circle.style.transitionDuration = steps[phase] + 's';
        countEl.textContent = left;
      }
      next();
      timer = setInterval(function () { left--; if (left <= 0) { next(); } else { countEl.textContent = left; } }, 1000);
    });
    stop.addEventListener('click', end);
  });

  // 5-4-3-2-1 grounding: one step at a time.
  document.querySelectorAll('[data-ground]').forEach(function (list) {
    var items = list.querySelectorAll('li'), controls = list.parentElement.querySelector('[data-ground-controls]'), at = 0;
    var prev = controls.querySelector('[data-ground-prev]'), next = controls.querySelector('[data-ground-next]');
    function show() {
      items.forEach(function (li, i) { li.classList.toggle('current', i === at); li.classList.toggle('done', i < at); });
      prev.disabled = at === 0;
      next.textContent = at === items.length - 1 ? 'Start again' : 'Next';
    }
    controls.hidden = false;
    list.classList.add('stepping');
    prev.addEventListener('click', function () { if (at > 0) { at--; show(); } });
    next.addEventListener('click', function () { at = at === items.length - 1 ? 0 : at + 1; show(); });
    show();
  });

  // Checkboxes that save as soon as they're changed: <input type="checkbox" data-autosubmit>
  document.querySelectorAll('[data-autosubmit]').forEach(function (input) {
    input.addEventListener('change', function () { input.form.submit(); });
  });

  // Fingerprint / face unlock for Mind (WebAuthn): set up a device, or unlock with one.
  var b64 = {
    enc: function (buf) { return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); },
    dec: function (s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) { s += '='; } return Uint8Array.from(atob(s), function (c) { return c.charCodeAt(0); }); }
  };
  function webauthnCall(body) {
    return fetch(document.body.getAttribute('data-base') + '/webauthn.php', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { if (j.error) { throw new Error(j.error); } return j; }); });
  }
  function webauthnButtons(selector, run) {
    var buttons = document.querySelectorAll(selector);
    if (!buttons.length || !window.PublicKeyCredential || !PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) { return; }
    PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().then(function (ok) {
      if (!ok) { return; }
      buttons.forEach(function (btn) {
        btn.hidden = false;
        var status = btn.parentElement.querySelector('[data-webauthn-status]');
        btn.addEventListener('click', function () {
          btn.disabled = true; if (status) { status.textContent = ''; }
          run(btn).catch(function (e) {
            if (status) { status.textContent = e.name === 'NotAllowedError' ? 'Cancelled.' : e.message; }
          }).then(function () { btn.disabled = false; });
        });
      });
    });
  }
  webauthnButtons('[data-webauthn-register]', function (btn) {
    var csrf = btn.getAttribute('data-csrf');
    return webauthnCall({ action: 'register_options', csrf: csrf }).then(function (o) {
      o.challenge = b64.dec(o.challenge); o.user.id = b64.dec(o.user.id);
      o.excludeCredentials = o.excludeCredentials.map(function (c) { return { type: c.type, id: b64.dec(c.id) }; });
      return navigator.credentials.create({ publicKey: o });
    }).then(function (cred) {
      return webauthnCall({ action: 'register_finish', csrf: csrf, label: btn.getAttribute('data-label') || 'This device',
        clientDataJSON: b64.enc(cred.response.clientDataJSON), attestationObject: b64.enc(cred.response.attestationObject) });
    }).then(function () { window.location.reload(); });
  });
  webauthnButtons('[data-webauthn-unlock]', function (btn) {
    var csrf = btn.getAttribute('data-csrf');
    return webauthnCall({ action: 'unlock_options', csrf: csrf }).then(function (o) {
      o.challenge = b64.dec(o.challenge);
      o.allowCredentials = o.allowCredentials.map(function (c) { return { type: c.type, id: b64.dec(c.id) }; });
      return navigator.credentials.get({ publicKey: o });
    }).then(function (a) {
      return webauthnCall({ action: 'unlock_finish', csrf: csrf, return: btn.getAttribute('data-return'), credential: {
        id: a.id, clientDataJSON: b64.enc(a.response.clientDataJSON), authenticatorData: b64.enc(a.response.authenticatorData), signature: b64.enc(a.response.signature) } });
    }).then(function (r) { window.location.href = r.next; });
  });

  // Sharing: quick-choice buttons fill in the permission grid of their form.
  document.querySelectorAll('[data-share-preset]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var values = JSON.parse(btn.getAttribute('data-share-preset'));
      Object.keys(values).forEach(function (section) {
        var radio = btn.form.querySelector('input[name="perm[' + section + ']"][value="' + values[section] + '"]');
        if (radio) { radio.checked = true; }
      });
    });
  });

  // "Start a 15-minute timer" (hypo guide): show the timer, set it and start it.
  document.querySelectorAll('[data-timer-quick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = document.querySelector('[data-timer]');
      if (!t) { return; }
      t.hidden = false;
      var secs = btn.getAttribute('data-timer-quick');
      var set = t.querySelector('[data-timer-set="' + secs + '"]');
      if (set) { set.click(); }
      t.querySelector('[data-timer-start]').click();
      t.scrollIntoView({ block: 'center' });
    });
  });

  // "Remember this device" on the sign-in page also applies to the single sign-on buttons.
  var rememberBox = document.querySelector('[data-remember-device]');
  if (rememberBox) {
    var syncSso = function () {
      document.querySelectorAll('a[data-sso]').forEach(function (a) {
        var u = new URL(a.href, window.location.href);
        if (rememberBox.checked) { u.searchParams.set('remember', '1'); } else { u.searchParams.delete('remember'); }
        a.href = u.toString();
      });
    };
    rememberBox.addEventListener('change', syncSso);
    syncSso();
  }

  // Read-only text to copy: select it all when focused.
  document.querySelectorAll('[data-select-on-focus]').forEach(function (el) { el.addEventListener('focus', function () { el.select(); }); });

  // Visual timer (timer.php).
  var timer = document.querySelector('[data-timer]');
  if (timer) {
    var total = 300, left = 300, tick = null, endAt = 0;
    var ring = timer.querySelector('[data-timer-ring]'), label = timer.querySelector('[data-timer-time]'), status = timer.querySelector('[data-timer-status]');
    var startBtn = timer.querySelector('[data-timer-start]'), pauseBtn = timer.querySelector('[data-timer-pause]');
    var show = function () {
      var s = Math.max(0, Math.ceil(left));
      label.textContent = (s >= 3600 ? Math.floor(s / 3600) + ':' + String(Math.floor(s / 60) % 60).padStart(2, '0') : Math.floor(s / 60)) + ':' + String(s % 60).padStart(2, '0');
      ring.setAttribute('stroke-dasharray', (total ? (left / total) * 100 : 0) + ' 100');
    };
    var stop = function () { clearInterval(tick); tick = null; startBtn.hidden = false; pauseBtn.hidden = true; };
    // Counting down to a set time (the next dose): Start always counts to that moment, however long the page was open.
    var until = parseInt(timer.getAttribute('data-timer-until') || '0', 10) * 1000, toUntil = false;
    var set = function (secs) { stop(); total = left = secs; toUntil = false; timer.classList.remove('done'); show(); };
    var setUntil = function () { set(Math.max(0, Math.round((until - Date.now()) / 1000))); toUntil = true; };
    timer.querySelectorAll('[data-timer-set]').forEach(function (b) { b.addEventListener('click', function () { set(parseInt(b.getAttribute('data-timer-set'), 10)); }); });
    timer.querySelector('[data-timer-custom]').addEventListener('change', function (e) { var m = parseInt(e.target.value, 10); if (m > 0) { set(m * 60); } });
    startBtn.addEventListener('click', function () {
      if (toUntil && left === total) { set(Math.max(0, Math.round((until - Date.now()) / 1000))); toUntil = true; }
      if (left <= 0) { left = total; }
      endAt = Date.now() + left * 1000;
      startBtn.hidden = true; pauseBtn.hidden = false; timer.classList.remove('done');
      tick = setInterval(function () {
        left = (endAt - Date.now()) / 1000;
        if (left <= 0) {
          left = 0; stop(); timer.classList.add('done'); status.textContent = 'Time is up.';
          if (navigator.vibrate) { navigator.vibrate([300, 150, 300, 150, 300]); }
        }
        show();
      }, 250);
    });
    pauseBtn.addEventListener('click', stop);
    timer.querySelector('[data-timer-reset]').addEventListener('click', function () { if (toUntil) { setUntil(); } else { set(total); } });
    var untilBtn = timer.querySelector('[data-timer-to-until]');
    if (untilBtn) { untilBtn.addEventListener('click', setUntil); }
    if (until > Date.now()) { setUntil(); }
    show();
  }

  // Privacy (Account > Privacy): cover the page while the app is in the background, so the app
  // switcher and anyone glancing at the screen don't see it.
  if (document.body.hasAttribute('data-privacy-blur')) {
    var cover = function (on) { document.documentElement.classList.toggle('privacy-cover', on); };
    document.addEventListener('visibilitychange', function () { cover(document.visibilityState === 'hidden'); });
    window.addEventListener('pagehide', function () { cover(true); });
    window.addEventListener('pageshow', function () { cover(false); });
    window.addEventListener('blur', function () { cover(true); });
    window.addEventListener('focus', function () { cover(false); });
  }

  // "Add supplement": common supplements fill in the name, strength, form and dose unit.
  document.querySelectorAll('[data-fill-med]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      [['med-name', 'name'], ['med-strength', 'strength'], ['med-form', 'form'], ['med-unit', 'unit']].forEach(function (pair) {
        var field = document.getElementById(pair[0]);
        if (field) { field.value = btn.getAttribute('data-' + pair[1]); field.dispatchEvent(new Event('change', { bubbles: true })); }
      });
      document.querySelectorAll('[data-fill-med]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
    });
  });

  // Schedule editor: Morning / Afternoon / Evening buttons fill in that dose row's time.
  function markTimePresets(row) {
    var input = row.querySelector('input[type=time]');
    row.querySelectorAll('[data-set-time]').forEach(function (b) {
      b.setAttribute('aria-pressed', input && input.value === b.getAttribute('data-set-time') ? 'true' : 'false');
    });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-set-time]');
    if (!btn) { return; }
    var row = btn.closest('.schedule-row');
    var input = row && row.querySelector('input[type=time]');
    if (input) { input.value = btn.getAttribute('data-set-time'); markTimePresets(row); }
  });
  document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('.schedule-row input[type=time]')) { markTimePresets(e.target.closest('.schedule-row')); }
  });

  // Prescription payments: for NHS charges the amount follows the number of items until it's
  // changed by hand; private and bought medicines have their own prices, so it's left alone.
  document.querySelectorAll('input[data-charge]').forEach(function (items) {
    var amount = items.form.querySelector('input[name=amount]'), kind = items.form.querySelector('select[name=kind]'), touched = false;
    amount.addEventListener('input', function () { touched = true; });
    function follow() {
      if (touched) { return; }
      if (kind && kind.value !== 'nhs') { amount.value = ''; return; }
      amount.value = (parseFloat(items.getAttribute('data-charge')) * (parseInt(items.value, 10) || 0)).toFixed(2);
    }
    items.addEventListener('input', follow);
    if (kind) { kind.addEventListener('change', follow); }
  });

  // Push notifications for this device (Account page).
  var pushBox = document.querySelector('[data-push]');
  if (pushBox) {
    var pStatus = pushBox.querySelector('[data-push-status]'), pOn = pushBox.querySelector('[data-push-on]'),
      pOff = pushBox.querySelector('[data-push-off]'), pTest = pushBox.querySelector('[data-push-test]'), pCsrf = pushBox.getAttribute('data-csrf');
    var pCall = function (body) {
      body.csrf = pCsrf;
      return fetch(document.body.getAttribute('data-base') + '/push.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (j) { if (j.error) { throw new Error(j.error); } return j; }); });
    };
    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    var ios = /iPad|iPhone|iPod/.test(navigator.userAgent);
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      pStatus.textContent = ios && !standalone
        ? 'On iPhone and iPad, add Pillbox to your Home Screen first (Share, then "Add to Home Screen"), then open it from there and turn notifications on.'
        : "This browser can't show notifications.";
    } else {
      var show = function (sub) {
        pOn.hidden = !!sub; pOff.hidden = !sub; pTest.hidden = !sub;
        pStatus.textContent = Notification.permission === 'denied' ? 'Notifications are blocked for Pillbox in this browser\'s settings.'
          : (sub ? 'Notifications are on for this device.' : 'Notifications are off for this device.');
      };
      navigator.serviceWorker.ready.then(function (reg) {
        reg.pushManager.getSubscription().then(show);
        pOn.addEventListener('click', function () {
          pOn.disabled = true;
          Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') { throw new Error('Notifications were not allowed.'); }
            return pCall({ action: 'key' });
          }).then(function (r) {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64.dec(r.key) });
          }).then(function (sub) {
            var label = (navigator.userAgentData && navigator.userAgentData.platform) || (/Android/.test(navigator.userAgent) ? 'Android' : ios ? 'iPhone or iPad' : /Mac/.test(navigator.userAgent) ? 'Mac' : /Windows/.test(navigator.userAgent) ? 'Windows' : 'This device');
            return pCall({ action: 'subscribe', subscription: sub.toJSON(), label: label }).then(function () { show(sub); });
          }).catch(function (e) { pStatus.textContent = e.message; }).then(function () { pOn.disabled = false; });
        });
        pOff.addEventListener('click', function () {
          reg.pushManager.getSubscription().then(function (sub) {
            if (!sub) { show(null); return; }
            return pCall({ action: 'unsubscribe', subscription: sub.toJSON() }).then(function () { return sub.unsubscribe(); }).then(function () { show(null); });
          }).catch(function (e) { pStatus.textContent = e.message; });
        });
        pTest.addEventListener('click', function () {
          pStatus.textContent = 'Sending...';
          pCall({ action: 'test' }).then(function () { pStatus.textContent = 'Test sent: it should appear in a few seconds.'; })
            .catch(function (e) { pStatus.textContent = e.message; });
        });
      });
    }
  }

  // Scrolling chip rows (e.g. Care categories on a phone): bring the current one into view.
  document.querySelectorAll('.chips [aria-current="page"]').forEach(function (chip) {
    var row = chip.parentElement;
    if (row.scrollWidth > row.clientWidth) {
      row.scrollLeft = chip.offsetLeft - row.offsetLeft - (row.clientWidth - chip.offsetWidth) / 2;
    }
  });

  // Travel: offer to pick the time zone this device is currently set to.
  document.querySelectorAll('[data-use-device-tz]').forEach(function (button) {
    var select = document.querySelector(button.getAttribute('data-use-device-tz'));
    var tz = window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!select || !tz || !select.querySelector('option[value="' + tz + '"]')) { return; }
    button.hidden = false;
    button.textContent = 'Use this phone\'s time zone (' + tz.split('/').pop().replace(/_/g, ' ') + ')';
    button.addEventListener('click', function () { select.value = tz; });
  });

  // Switching a dose row between "same amount" and "different amount each day"
  // carries the amounts across so nothing has to be retyped.
  document.addEventListener('change', function (e) {
    var radio = e.target;
    if (!radio.matches('.mode-switch input[type=radio]')) { return; }
    var row = radio.closest('.schedule-row');
    var amount = row.querySelector('.mode-same input[type=number]');
    var dayChecks = row.querySelectorAll('.mode-same .day input');
    var dayAmounts = row.querySelectorAll('.day-amounts input');
    var anyDaily = Array.prototype.some.call(dayAmounts, function (i) { return i.value !== ''; });
    if (radio.value === 'daily' && !anyDaily) {
      dayChecks.forEach(function (check, i) { dayAmounts[i].value = check.checked ? amount.value : ''; });
      if (dayAmounts[0]) { dayAmounts[0].focus(); }
    } else if (radio.value === 'same' && amount.value === '') {
      var first = Array.prototype.find.call(dayAmounts, function (i) { return i.value !== ''; });
      if (first) { amount.value = first.value; }
      dayChecks.forEach(function (check, i) { check.checked = dayAmounts[i].value !== '' && parseFloat(dayAmounts[i].value) > 0; });
    }
  });
})();

// NHS dm+d medicine search on the medication form.
(function () {
  'use strict';
  var box = document.querySelector('.dmd-search');
  if (!box) { return; }
  var input = document.getElementById('dmd-q');
  var list = document.getElementById('dmd-results');
  var status = document.getElementById('dmd-status');
  var codeField = document.getElementById('dmd_code');
  var nameField = document.getElementById('med-name');
  var timer = null;
  var latest = 0;
  var pickedName = null;

  function setOpen(open) {
    list.hidden = !open;
    input.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function setSelect(id, value) {
    var select = document.getElementById(id);
    if (select && select.querySelector('option[value="' + value + '"]')) { select.value = value; }
  }

  function choose(item) {
    nameField.value = item.name;
    document.getElementById('med-strength').value = item.strength;
    setSelect('med-form', item.form);
    setSelect('med-unit', item.dose_unit);
    codeField.value = item.code;
    pickedName = item.name;
    var linked = document.getElementById('dmd-linked');
    if (linked) { linked.remove(); }
    status.textContent = 'Filled in from NHS dm+d: ' + item.display + '. Check the details below.';
    input.value = '';
    setOpen(false);
    nameField.focus();
  }

  function render(results) {
    list.innerHTML = '';
    results.forEach(function (item) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = item.display;
      button.addEventListener('click', function () { choose(item); });
      li.appendChild(button);
      list.appendChild(li);
    });
    setOpen(results.length > 0);
  }

  function search() {
    var term = input.value.trim();
    if (term.length < 3) { render([]); status.textContent = ''; return; }
    var request = ++latest;
    status.textContent = 'Searching…';
    fetch(box.getAttribute('data-endpoint') + '?q=' + encodeURIComponent(term), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (request !== latest) { return; } // a newer search has started
        if (data.error) { render([]); status.textContent = data.error; return; }
        render(data.results || []);
        status.textContent = data.results && data.results.length
          ? data.results.length + ' match' + (data.results.length === 1 ? '' : 'es') + '. Choose one to fill in the form.'
          : 'No matches. Try fewer words, or type the details in yourself.';
      })
      .catch(function () {
        if (request === latest) { status.textContent = 'Search is unavailable right now. You can type the details in yourself.'; }
      });
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(search, 350);
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { setOpen(false); }
    if (e.key === 'ArrowDown' && !list.hidden) {
      e.preventDefault();
      var first = list.querySelector('button');
      if (first) { first.focus(); }
    }
    if (e.key === 'Enter') { e.preventDefault(); } // don't submit the whole form
  });
  list.addEventListener('keydown', function (e) {
    var current = document.activeElement.closest('li');
    if (!current) { return; }
    var next = e.key === 'ArrowDown' ? current.nextElementSibling : e.key === 'ArrowUp' ? current.previousElementSibling : null;
    if (e.key === 'ArrowUp' && !next) { e.preventDefault(); input.focus(); return; }
    if (next) { e.preventDefault(); next.querySelector('button').focus(); }
    if (e.key === 'Escape') { setOpen(false); input.focus(); }
  });

  // Renaming the medicine by hand means it may no longer be that dm+d product.
  nameField.addEventListener('input', function () {
    if (codeField.value && nameField.value !== pickedName) {
      codeField.value = '';
      var linked = document.getElementById('dmd-linked');
      if (linked) { linked.textContent = 'No longer linked to an NHS dm+d code because the name was changed.'; }
    }
  });
})();

// Installable app: service worker, install button and home-screen prompt.
(function () {
  'use strict';
  var baseMeta = document.querySelector('meta[name="pillbox-base"]');
  var base = baseMeta ? baseMeta.getAttribute('content') : './';
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(function () { /* app still works without it */ });
    });
  }

  function storageGet(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
  function storageSet(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* ignore */ } }

  var installedNote = document.querySelector('.installed-note');
  var installHelp = document.querySelector('.install-help');
  if (standalone && installedNote) {
    installedNote.hidden = false;
    if (installHelp) { installHelp.hidden = true; }
  }

  // Android / desktop Chrome & Edge: offer the browser's own install prompt.
  var deferredPrompt = null;
  var installButton = document.getElementById('install-button');
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    var wrap = document.querySelector('.install-android');
    if (wrap) { wrap.hidden = false; }
  });
  if (installButton) {
    installButton.addEventListener('click', function () {
      if (!deferredPrompt) { return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () { deferredPrompt = null; });
    });
  }

  // Gentle prompt on the Today page for phones that haven't installed it yet.
  var banner = document.querySelector('.install-banner');
  var isPhone = window.matchMedia('(max-width: 760px) and (pointer: coarse)').matches;
  if (banner && isPhone && !standalone && storageGet('pillbox-install-dismissed') !== '1') {
    banner.hidden = false;
    banner.querySelector('[data-dismiss-install]').addEventListener('click', function () {
      storageSet('pillbox-install-dismissed', '1');
      banner.hidden = true;
    });
  }
})();

// Apple Health export upload: sends big files in pieces so server upload limits don't matter.
(function () {
  'use strict';
  var form = document.getElementById('health-upload');
  if (!form || !window.fetch || !window.FormData || !window.crypto || !Blob.prototype.slice) { return; }
  var box = form.querySelector('.upload-progress');
  var bar = box.querySelector('progress');
  var status = box.querySelector('p');
  var button = form.querySelector('button[type=submit]');

  function post(data) {
    // getAttribute: the form has a field named "action", which hides the form.action property.
    return fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) { throw new Error(d.error || 'Something went wrong.'); } return d; });
  }
  function base() {
    var fd = new FormData();
    fd.append('csrf', form.querySelector('[name=csrf]').value);
    return fd;
  }

  form.addEventListener('submit', function (e) {
    var file = form.querySelector('input[type=file]').files[0];
    if (!file) { return; }
    e.preventDefault();
    var size = parseInt(form.getAttribute('data-chunk-size'), 10) || 2097152;
    var total = Math.max(1, Math.ceil(file.size / size));
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    var id = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    var index = 0;
    box.hidden = false;
    button.disabled = true;

    function fail(err) {
      status.textContent = err.message + ' Nothing was imported.';
      bar.value = 0;
      button.disabled = false;
    }
    function next() {
      if (index >= total) { return finish(); }
      var fd = base();
      fd.append('action', 'upload_chunk');
      fd.append('upload_id', id);
      fd.append('index', String(index));
      fd.append('chunk', file.slice(index * size, Math.min(file.size, (index + 1) * size)), 'chunk');
      post(fd).then(function () {
        index++;
        bar.value = Math.round(index / total * 80);
        status.textContent = 'Uploading… ' + Math.round(index / total * 100) + '% of ' + (file.size / 1048576).toFixed(1) + ' MB';
        next();
      }).catch(fail);
    }
    function finish() {
      status.textContent = 'Reading your health data… this can take a minute or two for a big export. Please keep this page open.';
      bar.removeAttribute('value'); // indeterminate
      var fd = base();
      fd.append('action', 'import_upload');
      fd.append('upload_id', id);
      fd.append('range', form.querySelector('[name=range]').value);
      post(fd).then(function (d) { window.location.href = d.redirect; }).catch(fail);
    }
    status.textContent = 'Uploading…';
    next();
  });
})();
