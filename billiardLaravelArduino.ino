#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

// ==============================================================================
// 1. KONFIGURASI WIFI & SERVER
// ==============================================================================
const char* ssid     = "LPRON";       // Ganti dengan SSID WiFi yang digunakan
const char* password = "LPRON2025";   // Ganti dengan Password WiFi

// Mode Polling:
// true  = BATCH POLLING (1x request untuk 10 meja, cepat, hemat memori & stabil)
// false = INDIVIDUAL TABLE POLLING (1 per 1 ke /api/microcontroller/table/{id}/light)
const bool USE_BATCH_POLLING = true;

// URL Endpoint
const String baseUrlTable = "https://billiard-system.azhr.cloud/api/microcontroller/table/";
const String urlBatch     = "https://billiard-system.azhr.cloud/api/microcontroller/tables/light";

// ==============================================================================
// 2. HARDWARE & RELAY CONFIGURATION
// ==============================================================================
LiquidCrystal_I2C lcd(0x27, 16, 2);

// Pin Relay per meja (Indeks 0 = Meja 1, Indeks 9 = Meja 10)
const int ledPins[]   = {27, 26, 25, 19, 18, 5, 17, 16, 4, 15};
const int buzzer      = 13;
const int totalTables = 10;

// POLARITAS RELAY:
// false = Modul Relay 5V Standar (Active-LOW: Nyala = LOW, Mati = HIGH)
// true  = Modul Relay Active-HIGH (Nyala = HIGH, Mati = LOW)
const bool RELAY_ACTIVE_HIGH = false;

// Interval Polling (5000ms = 5 detik)
unsigned long lastCheckTime = 0;
const unsigned long checkInterval = 5000;

// State lokal lampu meja
bool tableStates[totalTables] = {false};

// ==============================================================================
// PROTOTYPE FUNCTION
// ==============================================================================
void checkBatchTables();
void checkIndividualTables();
void applyRelay(int tableId, bool lightOn);
void updateLcd();
void alarm();

// ==============================================================================
// SETUP
// ==============================================================================
void setup() {
  Serial.begin(115200);
  lcd.init();
  lcd.backlight();
  pinMode(buzzer, OUTPUT);

  // Inisialisasi pin relay (Pastikan kondisi awal lampu MATI)
  for (int i = 0; i < totalTables; i++) {
    pinMode(ledPins[i], OUTPUT);
    digitalWrite(ledPins[i], RELAY_ACTIVE_HIGH ? LOW : HIGH);
  }

  // Koneksi WiFi
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);
  lcd.setCursor(0, 0); lcd.print("MENCARI KONEKSI");
  lcd.setCursor(0, 1); lcd.print("     WIFI !");

  int retries = 0;
  while (WiFi.status() != WL_CONNECTED && retries < 30) {
    alarm();
    delay(500);
    Serial.print(".");
    retries++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[WIFI] Terkoneksi!");
    Serial.printf("[WIFI] IP: %s\n", WiFi.localIP().toString().c_str());

    lcd.clear();
    lcd.setCursor(0, 0); lcd.print("WIFI TERKONEKSI");
    lcd.setCursor(0, 1); lcd.print(WiFi.localIP().toString());
    delay(1500);
  } else {
    Serial.printf("\n[WIFI ERROR] Status Code: %d\n", WiFi.status());
    lcd.clear();
    lcd.setCursor(0, 0); lcd.print("WIFI GAGAL!");
    delay(1500);
  }

  lcd.clear();
  lcd.setCursor(0, 0); lcd.print("BILLIARD READY  ");
  updateLcd();

  // Sinkronisasi awal langsung saat boot
  if (WiFi.status() == WL_CONNECTED) {
    if (USE_BATCH_POLLING) {
      checkBatchTables();
    } else {
      checkIndividualTables();
    }
  }
}

// ==============================================================================
// MAIN LOOP
// ==============================================================================
void loop() {
  // Polling berkala setiap 5 detik
  if (millis() - lastCheckTime >= checkInterval) {
    lastCheckTime = millis();

    if (WiFi.status() == WL_CONNECTED) {
      if (USE_BATCH_POLLING) {
        checkBatchTables();
      } else {
        checkIndividualTables();
      }
    } else {
      Serial.println("[WIFI] Terputus, mencoba koneksi ulang...");
      WiFi.reconnect();
    }
  }
}

// ==============================================================================
// 1. REKOMENDASI UTAMA: BATCH POLLING (1 Request untuk 10 Meja)
// ==============================================================================
void checkBatchTables() {
  WiFiClientSecure client;
  client.setInsecure(); // Wajib agar lolos SSL Cloudflare Edge

  HTTPClient http;
  http.setTimeout(3500);

  if (!http.begin(client, urlBatch)) {
    Serial.println("[HTTP] Inisialisasi batch gagal");
    return;
  }

  int httpCode = http.GET();
  if (httpCode == 200) {
    String payload = http.getString();
    StaticJsonDocument<2048> doc;
    DeserializationError error = deserializeJson(doc, payload);

    if (!error && doc.containsKey("data")) {
      JsonArray array = doc["data"].as<JsonArray>();
      bool changed = false;

      for (JsonObject obj : array) {
        int tableId  = obj["table_id"] | 0;
        bool lightOn = obj["light_on"] | false;

        if (tableId >= 1 && tableId <= totalTables) {
          int idx = tableId - 1;
          if (tableStates[idx] != lightOn) {
            tableStates[idx] = lightOn;
            applyRelay(tableId, lightOn);
            changed = true;
            Serial.printf("[BATCH] Meja %d -> %s\n", tableId, lightOn ? "ON" : "OFF");
          }
        }
      }

      if (changed) {
        updateLcd();
        alarm();
      }
    }
  } else {
    Serial.printf("[HTTP ERROR] Batch code: %d\n", httpCode);
  }

  http.end();
}

// ==============================================================================
// 2. VERSI ALTERNATIF: INDIVIDUAL TABLE POLLING (/table/{id}/light)
// ==============================================================================
void checkIndividualTables() {
  WiFiClientSecure client;
  client.setInsecure(); // Wajib agar lolos SSL Cloudflare Edge

  bool changed = false;

  for (int i = 1; i <= totalTables; i++) {
    String fullUrl = baseUrlTable + String(i) + "/light";
    HTTPClient http;
    http.setTimeout(2500);

    if (http.begin(client, fullUrl)) {
      int httpResponseCode = http.GET();

      if (httpResponseCode == 200) {
        String payload = http.getString();
        StaticJsonDocument<256> doc;
        DeserializationError error = deserializeJson(doc, payload);

        if (!error) {
          bool lightOn = doc["light_on"] | false;
          int tableId  = doc["table_id"] | i;

          if (tableId >= 1 && tableId <= totalTables) {
            int idx = tableId - 1;
            if (tableStates[idx] != lightOn) {
              tableStates[idx] = lightOn;
              applyRelay(tableId, lightOn);
              changed = true;
              Serial.printf("[TABLE %d] Light -> %s\n", tableId, lightOn ? "ON" : "OFF");
            }
          }
        }
      } else {
        Serial.printf("[TABLE %d] Error code: %d\n", i, httpResponseCode);
      }
      http.end();
    }
    delay(40); // Jeda kecil antar meja
  }

  if (changed) {
    updateLcd();
    alarm();
  }
}

// ==============================================================================
// KONTROL FISIK RELAY
// ==============================================================================
void applyRelay(int tableId, bool lightOn) {
  int pin = ledPins[tableId - 1];

  if (RELAY_ACTIVE_HIGH) {
    digitalWrite(pin, lightOn ? HIGH : LOW);
  } else {
    // Active-LOW: LOW = Relay Aktif (Menyala), HIGH = Relay Padam
    digitalWrite(pin, lightOn ? LOW : HIGH);
  }
}

// ==============================================================================
// UPDATE TAMPILAN LCD (16x2)
// ==============================================================================
void updateLcd() {
  lcd.setCursor(0, 1);
  for (int i = 0; i < totalTables; i++) {
    lcd.print(tableStates[i] ? "1" : "0");
  }
}

// ==============================================================================
// BUZZER NOTIFIKASI
// ==============================================================================
void alarm() {
  digitalWrite(buzzer, HIGH);
  delay(40);
  digitalWrite(buzzer, LOW);
}
