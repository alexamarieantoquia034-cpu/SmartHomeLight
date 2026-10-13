/* ==========================================================
   SmartHome Light — front-end controller v2
   ========================================================== */
(function () {
  'use strict';

  var BASE = window.APP_BASE || '';
  var api  = function (path) { return BASE + '/' + path; };

  /* ---------- helpers ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }
  function csrf() {
    if (window.CSRF_TOKEN) return window.CSRF_TOKEN;
    var el = document.querySelector('input[name="csrf"]');
    return el ? el.value : '';
  }
  function hex(r, g, b) {
    return '#' + [r, g, b].map(function (n) {
      return Number(n).toString(16).padStart(2, '0');
    }).join('');
  }
  function busy(btn, on) {
    if (!btn) return;
    btn.classList.toggle('is-busy', !!on);
    btn.disabled = !!on;
  }

  /* ---------- toasts (replaces window.alert) ---------- */
  var stack = null;
  function toastStack() {
    if (stack) return stack;
    stack = $('.toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      stack.setAttribute('role', 'status');
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    return stack;
  }
  function toast(msg, kind) {
    kind = kind === 'err' ? 'err' : 'ok';
    var el = document.createElement('div');
    el.className = 'toast ' + kind;
    var ico = document.createElement('span');
    ico.className = 't-ico';
    ico.textContent = kind === 'err' ? '\u26A0' : '\u2714';
    var txt = document.createElement('span');
    txt.textContent = msg;
    el.appendChild(ico);
    el.appendChild(txt);
    toastStack().appendChild(el);
    setTimeout(function () {
      el.classList.add('out');
      setTimeout(function () { el.remove(); }, 320);
    }, 3600);
  }

  /* ---------- network ---------- */
  async function post(path, data) {
    var body = new FormData();
    body.append('csrf', csrf());
    Object.entries(data || {}).forEach(function (e) { body.append(e[0], e[1]); });

    var res = await fetch(api(path), { method: 'POST', body, credentials: 'same-origin' });
    if (res.status === 401 || (res.redirected && res.url.indexOf('login') !== -1)) {
      window.location.href = api('login.php');
      throw new Error('Session expired');
    }
    var json = await res.json().catch(function () {
      return { ok: false, error: 'Bad response' };
    });
    if (!json.ok) throw new Error(json.error || 'Request failed');
    return json;
  }

  function sendColor(id, colorHex) {
    return post('api/light_action.php', {
      device_id: id,
      action: 'color',
      r: parseInt(colorHex.slice(1, 3), 16),
      g: parseInt(colorHex.slice(3, 5), 16),
      b: parseInt(colorHex.slice(5, 7), 16)
    });
  }

  /* keep the range track filled up to the thumb */
  function paintSlider(slider) {
    if (!slider) return;
    var min = Number(slider.min || 0), max = Number(slider.max || 100);
    var pct = ((Number(slider.value) - min) / (max - min)) * 100;
    slider.style.setProperty('--pct', pct + '%');
  }

/* ==========================================================
     LIGHT CARDS
     ========================================================== */
  var cards = $$('.light-card');

  cards.forEach(function (card) {
    var id       = card.dataset.device;
    var powerBtn = card.querySelector('[data-action="toggle"]');
    var testBtn  = card.querySelector('[data-action="test"]');
    var slider   = card.querySelector('[data-action="brightness"]');
    var colorInp = card.querySelector('[data-action="color"]');
    var swatches = $$('[data-action="swatch"]', card);
    var select   = card.querySelector('[data-action="preset"]');
    var stage    = card.querySelector('.bulb-stage');

    /* --- test blink --- */
    if (testBtn) {
      testBtn.addEventListener('click', async function () {
        busy(testBtn, true);
        try {
          await post('api/light_action.php', { device_id: id, action: 'test' });
          toast(card.dataset.online === '0'
            ? 'Test blink queued \u2014 it will run when the light reconnects'
            : 'Test blink sent \u2014 the LED should flash R / G / B / white');
        } catch (err) {
          toast(err.message, 'err');
        }
        setTimeout(function () { busy(testBtn, false); }, 1200);
      });
    }

    /* --- power toggle --- */
    if (powerBtn) {
      powerBtn.addEventListener('click', async function () {
        var turningOff = card.classList.contains('is-on');
        var offline = card.dataset.online === '0';
        busy(powerBtn, true);
        try {
          await post('api/light_action.php', { device_id: id, action: 'toggle' });
          await refresh();
          toast(offline
            ? (turningOff ? 'Saved \u2014 the LED will switch off when it reconnects'
                          : 'Saved \u2014 the LED will switch on when it reconnects')
            : (turningOff ? 'Light turned off' : 'Light turned on'));
        } catch (err) {
          toast(err.message, 'err');
        }
        busy(powerBtn, false);
      });
    }

    /* --- brightness slider --- */
    if (slider) {
      var label = card.querySelector('[data-role="brightness-label"]');
      var timer = null;
      paintSlider(slider);
      slider.addEventListener('input', function () {
        if (label) label.textContent = slider.value + '%';
        paintSlider(slider);
        // live preview of the glow while dragging
        var bulb = card.querySelector('.bulb');
        if (bulb) bulb.style.opacity = (0.45 + 0.55 * (slider.value / 100)).toFixed(2);
      });
      slider.addEventListener('change', function () {
        clearTimeout(timer);
        timer = setTimeout(async function () {
          try {
            await post('api/light_action.php', {
              device_id: id, action: 'brightness', brightness: slider.value
            });
            await refresh();
            toast('Brightness set to ' + slider.value + '%');
          } catch (err) { toast(err.message, 'err'); }
        }, 180);
      });
    }

    /* --- colour picker --- */
    if (colorInp) {
      var hexOut = card.querySelector('[data-role="hex"]');
      var setHex = function (h) { if (hexOut) hexOut.textContent = h.toUpperCase(); };
      setHex(colorInp.value);
      colorInp.addEventListener('input', function () { setHex(colorInp.value); });
      colorInp.addEventListener('change', async function () {
        try {
          await sendColor(id, colorInp.value);
          await refresh();
          toast('Colour set to ' + colorInp.value.toUpperCase());
        } catch (err) { toast(err.message, 'err'); }
      });
    }

    /* --- preset swatches (new UI) --- */
    swatches.forEach(function (sw) {
      sw.addEventListener('click', async function () {
        swatches.forEach(function (o) { o.style.outline = ''; });
        sw.style.outline = '2px solid rgba(255,255,255,.85)';
        try {
          await sendColor(id, sw.dataset.hex);
          await refresh();
          toast((sw.dataset.name || 'Preset') + ' applied');
        } catch (err) { toast(err.message, 'err'); }
      });
    });

    /* --- legacy preset <select> (kept for backwards compatibility) --- */
    if (select) {
      select.addEventListener('change', async function () {
        var h = select.value.split('|')[1];
        if (!h) return;
        try {
          await sendColor(id, h);
          select.selectedIndex = 0;
          await refresh();
        } catch (err) { toast(err.message, 'err'); }
      });
    }

    /* --- clicking the bulb itself toggles the light --- */
    if (stage) {
      stage.addEventListener('click', function () {
        if (powerBtn) powerBtn.click();
      });
      stage.style.cursor = 'pointer';
      stage.title = 'Click to turn the light on / off';
    }
  });

/* ==========================================================
     RENDER / POLLING
     ========================================================== */
  function paintCard(card, d) {
    card.classList.toggle('is-on', !!d.is_on);
    card.classList.toggle('is-offline', !d.online);

    var rgb    = d.color_r + ',' + d.color_g + ',' + d.color_b;
    var dim    = 0.32 + 0.68 * (d.brightness / 100);
    var bulb   = card.querySelector('.bulb');
    var icon   = card.querySelector('.bulb-icon');

    if (bulb) {
      bulb.style.background = d.is_on
        ? 'rgba(' + rgb + ',' + dim.toFixed(2) + ')'
        : 'rgba(120,130,150,.10)';
      bulb.style.boxShadow = d.is_on
        ? '0 0 46px rgba(' + rgb + ',.55), inset 0 0 26px rgba(' + rgb + ',.35)'
        : 'none';
      bulb.style.opacity = d.is_on ? '1' : (0.45 + 0.55 * (d.brightness / 100)).toFixed(2);
    }
    if (icon) icon.textContent = d.is_on ? '\uD83D\uDDA1' : '\uD83C\uDF19';

    // tint the top of the card with the live colour
    card.style.setProperty('--wash',
      d.is_on ? 'rgba(' + rgb + ',.16)' : 'transparent');

    var setText = function (role, text) {
      var el = card.querySelector('[data-role="' + role + '"]');
      if (el) el.textContent = text;
    };
    setText('state', d.is_on ? 'ON' : 'OFF');
    setText('brightness', d.brightness + '%');
    setText('brightness-label', d.brightness + '%');
    setText('power', Number(d.watts).toFixed(1) + ' W');

    // Live LDR reading (raw value + dark/bright verdict).
    var ldrEl = card.querySelector('[data-role="ldr"]');
    if (ldrEl) {
      ldrEl.textContent = '\u2600 ' + (d.ldr || 0) + ' \u00b7 ' + (d.room_dark ? 'dark' : 'bright');
    }

    // Remember reachability so the click handlers can explain themselves.
    card.dataset.online = d.online ? '1' : '0';

    var sync = card.querySelector('[data-role="sync"]');
    if (sync) {
      if (!d.online) {
        sync.textContent = 'device offline \u00b7 changes will queue';
        sync.className = 'sync-note wait';
      } else if (d.in_sync) {
        sync.textContent = '\u2714 LED matches the setting';
        sync.className = 'sync-note ok';
      } else {
        sync.textContent = 'waiting for the LED to confirm…';
        sync.className = 'sync-note wait';
      }
    }

    var dot = card.querySelector('.status-dot');
    if (dot) {
      dot.classList.toggle('on', !!d.online);
      dot.classList.toggle('off', !d.online);
      dot.title = d.online ? 'Online' : 'Offline';
    }

    var btn = card.querySelector('[data-action="toggle"]');
    if (btn) {
      var label = d.is_on ? 'Turn off' : 'Turn on';
      if (btn.textContent.trim() !== label) btn.textContent = label;
      btn.classList.toggle('on', !!d.is_on);
      // Do NOT disable when offline: light_action.php queues the command and
      // device_poll.php replays it the moment the ESP32 reconnects.
      btn.classList.toggle('is-queued', !d.online);
      btn.disabled = false;
      btn.title = d.online ? '' : 'Device is offline \u2014 will apply when it reconnects';
    }

    var sl = card.querySelector('[data-action="brightness"]');
    if (sl && document.activeElement !== sl) {
      sl.value = d.brightness;
      paintSlider(sl);
    }

    var ci = card.querySelector('[data-action="color"]');
    if (ci && document.activeElement !== ci) {
      var h = hex(d.color_r, d.color_g, d.color_b);
      ci.value = h;
      var ho = card.querySelector('[data-role="hex"]');
      if (ho) ho.textContent = h.toUpperCase();
    }

    var foot = card.querySelector('.light-foot');
    if (foot) {
      var spans = foot.querySelectorAll('span');
      if (spans[0]) spans[0].textContent = '\uD83D\uDCF6 ' + d.rssi + ' dBm';
      if (spans[1]) spans[1].textContent = '\uD83C\uDF10 ' + (d.ip_address || 'no ip');
    }
  }

  async function refresh() {
    if (!cards.length) return;
    try {
      var res  = await fetch(api('api/get_state.php'), { credentials: 'same-origin' });
      if (res.redirected) { window.location.href = api('login.php'); return; }
      var json = await res.json();
      if (!json.ok) return;

      json.devices.forEach(function (d) {
        var card = document.querySelector('.light-card[data-device="' + d.id + '"]');
        if (card) paintCard(card, d);
      });

      var s = json.summary;
      var s1 = $('#statOn');    if (s1) s1.textContent = s.lights_on;
      var s2 = $('#statWatts'); if (s2) s2.textContent = Number(s.total_watts).toFixed(1);

      var pill = $('#connStatus');
      if (pill) {
        pill.textContent = s.online + ' / ' + s.total +
          ' device' + (s.total === 1 ? '' : 's') + ' online';
        pill.classList.toggle('pill-ok', s.online > 0);
        pill.classList.toggle('pill-warn', s.online === 0);
      }
    } catch (e) { /* keep last known state */ }
  }

  if (cards.length) {
    refresh();
    setInterval(refresh, 4000);
    // pause polling when the tab is hidden (saves the ESP32 + browser)
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refresh();
    });
  }

/* ==========================================================
     PAGE-LEVEL ACTIONS (called by inline onclick= in the PHP pages)
     ========================================================== */
  window.copyKey = function (btn) {
    var cell = btn.closest('tr').querySelector('[data-role="key"]');
    if (!cell) return;
    var key = cell.textContent.trim();
    var done = function () {
      var old = btn.textContent;
      btn.textContent = 'Copied!';
      toast('Device key copied to clipboard');
      setTimeout(function () { btn.textContent = old; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(key).then(done, function () { prompt('Copy this key:', key); });
    } else {
      prompt('Copy this key:', key);
    }
  };

  window.delDevice = async function (id, btn) {
    busy(btn, true);
    try {
      await post('api/device_action.php', { action: 'delete', device_id: id });
      var row = btn.closest('tr');
      row.style.transition = 'opacity .3s, transform .3s';
      row.style.opacity = '0';
      row.style.transform = 'translateX(-20px)';
      setTimeout(function () { row.remove(); }, 320);
      toast('Device deleted');
    } catch (err) {
      toast(err.message, 'err');
      busy(btn, false);
    }
  };

  window.runNow = async function (id, btn) {
    busy(btn, true);
    try {
      await post('api/schedule_action.php', { action: 'run_now', id: id });
      btn.textContent = 'Sent';
      toast('Schedule executed now');
      setTimeout(function () { btn.textContent = 'Run now'; busy(btn, false); }, 1500);
    } catch (err) {
      toast(err.message, 'err');
      busy(btn, false);
    }
  };

  /* ---------- mobile nav ---------- */
  var navToggle = $('.nav-toggle');
  if (navToggle) {
    navToggle.addEventListener('click', function () {
      var nav = $('.nav');
      if (nav) nav.classList.toggle('open');
    });
  }

  /* ---------- show / hide password (login form) ---------- */
  var pwBtn = $('.pw-toggle');
  if (pwBtn) {
    pwBtn.addEventListener('click', function () {
      var input = pwBtn.parentNode.querySelector('input');
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      pwBtn.textContent = show ? '\u2712' : '\u25CF';
      pwBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      input.focus();
    });
  }

  /* ---------- day-of-week checkboxes -> hidden 7-bit field ---------- */
  var boxes = $$('.day-box');
  if (boxes.length) {
    var syncDays = function () {
      var bits = '';
      boxes.forEach(function (b) { bits += b.checked ? '1' : '0'; });
      var hidden = $('#daysOfWeek');
      if (hidden) hidden.value = bits;
    };
    boxes.forEach(function (b) { b.addEventListener('change', syncDays); });
    syncDays();
  }

  /* ---------- add-device form toggle (devices page) ---------- */
  window.toggleAddForm = function () {
    var f = $('#addForm');
    if (f) f.classList.toggle('hidden');
  };

  /* ---------- "All on / All off" quick actions (dashboard) ---------- */
  window.bulkToggle = async function (turnOn) {
    var btns = $$('.light-card [data-action="toggle"]').filter(function (b) {
      return !b.disabled && b.classList.contains('on') !== turnOn;
    });
    if (!btns.length) { toast('Nothing to change', 'err'); return; }
    btns.forEach(function (b) { busy(b, true); });
    var done = 0;
    for (var i = 0; i < btns.length; i++) {
      try {
        await post('api/light_action.php', {
          device_id: btns[i].closest('.light-card').dataset.device,
          action: turnOn ? 'on' : 'off'
        });
        done++;
      } catch (e) { /* keep going through the rest */ }
    }
    btns.forEach(function (b) { busy(b, false); });
    await refresh();
    toast(done + ' light' + (done === 1 ? '' : 's') + (turnOn ? ' turned on' : ' turned off'));
  };

  /* ---------- dismiss flash messages after a few seconds ---------- */
  var flash = $('.alert[data-auto-dismiss]');
  if (flash) {
    setTimeout(function () {
      flash.style.transition = 'opacity .4s, transform .4s';
      flash.style.opacity = '0';
      flash.style.transform = 'translateY(-8px)';
      setTimeout(function () { flash.remove(); }, 420);
    }, 4200);
  }
})();