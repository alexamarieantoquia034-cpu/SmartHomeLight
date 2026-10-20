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

    var controller = new AbortController();
    var timeoutId = setTimeout(function () { controller.abort(); }, 30000);

    try {
      var res = await fetch(api(path), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        signal: controller.signal
      });
      clearTimeout(timeoutId);

      if (res.status === 401 || (res.redirected && res.url.indexOf('login') !== -1)) {
        window.location.href = api('login.php');
        throw new Error('Session expired');
      }
      var json = await res.json().catch(function () {
        return { ok: false, error: 'Bad response' };
      });
      if (!json.ok) throw new Error(json.error || 'Request failed');
      return json;
    } catch (err) {
      clearTimeout(timeoutId);
      if (err.name === 'AbortError') {
        toast('Request timed out after 30 seconds', 'err');
      } else {
        toast(err.message, 'err');
      }
      throw err;
    }
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
            : 'Test blink sent \u2014 the relay should flash a few times now');
        } catch (err) {
          toast(err.message, 'err');
        }
        setTimeout(function () { busy(testBtn, false); }, 1200);
      });
    }

    /* --- operating mode pills (Auto / Manual / Schedule) --- */
    $$('.mode-pill', card).forEach(function (pill) {
      pill.addEventListener('click', async function () {
        var mode = pill.dataset.mode;
        if (!mode) return;
        $$('.mode-pill', card).forEach(function (p) { busy(p, true); });
        try {
          await post('api/light_action.php', {
            device_id: id, action: 'op_mode', op_mode: mode
          });
          toast('Mode set to ' + pill.textContent.trim());
          await refresh();
        } catch (err) {
          toast(err.message, 'err');
        }
        $$('.mode-pill', card).forEach(function (p) { busy(p, false); });
      });
    });

    /* --- power toggle --- */
    if (powerBtn) {
      powerBtn.addEventListener('click', async function () {
        var turningOff = card.classList.contains('is-on');
        var offline = card.dataset.online === '0';
        busy(powerBtn, true);
        var syncEl = card.querySelector('[data-role="sync"]');
        try {
          await post('api/light_action.php', { device_id: id, action: 'toggle' });
          if (syncEl) {
            syncEl.textContent = 'command sent \u00b7 waiting for the LED\u2026';
            syncEl.className = 'sync-note wait';
          }
          if (offline) {
            await refresh();
            toast(turningOff
              ? 'Saved \u2014 the LED will switch off when it reconnects'
              : 'Saved \u2014 the LED will switch on when it reconnects');
          } else {
            // Success only when the ESP32 ITSELF reports the new state.
            var d = await waitForSync(id, 12000);
            if (d && d.in_sync) {
              toast(turningOff ? 'Light turned off \u2014 confirmed' : 'Light turned on \u2014 confirmed');
            } else if (d && !d.online) {
              toast('Device went offline \u2014 command queued for when it reconnects', 'err');
            } else {
              await refresh();
              toast('Command sent, but the device has not confirmed yet \u2014 check the relay/power', 'err');
            }
          }
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

    // STATE shows what the HARDWARE last reported (devices.actual_on, written
    // by the ESP32 heartbeat), never the desired value. Only when the device
    // has never reported do we fall back to the desired state.
    var reported = !!d.reported_at;
    var actualOn = reported ? !!d.actual_on : !!d.is_on;
    var stateTxt = actualOn ? 'ON' : 'OFF';
    if (!reported) stateTxt += ' \u00b7 no report yet';
    else if (d.online && !d.in_sync) stateTxt += ' \u00b7 pending';
    setText('state', stateTxt);
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

    // Operating mode pills: highlight the authoritative backend mode.
    $$('.mode-pill', card).forEach(function (p) {
      p.classList.toggle('active', p.dataset.mode === d.op_mode);
    });

    var sync = card.querySelector('[data-role="sync"]');
    if (sync) {
      if (!d.online) {
        sync.textContent = 'device offline \u00b7 changes will queue';
        sync.className = 'sync-note wait';
      } else if (d.in_sync) {
        sync.textContent = '\u2714 LED reports ' + (actualOn ? 'ON' : 'OFF') + ' \u00b7 matches the setting';
        sync.className = 'sync-note ok';
      } else {
        sync.textContent = 'LED reports ' + (actualOn ? 'ON' : 'OFF') + ' \u00b7 confirming\u2026';
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

  /* Fetch the live state, paint every card + headline stats, return the JSON.
     Returns null on network hiccup / redirect (then the page navigates). */
  async function fetchState() {
    if (!cards.length) return null;
    try {
      var controller = new AbortController();
      var timeoutId = setTimeout(function () { controller.abort(); }, 30000);

      var res  = await fetch(api('api/get_state.php'), {
        credentials: 'same-origin',
        signal: controller.signal
      });
      clearTimeout(timeoutId);

      if (res.redirected) { window.location.href = api('login.php'); return null; }
      var json = await res.json();
      if (!json.ok) return null;

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
      return json;
    } catch (e) {
      // keep last known state; the timeout abort is expected when connection stalls
      return null;
    }
  }

  async function refresh() {
    await fetchState();
  }

  /* Wait until the HARDWARE itself reports the state we asked for
     (devices.in_sync - actual_on vs desired, updated by the ESP32's own
     heartbeat after it applied the command) or until the device drops
     offline / time runs out. This is what makes the success toast truthful:
     it fires only when the relay really changed, otherwise we report
     "not confirmed" instead of a fake success. */
  async function waitForSync(id, timeoutMs) {
    var deadline = Date.now() + (timeoutMs || 12000);
    var d = null;
    while (Date.now() < deadline) {
      var json = await fetchState();
      if (json) {
        d = null;
        json.devices.forEach(function (x) { if (String(x.id) === String(id)) d = x; });
        if (!d) return null;
        if (d.in_sync) return d;                 // device confirmed the state
        if (!d.online) return d;                 // dropped off - command queued
      }
      await new Promise(function (r) { setTimeout(r, 900); });
    }
    return d;                                    // last known (still unconfirmed)
  }

  /* ---------- statistics + charts ---------- */
  var energyChart = null, weeklyChart = null;

  // Shared dark-theme axis options (matches --text-2 / grid on --line-soft).
  function chartAxes() {
    return {
      y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.06)' }, ticks: { color: '#b7c3d6' } },
      x: { grid: { display: false }, ticks: { color: '#b7c3d6' } }
    };
  }

  // Swap a chart canvas for its empty-state message (and back).
  function setChartEmpty(canvas, emptyEl, isEmpty) {
    canvas.style.display = isEmpty ? 'none' : '';
    if (emptyEl) emptyEl.hidden = !isEmpty;
  }

  function fetchStats() {
    if (!$('#energyChart') && !$('#weeklyChart')) return;
    fetch(api('api/stats.php'), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (json) {
        if (!json.ok) return;

        // Render today's usage LINE chart (hours of day vs minutes)
        var ec = $('#energyChart');
        if (ec && json.today) {
          var ecEmpty = $('#energyEmpty');
          var ecTotal = $('#todayTotal');
          if (ecTotal) {
            ecTotal.textContent = json.today.has_data
              ? Number(json.today.total_minutes).toFixed(0) + ' min'
              : '\u2014';
          }
          setChartEmpty(ec, ecEmpty, !json.today.has_data);
          if (energyChart) { energyChart.destroy(); energyChart = null; }
          if (json.today.has_data) {
            energyChart = new Chart(ec.getContext('2d'), {
              type: 'line',
              data: {
                labels: json.today.labels,
                datasets: [{
                  label: 'Usage (min)',
                  data: json.today.minutes,
                  borderColor: '#5b9dff',
                  backgroundColor: 'rgba(91,157,255,.12)',
                  fill: true,
                  tension: 0.4,
                  pointRadius: 3,
                  pointHoverRadius: 5
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: chartAxes()
              }
            });
          }
        }

        // Render weekly OVERVIEW BAR chart (Mon-Sun vs hours)
        var wc = $('#weeklyChart');
        if (wc && json.weekly) {
          var wcEmpty = $('#weeklyEmpty');
          var wcTotal = $('#weekTotal');
          if (wcTotal) {
            wcTotal.textContent = json.weekly.has_data
              ? Number(json.weekly.total_hours).toFixed(1) + ' h'
              : '\u2014';
          }
          setChartEmpty(wc, wcEmpty, !json.weekly.has_data);
          if (weeklyChart) { weeklyChart.destroy(); weeklyChart = null; }
          if (json.weekly.has_data) {
            weeklyChart = new Chart(wc.getContext('2d'), {
              type: 'bar',
              data: {
                labels: json.weekly.labels,
                datasets: [{
                  label: 'Hours',
                  data: json.weekly.hours,
                  backgroundColor: '#5b9dff',
                  hoverBackgroundColor: '#7db1ff',
                  borderRadius: 6,
                  maxBarThickness: 34
                }]
              },
              options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: chartAxes()
              }
            });
          }
        }
      })
      .catch(function () { /* silent - stats are non-critical */ });
  }

  /* ---------- real-time clock (Asia/Manila) ---------- */
  function startClock() {
    var clockEl = $('#liveClock');
    var dateEl  = $('#liveDate');
    var greetEl = $('#liveGreet');
    if (!clockEl) return;

    var manilaOpts = { timeZone: 'Asia/Manila' };
    var timeFmt = new Intl.DateTimeFormat('en-PH', Object.assign({}, manilaOpts, {
      hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
    }));
    var dateFmt = new Intl.DateTimeFormat('en-PH', Object.assign({}, manilaOpts, {
      weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    }));
    var hourFmt = new Intl.DateTimeFormat('en-PH', Object.assign({}, manilaOpts, {
      hour: 'numeric', hour12: false
    }));

    var lastPeriod = '';

    function manilaHour(now) {
      var parts = hourFmt.formatToParts(now);
      var p = parts.find(function (x) { return x.type === 'hour'; });
      return p ? parseInt(p.value, 10) : now.getUTCHours();
    }

    function greetingPeriod(h) {
      return h < 12 ? 'Good morning' : (h < 17 ? 'Good afternoon' : 'Good evening');
    }

    function tick() {
      var now = new Date();
      clockEl.textContent = timeFmt.format(now);
      clockEl.setAttribute('datetime', now.toISOString());
      if (dateEl) dateEl.textContent = dateFmt.format(now);

      // Update personalized greeting when the period changes
      if (greetEl) {
        var h = manilaHour(now);
        var period = greetingPeriod(h);
        if (period !== lastPeriod) {
          lastPeriod = period;
          var current = greetEl.textContent;
          var userNm = current.replace(/^[^,]+,\s*/, '');
          greetEl.textContent = period + ', ' + userNm;
        }
      }
    }
    tick();
    setInterval(tick, 1000);
  }

  if (cards.length) {
    refresh();
    setInterval(refresh, 4000);
    // pause polling when the tab is hidden (saves the ESP32 + browser)
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refresh();
    });
  }

  // Start clock and stats regardless of whether devices exist
  startClock();
  if ($('#energyChart') || $('#weeklyChart')) {
    fetchStats();
    setInterval(fetchStats, 30000);
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

  /* ---------- History page: activity log filters + pagination ---------- */
  var logPager = $('#logPager');
  if (logPager) {
    var logForm = $('#logFilters');
    var logBody = $('#logBody');
    var logInfo = $('#logInfo');
    var logPrev = $('#logPrev');
    var logNext = $('#logNext');
    var logState = {
      page:    parseInt(logPager.dataset.page, 10) || 1,
      perPage: parseInt(logPager.dataset.perPage, 10) || 10
    };
    var logReq = 0;
    var logSearchTimer = null;

    function logEsc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function logRow(r) {
      return '<tr>' +
        '<td>' + logEsc(r.when) + '</td>' +
        '<td><span class="badge src-' + logEsc(r.source) + '">' + logEsc(r.source_label) + '</span></td>' +
        '<td>' + logEsc(r.device_name) + '</td>' +
        '<td>' + logEsc(r.action_label) + '</td>' +
        '<td class="muted">' + logEsc(r.detail) + '</td>' +
        '</tr>';
    }

    function logQuery(page) {
      var out = { page: page, per_page: logState.perPage };
      var data = new FormData(logForm);
      ['q', 'date_from', 'date_to', 'source'].forEach(function (k) {
        var v = String(data.get(k) || '').trim();
        if (v) out[k] = v;
      });
      return out;
    }

    function logApply(json) {
      var p = json.pagination;
      logState.page = p.page;
      logPager.dataset.page = p.page;
      logBody.innerHTML = json.rows.length
        ? json.rows.map(logRow).join('')
        : '<tr><td colspan="5" class="muted center">' +
            (json.filtered ? 'No records match your filters.' : 'Nothing logged yet.') +
          '</td></tr>';
      logInfo.innerHTML = p.total > 0
        ? 'Page <strong>' + p.page + '</strong> of ' + p.total_pages +
          ' <span class="pager-count">' + Number(p.total).toLocaleString() + ' records</span>'
        : 'No records';
      logPrev.disabled = !p.has_prev;
      logNext.disabled = !p.has_next;
      // Keep the current view shareable/bookmarkable without reloading.
      try {
        var qs = new URLSearchParams(logQuery(p.page)).toString();
        history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
      } catch (e) { /* older browsers: skip URL sync */ }
    }

    async function loadLog(page) {
      var req = ++logReq;
      logPager.classList.add('is-loading');
      try {
        var res = await fetch(api('api/history_log.php') + '?' + new URLSearchParams(logQuery(page)).toString(), {
          credentials: 'same-origin'
        });
        var json = await res.json();
        if (req !== logReq) return;            // a newer request superseded this one
        if (!json.ok) throw new Error(json.error || 'Could not load history');
        logApply(json);
      } catch (err) {
        if (req === logReq) toast(err.message, 'err');
      } finally {
        if (req === logReq) logPager.classList.remove('is-loading');
      }
    }

    logForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      loadLog(1);                              // a new search always restarts at page 1
    });
    ['date_from', 'date_to', 'source'].forEach(function (name) {
      var el = logForm.elements[name];
      if (el) el.addEventListener('change', function () { loadLog(1); });
    });
    logForm.elements['q'].addEventListener('input', function () {
      clearTimeout(logSearchTimer);
      logSearchTimer = setTimeout(function () { loadLog(1); }, 350);  // debounced live search
    });
    $('#logReset').addEventListener('click', function () {
      logForm.reset();                         // clear server-rendered defaults too
      ['q', 'date_from', 'date_to', 'source'].forEach(function (k) {
        if (logForm.elements[k]) logForm.elements[k].value = '';
      });
      loadLog(1);
    });
    logPrev.addEventListener('click', function () {
      if (!logPrev.disabled) loadLog(logState.page - 1);
    });
    logNext.addEventListener('click', function () {
      if (!logNext.disabled) loadLog(logState.page + 1);
    });
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
    toast(done + ' command' + (done === 1 ? '' : 's') + ' sent \u2014 devices confirm within a few seconds');
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