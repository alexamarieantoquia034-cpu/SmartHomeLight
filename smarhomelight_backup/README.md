# SmartHome Light — ESP32 + XAMPP/MySQL

A web dashboard that controls ESP32 RGB lights, backed by a MySQL database running on XAMPP.

```
┌────────────┐   POST /api/light_action.php   ┌──────────────┐      ┌────────────┐
│  Browser   │ ────────────────────────────▶ │  PHP / XAMPP │ ───▶ │   MySQL    │
│ (dashboard)│ ◀──── /api/get_state.php ──── │              │ ◀─── │ (smartlight)│
└────────────┘                               └──────────────┘      └────────────┘
                                                       ▲
                       GET  /api/device_poll.php        │ queued commands
                       POST /api/device_heartbeat.php    │
                                                  ┌─────┴──────┐
                                                  │   ESP32    │
                                                  │ RGB light  │
                                                  └────────────┘
```

```
Browser  ──POST /api/light_action.php──▶  PHP  ──▶  MySQL (light_state = DESIRED)
   ▲                                          ▲
   └── /api/get_state.php ──┘                  │ GET  /api/device_poll.php
                                        ESP32 ◀┘ (pulls DESIRED, applies to GPIO)
                                          │
                                          └──POST /api/device_heartbeat.php──▶
                                             (devices.actual_* = REAL hardware state)
```

The ESP32 sits behind a NAT/router, so it **polls** the server instead of
being pushed to.

**Desired vs actual.** `light_state` stores what you *asked for*; the
`devices.actual_*` columns store what the device *reports it is really doing*.
These are deliberately separate — if a device reported its status into the
same table, a device that was still catching up would erase your command
before it was ever delivered. The dashboard shows a sync indicator so you can
tell when the LED has actually caught up.

---

## 1. Requirements

| Component | Version used |
|-----------|--------------|
| XAMPP (Apache + PHP + MariaDB/MySQL) | PHP 8.2, MariaDB 10.4 |
| PHP extensions | `pdo_mysql`, `json`, `mbstring` (all bundled with XAMPP) |
| Arduino IDE + ESP32 board package | For the light controller |

---

## 2. Installation

1. **Start XAMPP** — launch **Apache** and **MySQL** in the Control Panel.
2. **Run the installer:**
   ```
   http://localhost/smarhomelight_backup/install.php
   ```
   This creates the `smartlight` database, all 8 tables, an admin account and
   one demo device. It is safe to run more than once (all statements are
   `IF NOT EXISTS`).
3. **Sign in** at `http://localhost/smarhomelight_backup/login.php`

   | Username | Password |
   |----------|----------|
   | `admin`  | `admin123` |

4. **Delete `install.php`** once you are done.

If the installer cannot connect, edit `includes/config.php` and match your
MySQL credentials (XAMPP defaults to `root` with an empty password).

---

## 3. Connect your ESP32

### 3.1 Register the device
Open the **Devices** page, click **+ Add device**, name it, and copy the
generated **device key**.

### 3.2 Flash the firmware
Open `esp32/smart_light/smart_light.ino` in the Arduino IDE. **No extra
libraries are needed** — the sketch parses the JSON by hand, so there is
nothing to install.

> Arduino requires the sketch folder name to match the `.ino` file name.
> Keep it as `esp32\smart_light\smart_light.ino`. The firmware drives the relay
> with `digitalWrite()` and reads the LDR with `analogRead()`, so it compiles on
> both ESP32 Arduino core 2.x and 3.x.

Settings to check:

```cpp
const char* SERVER_HOST   = "192.168.1.7";    // THIS PC's LAN IP (ipconfig)
const char* DEVICE_KEY    = "...";            // from esp32/device_key.txt
const uint8_t PIN_RELAY   = 26;               // D26 - relay IN pin
const bool    RELAY_ACTIVE_LOW = true;        // false if your relay is backwards
const uint8_t PIN_LDR     = 34;               // D34 - LDR analog input
const bool    AUTO_LIGHT  = true;             // dark -> ON, bright -> OFF
```

- `SERVER_HOST` must be the PC's **LAN IP**, not `localhost` — the ESP32
  cannot resolve your PC's own `localhost`.
- Both devices must be on the same Wi-Fi network. The ESP32 only supports
  **2.4 GHz**, so a 5 GHz-only network will never appear.
- Open the Serial Monitor at **115200** baud.
- Check the banner line `Firmware: 2.1.0   built: ...`. If it does not say
  `2.1.0`, the board is still running an older binary — press Upload.

#### Serial console

Type these in the Serial Monitor input line and press Enter:

| Command | What it does |
|---------|--------------|
| `scan` | Lists every Wi-Fi network in range, flagging your target with `>>` |
| `wifi` | Forces an immediate reconnect (skips the 15 s retry rate limit) |
| `status` | Shows IP, gateway, RSSI, MAC, light state, poll interval + LDR reading |
| `ldr` | Prints the current LDR raw value, %, dark/bright verdict + learned range |
| `cal` | Watches the live sensor for 6 s — cover/uncover to see it flip dark/bright |
| `test` | Clicks the relay 3× so you can hear/see it switch |
| `help` | Lists the commands |

If `scan` shows your network as `>>` but joining still fails, the name is
correct and the **password** is wrong. If the network is missing entirely,
the name is wrong, the AP is 5 GHz, or the board is out of range.

### 3.3 Wiring

**Relay (the light switch)** — relay `IN` to **GPIO 26 / D26**:

| ESP32 | Relay module |
|-------|--------------|
| GPIO 26 (D26) | IN |
| GND           | GND |
| 5V (VIN)      | VCC |

Most relay boards are **active-LOW** (they switch when the pin goes LOW), which
the firmware assumes via `RELAY_ACTIVE_LOW = true`. If the light is on when it
should be off, set that to `false`. Drive the mains/lamp through the relay's
normally-open contacts and its own supply — never power the lamp from the ESP32.

**LDR light sensor** — wired as a voltage divider into **GPIO 34 / D34**:

| ESP32 | LDR divider |
|-------|-------------|
| GPIO 34 (D34) | junction of LDR + 10 kΩ resistor |
| 3V3           | other leg of the LDR |
| GND           | other leg of the 10 kΩ resistor |

GPIO 34 is input-only on the ESP32, so it needs no pull-up. The firmware
**auto-calibrates**: it learns the sensor's dark/bright range from live min/max
and switches at the midpoint, so the exact ADC numbers don't matter — only the
direction the reading moves when you cover it. Use the `cal` command to watch it.

**Automatic mode** — `AUTO_LIGHT = true` (the default) makes the relay follow the
room: light turns **ON when dark** (sensor covered), **OFF when bright** (sensor
uncovered). The ESP32 reports each change so the **dashboard mirrors it
automatically**. A manual tap on the website pauses AUTO for `MANUAL_OVERRIDE_MS`
(60 s) so your click is not instantly undone, then AUTO resumes.

If covering the sensor makes the light behave backwards (goes bright when you
cover it), set `LDR_INVERT = true` and reflash. The `cal` command prints the
learned range so you can confirm which way your sensor swings.

---

## 4. Using the dashboard

- **Dashboard** — one card per light: on/off, brightness slider, colour picker
  and presets. The page refreshes every 4 seconds, so you see the real
  hardware state (not just the requested one).
- **Devices** — add/remove units, copy device keys.
- **Schedules** — time-based on/off with day-of-week selection and a
  **Run now** button for testing.
- **History** — daily energy chart and the activity log.

---

## 5. Making schedules run automatically

Schedules fire from `cron/run_schedules.php`. Run it once a minute:

**Windows — Task Scheduler**
- Create a task that runs **every 1 minute**.
- Program: `C:\xampp\php\php.exe`
- Arguments: `"C:\xampp\htdocs\smarhomelight_backup\cron\run_schedules.php"`
- Start in: `C:\xampp\htdocs\smarhomelight_backup`

**Linux / macOS — crontab**
```
* * * * * /usr/bin/php /path/to/smarhomelight_backup/cron/run_schedules.php
```

The script is idempotent: a schedule will not fire twice within the same
minute.
---

## 6. Files

```
smarhomelight_backup/
├── index.php              Dashboard
├── devices.php            Device registration + keys
├── schedules.php          Timer automation
├── history.php            Energy chart + activity log
├── login.php / logout.php Authentication
├── install.php            One-click DB installer (delete after use)
├── includes/
│   ├── config.php         DB credentials, timezone, tuning values
│   ├── db.php             PDO connection + timezone bootstrap
│   ├── auth.php           Sessions, password hashing, CSRF
│   └── functions.php      Shared helpers, state-change API
├── api/
│   ├── light_action.php   Web → set light state (CSRF protected)
│   ├── get_state.php      Live state for the dashboard
│   ├── device_poll.php    ESP32 pulls commands + sends state
│   ├── device_heartbeat.php  ESP32 confirms real hardware state
│   ├── device_action.php  Add / delete devices
│   └── schedule_action.php  Schedule CRUD
├── cron/run_schedules.php Minute-by-minute scheduler
├── database/schema.sql    Full schema
├── assets/                style.css, app.js
└── esp32/smart_light/smart_light.ino  ESP32 firmware (no libraries needed)
```

---

## 7. API reference

### `GET /api/device_poll.php` — ESP32 pulls work
Authenticates with `key`, refreshes liveness, optionally reports state, and
returns queued commands plus the authoritative desired state.

```
?key=DEVICE_KEY&is_on=1&brightness=80&r=255&g=214&b=175&mode=white&rssi=-55
```
```json
{
  "ok": true,
  "commands": [ { "id": 1, "command": "set_state",
                  "payload": { "is_on": 1, "brightness": 100,
                               "color": [255,214,175], "mode": "white" } } ],
  "desired": { "is_on": 1, "brightness": 100,
               "color": [255,214,175], "mode": "white" },
  "poll_interval": 5
}
```

### `POST /api/device_heartbeat.php` — ESP32 confirms reality
Accepts JSON. Records telemetry, marks the light online and starts energy
accounting on an off → on transition.

### `POST /api/light_action.php` — Web control
Requires a session and a CSRF token.
`action` = `on` | `off` | `toggle` | `brightness` | `color` | `mode`

---

## 8. Security notes

- Passwords are hashed with `password_hash()` (bcrypt); sessions are
  `HttpOnly` + `SameSite=Lax` and the ID is regenerated on login.
- All web mutations require a CSRF token.
- **Every SQL statement uses PDO prepared statements** — no string-built queries.
- Device endpoints authenticate with a per-device 128-bit key; an unknown key
  is rejected with `401`.
- Output is escaped via `e()` (htmlspecialchars).

Before exposing this beyond your LAN:
1. Change the admin password.
2. Set `'cron_token'` in `includes/config.php` to a random string.
3. Give MySQL a real root password and update `includes/config.php`.
4. Delete `install.php`.
5. Serve over HTTPS if you must reach it from outside your network.

---

## 9. Troubleshooting

| Problem | Fix |
|---------|-----|
| "Database connection failed" | Start MySQL in XAMPP; check `includes/config.php` |
| Blank / white page | Temporarily enable `display_errors` in `php.ini` |
| Device shows **Offline** | Check `SERVER_HOST` is your LAN IP and both are on the same Wi-Fi |
| `wifi:sta is connecting, cannot set config` in the log | Old firmware; upload the current `smart_light.ino` |
| Wi-Fi will not join | Type `scan` in the Serial Monitor — `>>` means the name is right and the password is wrong |
| Network invisible to the ESP32 | The AP is 5 GHz only, or the board is out of range |
| Lights change but ESP32 ignores them | Confirm the device key matches; watch the Serial Monitor |
| Schedules never fire | Add the Task Scheduler job (step 5) |
| Colours look inverted | Common-cathode strip — swap `OFF_LEVEL` / `ON_LEVEL` |