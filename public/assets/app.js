// Pillbox progressive enhancements. Every page still works without JavaScript.
(function () {
  'use strict';

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
  // data-show-when="<input id>" / data-hide-when="<input id>"
  function syncToggles() {
    document.querySelectorAll('[data-show-when], [data-hide-when]').forEach(function (el) {
      var showId = el.getAttribute('data-show-when');
      var hideId = el.getAttribute('data-hide-when');
      var input = document.getElementById(showId || hideId);
      if (!input) { return; }
      var visible = showId ? input.checked : !input.checked;
      el.hidden = !visible;
    });
  }
  document.addEventListener('change', syncToggles);
  syncToggles();

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
