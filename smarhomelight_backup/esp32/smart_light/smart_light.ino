#include <WiFi.h>
#include <HTTPClient.h>

// ==================== CONFIG ====================
const char* WIFI_SSID = "gu";
const char* WIFI_PASS = "12345678";

const char* SERVER_HOST = "192.168.137.14";
const int   SERVER_PORT = 80;
const char* BASE_PATH   = "/smarhomelight_backup";
const char* DEVICE_KEY  = "b377d2261f0ffa8679d54301120659cf";
const char* FIRMWARE_VER = "2.6.2";

// ---- Pins ----
const uint8_t PIN_RELAY = 26;
const uint8_t PIN_LDR   = 34;

// ---- LDR / threshold ----
// ⚠️ BUHATA: type `calibrate` sa Serial Monitor, then i-set ni
//    base sa gi-print nga suggested values.
const int LDR_THRESHOLD  = 1200;
const int LDR_HYSTERESIS = 150;

// true  => LOW  ADC value means DARK
// false => HIGH ADC value means DARK
const bool LDR_DARK_IS_LOW = false;

// true  => relay active-LOW (LOW = bulb ON)
const bool RELAY_ACTIVE_LOW = true;

// ---- Timing ----
const unsigned long LDR_INTERVAL_MS  = 500;
const unsigned long POLL_INTERVAL_MS = 5000;
const unsigned long HB_INTERVAL_MS   = 15000;
const unsigned long WIFI_RETRY_MS    = 10000;
const int           HTTP_TIMEOUT_MS  = 4000;

// ==================== STATE ====================
int    g_ldr        = 0;
bool   g_dark       = false;
bool   g_lightOn    = false;
int    g_brightness = 100;
String g_opMode     = "auto";
String g_modeDirty  = "";

unsigned long tLdr = 0, tPoll = 0, tHb = 0, tWifi = 0;
bool g_heartbeatPending = false;

// ==================== RELAY ====================
inline int relayOnLevel()  { return RELAY_ACTIVE_LOW ? LOW  : HIGH; }
inline int relayOffLevel() { return RELAY_ACTIVE_LOW ? HIGH : LOW;  }

void applyRelay(bool on) {
  digitalWrite(PIN_RELAY, on ? relayOnLevel() : relayOffLevel());
}

void setLight(bool on, int brightness, bool fromAuto) {
  if (on == g_lightOn && brightness == g_brightness && !fromAuto) return;
  if (on != g_lightOn) {
    g_lightOn = on;
    applyRelay(on);
  }
  g_brightness = brightness;
}

// ==================== URL / WiFi HELPERS ====================
String baseUrl() {
  String u = "http://";
  u += SERVER_HOST;
  if (SERVER_PORT != 80) { u += ":"; u += String(SERVER_PORT); }
  u += BASE_PATH;
  return u;
}

const char* wifiStatusName(wl_status_t s) {
  switch (s) {
    case WL_IDLE_STATUS:     return "IDLE";
    case WL_NO_SSID_AVAIL:   return "NO_SSID";
    case WL_SCAN_COMPLETED:  return "SCAN_COMPLETED";
    case WL_CONNECTED:       return "CONNECTED";
    case WL_CONNECT_FAILED:  return "CONNECT_FAILED";
    case WL_CONNECTION_LOST: return "CONNECTION_LOST";
    case WL_DISCONNECTED:    return "DISCONNECTED";
    default:                 return "UNKNOWN";
  }
}

void ensureWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  unsigned long now = millis();
  if (now - tWifi < WIFI_RETRY_MS) return;
  tWifi = now;

  wl_status_t st = WiFi.status();
  Serial.printf("[WiFi] retry (last=%d %s)\n", (int)st, wifiStatusName(st));

  WiFi.disconnect(false);
  delay(20);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
}

void doPoll();
void doHeartbeat(bool force);

// ==================== SETUP ====================
void setup() {
  Serial.begin(115200);
  delay(300);

  pinMode(PIN_RELAY, OUTPUT);
  pinMode(PIN_LDR, INPUT);
  applyRelay(false);
  g_lightOn = false;

  analogReadResolution(12);
  analogSetAttenuation(ADC_11db);

  Serial.println();
  Serial.println("========================================");
  Serial.println(" Smart Home Light - Living Room");
  Serial.printf(" Firmware: %s\n", FIRMWARE_VER);
  Serial.printf(" Mode: %s\n", g_opMode.c_str());
  Serial.println("========================================");
  Serial.printf(" LDR pin: GPIO %u  Relay pin: GPIO %u\n", PIN_LDR, PIN_RELAY);
  Serial.printf(" Relay active: %s\n", RELAY_ACTIVE_LOW ? "LOW (LOW=ON)" : "HIGH (HIGH=ON)");
  Serial.printf(" LDR polarity: %s\n", LDR_DARK_IS_LOW ? "LOW=dark" : "HIGH=dark");
  Serial.printf(" Threshold=%d +/- %d  (deadband %d..%d)\n",
                LDR_THRESHOLD, LDR_HYSTERESIS,
                LDR_THRESHOLD - LDR_HYSTERESIS,
                LDR_THRESHOLD + LDR_HYSTERESIS);

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  Serial.printf("[WiFi] connecting to %s ...\n", WIFI_SSID);

  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 8000) {
    delay(250); Serial.print(".");
  }
  Serial.println();
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[WiFi] connected, IP: %s\n", WiFi.localIP().toString().c_str());
  } else {
    Serial.println("[WiFi] not connected yet - LDR auto still runs offline.");
  }
  Serial.println("Commands: status | on | off | auto | manual | schedule");
  Serial.println("          calibrate | testldr | scan");
}

// ==================== MAIN LOOP ====================
void loop() {
  unsigned long now = millis();

  // ---- 1) LDR sample every 500 ms ----
  if (now - tLdr >= LDR_INTERVAL_MS) {
    tLdr = now;

    long sum = 0;
    for (int i = 0; i < 8; i++) { sum += analogRead(PIN_LDR); delay(4); }
    g_ldr = (int)(sum / 8);

    int hi = LDR_THRESHOLD + LDR_HYSTERESIS;
    int lo = LDR_THRESHOLD - LDR_HYSTERESIS;

    // Hysteresis
    if (LDR_DARK_IS_LOW) {
      if (g_dark) g_dark = (g_ldr < hi);
      else        g_dark = (g_ldr < lo);
    } else {
      if (g_dark) g_dark = (g_ldr > lo);
      else        g_dark = (g_ldr > hi);
    }

    // ---- MODE ROUTER ----
    if (g_opMode != "auto") {
      Serial.printf("[LDR] %d %s | mode=%s (LDR ignored) | relay=%s | wifi=%s\n",
                    g_ldr, g_dark ? "DARK" : "BRIGHT",
                    g_opMode.c_str(),
                    g_lightOn ? "ON" : "OFF",
                    WiFi.status() == WL_CONNECTED ? "UP" : "DOWN");
      return;
    }

    // ---- AUTO: dark => ON, bright => OFF ----
    bool want = g_dark;
    if (want != g_lightOn) {
      setLight(want, 100, true);
      Serial.printf("[AUTO] %d %s -> relay %s\n",
                    g_ldr, g_dark ? "DARK" : "BRIGHT",
                    want ? "ON" : "OFF");
      if (WiFi.status() == WL_CONNECTED) g_heartbeatPending = true;
    }

    // Range warning para mahibalo ka kung wiring issue
    const char* warn = "";
    if (g_ldr < 100)       warn = " ⚠️ reading sobrang ubos — check wiring (short to GND?)";
    else if (g_ldr > 4000) warn = " ⚠️ reading sobrang taas — check wiring (floating?)";

    Serial.printf("[LDR] %d %s | thr=%d..%d | relay=%s (GPIO%d=%d) | wifi=%s%s\n",
                  g_ldr, g_dark ? "DARK" : "BRIGHT",
                  lo, hi,
                  g_lightOn ? "ON" : "OFF",
                  PIN_RELAY, digitalRead(PIN_RELAY),
                  WiFi.status() == WL_CONNECTED ? "UP" : "DOWN",
                  warn);
  }

  // ---- 2) WiFi reconnect ----
  ensureWifi();

  // ---- 3) Deferred heartbeat ----
  if (g_heartbeatPending && WiFi.status() == WL_CONNECTED) {
    g_heartbeatPending = false;
    doHeartbeat(true);
  }

  // ---- 4) Poll every 5 s ----
  if (now - tPoll >= POLL_INTERVAL_MS) {
    tPoll = now;
    if (WiFi.status() == WL_CONNECTED) doPoll();
  }

  // ---- 5) Heartbeat every 15 s ----
  if (now - tHb >= HB_INTERVAL_MS) {
    tHb = now;
    if (WiFi.status() == WL_CONNECTED) doHeartbeat(false);
  }

  handleSerial();
  yield();
}

// ==================== TEST BLINK ====================
void doBlink() {
  Serial.println("[TEST] blinking relay");
  bool was = g_lightOn;
  int  bri = g_brightness;
  for (int i = 0; i < 4; i++) {
    setLight(!was, 100, false); delay(300);
    setLight(was, bri, false);  delay(300);
  }
  setLight(was, bri, false);
}

// ==================== POLL ====================
void doPoll() {
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  String url = baseUrl() + "/api/device_poll.php?key=" + String(DEVICE_KEY)
    + "&ldr=" + String(g_ldr)
    + "&room_dark=" + String(g_dark ? 1 : 0)
    + "&rssi=" + String(WiFi.RSSI())
    + "&ip=" + WiFi.localIP().toString();

  if (!http.begin(url)) { Serial.println("[POLL] begin failed"); return; }
  int code = http.GET();
  if (code != 200) {
    Serial.printf("[POLL] HTTP %d\n", code);
    http.end();
    return;
  }
  String body = http.getString();
  http.end();

  int di = body.indexOf("\"desired\"");

  int mi = body.indexOf("\"op_mode\"");
  if (mi >= 0) {
    String prevMode = g_opMode;
    if      (body.indexOf("schedule", mi) == mi + 11) g_opMode = "schedule";
    else if (body.indexOf("manual",   mi) == mi + 11) g_opMode = "manual";
    else if (body.indexOf("auto",     mi) == mi + 11) g_opMode = "auto";
    if (g_opMode != prevMode) {
      Serial.printf("[MODE] %s -> %s\n", prevMode.c_str(), g_opMode.c_str());
      g_heartbeatPending = true;
    }
  }

  if (body.indexOf("\"command\":\"test\"") >= 0) {
    doBlink();
    g_heartbeatPending = true;
  }

  if (g_opMode == "auto") return;
  if (di < 0) return;

  int io = body.indexOf("\"is_on\"", di);
  if (io < 0) return;
  int colon = body.indexOf(":", io);
  int want = body.substring(colon + 1).toInt();

  int bri = g_brightness;
  int bi = body.indexOf("\"brightness\"", di);
  if (bi >= 0) {
    int c2 = body.indexOf(":", bi);
    bri = body.substring(c2 + 1).toInt();
    if (bri < 0) bri = 0;
    if (bri > 100) bri = 100;
  }

  bool hasCmd = body.indexOf("\"commands\":[]") < 0
             && body.indexOf("\"commands\": []") < 0;

  if (want == 0 || want == 1) {
    bool wOn = (want == 1);
    if (wOn != g_lightOn || hasCmd) {
      setLight(wOn, bri, false);
      Serial.printf("[POLL] -> %s\n", wOn ? "ON" : "OFF");
      g_heartbeatPending = true;
    }
  }
}

// ==================== HEARTBEAT ====================
void doHeartbeat(bool force) {
  static unsigned long lastOk = 0;
  if (!force && millis() - lastOk < 5000) return;

  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  String url = baseUrl() + "/api/device_heartbeat.php";
  if (!http.begin(url)) { Serial.println("[HB] begin failed"); return; }
  http.addHeader("Content-Type", "application/json");

  String j = "{";
  j += "\"key\":\"" + String(DEVICE_KEY) + "\",";
  j += "\"ldr\":" + String(g_ldr) + ",";
  j += "\"room_dark\":" + String(g_dark ? 1 : 0) + ",";
  j += "\"is_on\":" + String(g_lightOn ? 1 : 0) + ",";
  j += "\"brightness\":" + String(g_brightness) + ",";
  j += "\"r\":255,\"g\":214,\"b\":175,\"mode\":\"white\",";
  j += "\"auto\":" + String(g_opMode == "auto" ? 1 : 0) + ",";
  if (g_modeDirty.length() > 0) {
    j += "\"op_mode\":\"" + g_modeDirty + "\",";
  }
  j += "\"rssi\":" + String(WiFi.RSSI()) + ",";
  j += "\"ip\":\"" + WiFi.localIP().toString() + "\",";
  j += "\"firmware\":\"" + String(FIRMWARE_VER) + "\",";
  j += "\"free_heap\":" + String(ESP.getFreeHeap()) + ",";
  j += "\"uptime_s\":" + String(millis() / 1000);
  j += "}";

  int code = http.POST(j);
  if (code == 200) {
    lastOk = millis();
    g_modeDirty = "";
    Serial.println("[HB] ok");
  } else {
    Serial.printf("[HB] HTTP %d\n", code);
  }
  http.end();
}

// ==================== CALIBRATION HELPER ====================
void runCalibrate() {
  Serial.println();
  Serial.println("========================================");
  Serial.println(" CALIBRATION");
  Serial.println("========================================");
  Serial.println(" Tabuni ang LDR ug ablihi samtang nag-run.");
  Serial.println(" 10 seconds total — 20 samples...\n");

  int mn = 4095, mx = 0;
  for (int i = 0; i < 20; i++) {
    int v = analogRead(PIN_LDR);
    if (v < mn) mn = v;
    if (v > mx) mx = v;
    Serial.printf("  [%02d] %4d  (min=%d  max=%d)\n", i + 1, v, mn, mx);
    delay(500);
  }

  int span = mx - mn;
  Serial.println();
  Serial.println("----------------------------------------");
  Serial.printf(" MIN (brightest or darkest): %d\n", mn);
  Serial.printf(" MAX (the other extreme):    %d\n", mx);
  Serial.printf(" SPAN:                       %d\n", span);

  if (span < 200) {
    Serial.println();
    Serial.println(" ⚠️  SPAN SOBRANG GAMAY (<200)!");
    Serial.println(" Possible nga hardware issue:");
    Serial.println("  - LDR wala naka-connect sa GPIO 34");
    Serial.println("  - LDR module naggamit sa DO instead of AO pin");
    Serial.println("  - LDR mismo nadaot");
    Serial.println("  - Wire loose or short to GND");
    Serial.println(" Check ang wiring before mo-proceed.");
    Serial.println("========================================");
    return;
  }

  int suggested_thr  = (mn + mx) / 2;
  int suggested_hyst = span / 10;
  bool suggested_low = (mn < mx) ? false : true;  // dili pa ta kahibalo asa ang dark

  Serial.println();
  Serial.println("========================================");
  Serial.println(" I-SET NI SA CODE:");
  Serial.println("========================================");
  Serial.printf(" const int  LDR_THRESHOLD  = %d;\n", suggested_thr);
  Serial.printf(" const int  LDR_HYSTERESIS = %d;\n", suggested_hyst);
  Serial.println();
  Serial.println(" Unsa ang DARK? Tan-awa ang readings sa itaas:");
  Serial.println("  - Kung DARK reading = MIN → LDR_DARK_IS_LOW = true");
  Serial.println("  - Kung DARK reading = MAX → LDR_DARK_IS_LOW = false");
  Serial.printf("  (Suggested kung DARK=MIN: %s)\n",
                suggested_low ? "false" : "true");
  Serial.println("========================================");
  Serial.println();
}

// ==================== SERIAL COMMANDS ====================
void handleSerial() {
  if (!Serial.available()) return;
  String s = Serial.readStringUntil('\n');
  s.trim(); s.toLowerCase();

  if (s == "status") {
    Serial.printf("LDR=%d %s light=%s mode=%s | wifi=",
                  g_ldr, g_dark ? "DARK" : "BRIGHT",
                  g_lightOn ? "ON" : "OFF", g_opMode.c_str());
    if (WiFi.status() == WL_CONNECTED) {
      Serial.printf("UP %s rssi=%ddBm\n",
                    WiFi.localIP().toString().c_str(), WiFi.RSSI());
    } else {
      Serial.printf("DOWN %s\n", wifiStatusName(WiFi.status()));
    }
  }
  else if (s == "on") {
    g_opMode = "manual"; g_modeDirty = g_opMode;
    setLight(true, 100, false);
    Serial.println("Mode=MANUAL ON");
    if (WiFi.status() == WL_CONNECTED) doHeartbeat(true);
  }
  else if (s == "off") {
    g_opMode = "manual"; g_modeDirty = g_opMode;
    setLight(false, 0, false);
    Serial.println("Mode=MANUAL OFF");
    if (WiFi.status() == WL_CONNECTED) doHeartbeat(true);
  }
  else if (s == "auto") {
    g_opMode = "auto"; g_modeDirty = g_opMode;
    Serial.println("Mode=AUTO (LDR controls)");
    if (WiFi.status() == WL_CONNECTED) doHeartbeat(true);
  }
  else if (s == "manual") {
    g_opMode = "manual"; g_modeDirty = g_opMode;
    Serial.println("Mode=MANUAL (LDR ignored)");
    if (WiFi.status() == WL_CONNECTED) doHeartbeat(true);
  }
  else if (s == "schedule") {
    g_opMode = "schedule"; g_modeDirty = g_opMode;
    Serial.println("Mode=SCHEDULE (LDR ignored)");
    if (WiFi.status() == WL_CONNECTED) doHeartbeat(true);
  }
  else if (s == "calibrate") {
    runCalibrate();
  }
  else if (s == "testldr") {
    Serial.println();
    Serial.println("=== LDR READOUT (walay relay) ===");
    Serial.println("Tabuni / ablihi ang LDR. Type anything to stop.\n");
    for (int i = 0; i < 30; i++) {
      if (Serial.available()) { Serial.read(); break; }
      long sum = 0;
      for (int j = 0; j < 8; j++) { sum += analogRead(PIN_LDR); delay(4); }
      int v = sum / 8;
      const char* warn = "";
      if (v < 100)       warn = "  ⚠️ sobrang ubos";
      else if (v > 4000) warn = "  ⚠️ sobrang taas";
      Serial.printf("LDR = %4d%s\n", v, warn);
      delay(500);
    }
    Serial.println("=== END ===\n");
  }
  else if (s == "scan") {
    int n = WiFi.scanNetworks();
    Serial.printf("%d networks:\n", n);
    for (int i = 0; i < n; i++) {
      Serial.printf("  %s %ddBm %s\n",
                    WiFi.SSID(i).c_str(), WiFi.RSSI(i),
                    WiFi.SSID(i) == WIFI_SSID ? "<< MATCH" : "");
    }
  }
}