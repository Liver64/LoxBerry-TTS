# LoxBerry Text2Speech

[![LoxBerry Plugin](https://img.shields.io/badge/LoxBerry-Plugin-blue)](https://www.loxberry.de/)
[![GitHub release](https://img.shields.io/github/v/release/Liver64/LoxBerry-TTS?include_prereleases)](https://github.com/Liver64/LoxBerry-TTS/releases)
[![GitHub issues](https://img.shields.io/github/issues/Liver64/LoxBerry-TTS)](https://github.com/Liver64/LoxBerry-TTS/issues)
[![License](https://img.shields.io/github/license/Liver64/LoxBerry-TTS)](https://github.com/Liver64/LoxBerry-TTS)

Text2Speech ist ein Plugin für [LoxBerry](https://www.loxberry.de/) zur zentralen Erzeugung von Sprachausgaben (Text-to-Speech).

Das Plugin unterstützt verschiedene TTS-Engines, konfigurierbare Sprachen und Stimmen, MP3-Caching, lokale Audioausgabe sowie eine MQTT-Schnittstelle, über die andere LoxBerry-Plugins Sprachausgaben anfordern können.

---

## Funktionen

- Zentrale Text-to-Speech-Schnittstelle für LoxBerry
- Unterstützung verschiedener TTS-Engines
- Konfigurierbare Sprachen und Stimmen
- Erzeugung und Caching von MP3-Dateien
- Lokale Audioausgabe
- Test-Sprachausgabe direkt aus dem Webinterface
- MQTT-Schnittstelle für andere LoxBerry-Plugins
- Validierung eingehender MQTT-Anfragen
- Eigener MQTT-Subscriber-Service
- Automatischer Reconnect zum LoxBerry MQTT-Broker
- Zusätzliche TTS-Funktionen für:
  - Wetter
  - Uhrzeit
  - Wetterwarnungen
  - Pollenflug
  - Abfallkalender
  - Entfernung / Fahrzeit
- Kompatibel mit aktuellen LoxBerry-Versionen sowie älteren jQuery-Mobile-basierten Installationen

---

## Installation

Die aktuelle Version kann über die GitHub-Releases heruntergeladen werden:

**[LoxBerry-TTS Releases](https://github.com/Liver64/LoxBerry-TTS/releases)**

Das ZIP-Archiv anschließend über die LoxBerry Pluginverwaltung installieren.

Nach der Installation kann Text2Speech über das Webinterface konfiguriert werden. Dort werden unter anderem die gewünschte TTS-Engine, Sprache, Stimme und weitere Optionen festgelegt.

---

## MQTT Plugin-Interface

Text2Speech stellt anderen LoxBerry-Plugins eine MQTT-Schnittstelle zur Verfügung.

Dabei wird ausschließlich der **standardmäßig in LoxBerry konfigurierte MQTT-Broker** verwendet.

Es werden keine zusätzlichen Mosquitto-Listener, MQTT-Bridges, TLS-Bundles oder plugin-spezifischen Broker-Konfigurationen benötigt.

Der MQTT-Subscriber von Text2Speech läuft als eigener systemd-Service:

```text
mqtt-service-tts.service
```

Die Subscriber-Implementierung befindet sich unter:

```text
/opt/loxberry/bin/plugins/text2speech/mqtt/mqtt-subscribe.php
```

Der Subscriber lauscht auf:

```text
tts-publish/#
```

### Empfohlenes Request-Topic

```text
tts-publish/<client>/<corr>
```

Beispiel:

```text
tts-publish/myplugin/7f60f8e9
```

### Response-Topic

Für jede Anfrage sollte über `reply_to` ein eindeutiges Antwort-Topic angegeben werden:

```text
tts-subscribe/<client>/<corr>
```

Beispiel:

```text
tts-subscribe/myplugin/7f60f8e9
```

Ein eindeutiges `reply_to` wird insbesondere dann empfohlen, wenn mehrere Anfragen parallel verarbeitet werden können.

---

## Beispiel einer MQTT-Anfrage

```json
{
  "client": "myplugin",
  "corr": "7f60f8e9",
  "reply_to": "tts-subscribe/myplugin/7f60f8e9",
  "text": "Dies ist eine Testausgabe",
  "nocache": 0,
  "logging": 1,
  "mp3files": 0
}
```

Mindestens eines der folgenden Felder muss angegeben werden:

```text
text
```

oder:

```text
function
```

Eingehende Anfragen werden von Text2Speech vor der Verarbeitung validiert.

Unbekannte JSON-Keys, ungültige Datentypen und fehlerhafte Requests werden abgewiesen.

---

## Funktionsaufrufe

Anstelle eines statischen Textes kann über `function` eine der unterstützten Text2Speech-Funktionen aufgerufen werden.

Beispiel:

```json
{
  "client": "myplugin",
  "reply_to": "tts-subscribe/myplugin/weather-001",
  "function": "weather",
  "logging": 1
}
```

Unterstützte Funktionen:

| Funktion | Beschreibung |
|---|---|
| `weather` | Wetterinformationen |
| `clock` | Aktuelle Uhrzeit |
| `warning` | Wetterwarnungen |
| `pollen` | Pollenflugvorhersage |
| `abfall` | Abfallkalender |
| `distance` | Entfernung / Fahrzeit |

---

## Antwort

Nach erfolgreicher Verarbeitung sendet Text2Speech eine JSON-Antwort auf das angegebene `reply_to`-Topic.

Eine erfolgreiche Anfrage enthält:

```json
{
  "status": "done"
}
```

Abhängig von der Anfrage und der Text2Speech-Konfiguration können weitere Informationen zurückgegeben werden, beispielsweise die erzeugte Audiodatei, vorhandene MP3-Dateien oder Log-Ausgaben.

Bei einem Fehler wird eine Antwort mit:

```json
{
  "status": "error"
}
```

und einer entsprechenden Fehlermeldung zurückgegeben.

---

## Schutz vor doppelten Anfragen

Text2Speech besitzt einen Schutz gegen mehrfach eingehende identische MQTT-Anfragen.

Ein byte-identischer MQTT-Payload, der innerhalb von ungefähr **25 Sekunden** erneut empfangen wird, wird nicht noch einmal verarbeitet.

Dadurch werden doppelte Sprachausgaben durch wiederholt eintreffende MQTT-Nachrichten verhindert.

---

## Hinweise für Entwickler

Andere LoxBerry-Plugins können Text2Speech als zentrale TTS-Schnittstelle verwenden.

### Empfohlener Ablauf

1. Die in LoxBerry konfigurierte MQTT-Verbindung verwenden.
2. Eine eindeutige Request-/Correlation-ID erzeugen.
3. Ein eindeutiges Response-Topic abonnieren:

   ```text
   tts-subscribe/<client>/<corr>
   ```

4. Dieses Topic im JSON-Payload als `reply_to` angeben.
5. Die Anfrage veröffentlichen unter:

   ```text
   tts-publish/<client>/<corr>
   ```

6. Auf die Antwort unter `reply_to` warten.
7. Den zurückgegebenen `status` auswerten.
8. Die erzeugte Audiodatei bzw. das konfigurierte Text2Speech-Interface verwenden.

### Wichtig

Plugins, die das Text2Speech-Interface verwenden, sollen **keine Änderungen an der Mosquitto-Systemkonfiguration vornehmen**.

Insbesondere müssen keine Dateien oder Konfigurationen unter:

```text
/etc/mosquitto/conf.d/
/etc/mosquitto/ca/
/etc/mosquitto/certs/
```

für die Kommunikation mit Text2Speech angelegt werden.

Es wird weder ein eigener Text2Speech-MQTT-Listener noch eine MQTT-Bridge benötigt.

MQTT-Sicherheit, Authentifizierung und Broker-Konfiguration werden ausschließlich über die zentrale LoxBerry MQTT-Konfiguration verwaltet.

### Developer Guide

Eine ausführliche Entwicklerdokumentation zum MQTT Request-/Response-Protokoll, zur Validierung, zu den unterstützten Funktionen, zur Service-Architektur und zur Fehlersuche ist Bestandteil des Projekts.

---

## MQTT Subscriber Service

Status des Subscribers prüfen:

```bash
sudo systemctl status mqtt-service-tts.service
```

Subscriber neu starten:

```bash
sudo systemctl restart mqtt-service-tts.service
```

Service-Log verfolgen:

```bash
journalctl -u mqtt-service-tts.service -f
```

Bei einer Unterbrechung der MQTT-Verbindung stellt der Subscriber die Verbindung automatisch wieder her.

---

## Fehlersuche

Werden Text2Speech-Anfragen eines anderen Plugins nicht verarbeitet, sollte zunächst der Subscriber-Service geprüft werden:

```bash
systemctl status mqtt-service-tts.service
```

Anschließend können die Service-Logs kontrolliert werden:

```bash
journalctl -u mqtt-service-tts.service
```

Zusätzlich sollte geprüft werden, ob der LoxBerry MQTT-Broker läuft und das aufrufende Plugin die korrekten Request- und Response-Topics verwendet.

---

## Updates

Neue Versionen stehen auf der GitHub-Releases-Seite zur Verfügung:

**[Releases](https://github.com/Liver64/LoxBerry-TTS/releases)**

Fehler und Verbesserungsvorschläge können über GitHub Issues gemeldet werden:

**[GitHub Issues](https://github.com/Liver64/LoxBerry-TTS/issues)**

---

## Lizenz

Informationen zur Lizenz befinden sich in der Datei `LICENSE` im Repository.

---

## Projekt

**LoxBerry Text2Speech**

Repository:

```text
https://github.com/Liver64/LoxBerry-TTS
```