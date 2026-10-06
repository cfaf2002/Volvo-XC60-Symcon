# Volvo für IP-Symcon

[![IP-Symcon ab 8.2](https://img.shields.io/badge/IP--Symcon-ab_8.2-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 3.1 (Build 22)](https://img.shields.io/badge/Modul--Version-3.1_(Build_22)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Volvo-XC60-Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Volvo-XC60-Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprache: Deutsch](https://img.shields.io/badge/Sprache-Deutsch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Cloud: Volvo Cars Developer Platform](https://img.shields.io/badge/Cloud-Volvo_Cars_Developer_Platform-lightgrey.svg)](https://developer.volvocars.com/)
[![Karte: OpenStreetMap](https://img.shields.io/badge/Karte-OpenStreetMap-lightgrey.svg)](https://www.openstreetmap.org/copyright)
![Anmeldung: OAuth2 mit PKCE](https://img.shields.io/badge/Anmeldung-OAuth2_%2B_PKCE-red.svg)
![Nur Leserechte](https://img.shields.io/badge/Rechte-nur_lesen-brightgreen.svg)

Modul zum Auslesen eines **Volvo** über die offiziellen Schnittstellen der
[Volvo Cars Developer Platform](https://developer.volvocars.com/) (Connected Vehicle API und Energy API).
Gedacht vor allem für Plug-in-Hybride und Elektroautos, z. B. um den Akkustand an das Easee-Wallbox-Modul zu geben.

Die Bibliothek enthält zwei Instanz-Typen:

- **Volvo Fahrzeug** – liest die Fahrzeugdaten aus der Volvo-Cloud (siehe unten)
- **Volvo Karte** – Kartenkachel mit dem Standort des Fahrzeugs und optionalem Verlauf

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionen](#funktionen)
2. [Voraussetzungen und Technik](#voraussetzungen-und-technik)
3. [Einrichtung](#einrichtung)
4. [Einstellungen](#einstellungen)
5. [Variablen und Darstellungen](#variablen-und-darstellungen)
6. [Kachel-Visualisierung](#kachel-visualisierung)
7. [Volvo Karte](#volvo-karte)
8. [Zusammenspiel mit dem Easee-Wallbox-Modul](#zusammenspiel-mit-dem-easee-wallbox-modul)
9. [Anmeldung abgelaufen?](#anmeldung-abgelaufen)
10. [PHP-Befehle](#php-befehle)
11. [Sicherheit und Geschwindigkeit](#sicherheit-und-geschwindigkeit)
12. [Fehlersuche](#fehlersuche)
13. [Entwicklung und Tests](#entwicklung-und-tests)
14. [Changelog](#changelog)
15. [Lizenz](#lizenz)

## Funktionen

- **Akkustand** in Prozent – bei älteren Plug-in-Hybriden automatisch über den Tank-Endpunkt, wenn die Energy API ihn nicht liefert
- Elektrische Reichweite, Ladestatus, Ladekabel angeschlossen
- Restladezeit und Ziel-Akkustand (sofern das Fahrzeug sie liefert)
- Tankinhalt und Reichweite Tank (Plug-in-Hybride/Verbrenner)
- Optional **Standort**: Koordinaten, **Adresse** (Straße, Ort), Kartenlink (OpenStreetMap, Google Maps oder Apple Karten),
  Entfernung von zu Hause und „Zu Hause“ ja/nein
- Kilometerstand, Zentralverriegelung, Türen/Klappen und Fenster offen oder geschlossen
- Modell, Baujahr, Akkugröße und das **offizielle Fahrzeugbild** (PNG mit transparentem Hintergrund)
- Offizielle Anmeldung per OAuth2 mit **eigenen** Zugangsdaten, Token werden automatisch erneuert
- **Eigene Kachel** für die Kachel-Visualisierung (Akku-Ring, Fahrzeugbild, Reichweite, Laden, Sicherheit, Standort)
- **Farbschema der Kachel:** Symcon-Design, Dunkel oder Hell
- Push-Benachrichtigung, wenn die Anmeldung erneuert werden muss

Werte, die dein Fahrzeug nicht liefert, werden nicht angelegt bzw. bleiben leer.

## Voraussetzungen und Technik

- IP-Symcon ab Version **8.2**, empfohlen **9.0**
- Volvo-ID (Konto der Volvo Cars App), mit der das Fahrzeug verknüpft ist
- Konto im [Volvo Developer Portal](https://developer.volvocars.com/) mit **derselben E-Mail-Adresse** wie die Volvo-ID
- Für die bequemste Anmeldung: **Symcon Connect** aktiv (sonst geht es auch von Hand, siehe unten)

Die Module nutzen die aktuelle Symcon-Technik:

| Technik | Ab Symcon | Wofür |
|---|---|---|
| Basisklasse `IPSModuleStrict` | 8.1 | Strenge Typen in allen Modulfunktionen, robust unter PHP 8.5 (Symcon 9.0) |
| `RegisterHook` im Modul | 8.1 | Rückruf `/hook/volvo` nach der Anmeldung – ohne Eingriff in die WebHook-Instanz, wird beim Löschen automatisch entfernt |
| Darstellungen statt Variablenprofilen | 8.0 | Aufzählung mit Symbolen und Farben (Ladestatus), Wertanzeige mit Vorlage „Batterie“ (Akkustand), Ja/Nein-Werte mit Text und Farbe (Verriegelt, Türen, Fenster, Zu Hause), Datum/Uhrzeit |
| `openObject` in der Kachel | 8.2 | Tippen auf den Standort öffnet die Kachel „Volvo Karte“ direkt in der Visualisierung |
| HTML-SDK-Kachel | 7.1 | Eigene Kacheln mit Live-Aktualisierung über `UpdateVisualizationValue` |
| Design der Visualisierung | 9.0 | Farbschema „Symcon-Design“ übernimmt Schrift- und Akzentfarbe der gewählten Visualisierung; alternativ „Dunkel“ und „Hell“ |

Eigene Variablenprofile legt das Modul nicht mehr an. Bestehende Variablen bekommen beim Update automatisch die neue
Darstellung; die alten Profile `VOLVO.*` werden nicht mehr benutzt und können unter *Kern Instanzen → Profile* gelöscht werden.
Der WebHook-Eintrag `/hook/volvo` wird von Symcon selbst verwaltet – ein alter, von Hand angelegter Eintrag in der WebHook-Instanz
wird dabei ersetzt.

## Einrichtung

### 1. Anwendung im Volvo Developer Portal anlegen

1. Im Portal unter **„Your API applications“** eine neue Anwendung anlegen, z. B. „Symcon“.
2. Den **VCC API Key** (Primary Key) notieren.
3. Die Anwendung für die Anmeldung mit Volvo-ID einrichten bzw. veröffentlichen. Dabei:
   - **Redirect URI** eintragen – die Adresse, die in der Symcon-Instanz unter „Weiterleitungs-Adresse“ angezeigt wird
     (mit Symcon Connect z. B. `https://xxxx.ipmagic.de/hook/volvo`).
   - Diese **Rechte (Scopes)** auswählen:
     `openid`, `conve:vehicle_relation`, `conve:battery_charge_level`, `conve:fuel_status`, `conve:odometer_status`,
     `conve:trip_statistics`, `conve:lock_status`, `conve:doors_status`, `conve:windows_status`, `energy:state:read`, `energy:capability:read`
4. **Client-ID** und **Client-Secret** notieren.

Die Bezeichnungen im Portal können sich ändern – entscheidend sind API Key, Client-ID, Client-Secret, Redirect URI und die Scopes.

### 2. Modul installieren

1. **Kern-Instanzen → Modules → Hinzufügen**: `https://github.com/cfaf2002/Volvo-XC60-Symcon`
2. **Instanz hinzufügen → „Volvo Fahrzeug“**.
3. API Key, Client-ID und Client-Secret eintragen, **Übernehmen**.

### 3. Bei Volvo anmelden

1. In der Instanz **„Bei Volvo anmelden“** klicken – es öffnet sich die Anmeldeseite von Volvo.
2. Mit der Volvo-ID anmelden und den Zugriff bestätigen.
3. **Mit Symcon Connect:** Die Seite zeigt „Anmeldung erfolgreich“ – fertig.
   **Ohne Symcon Connect:** Der Browser landet auf der eingetragenen Weiterleitungs-Adresse (die Seite selbst darf eine
   Fehlermeldung zeigen). Die **komplette Adresse aus der Adresszeile** (enthält `code=…`) kopieren und in der Instanz unter
   „Anmeldung ohne Symcon Connect abschließen“ einfügen.

Danach mit **„Fahrzeuge anzeigen“** prüfen, ob dein Fahrzeug gefunden wird.

## Einstellungen

| Einstellung | Bedeutung |
|---|---|
| VCC API Key, Client-ID, Client-Secret | Aus der Volvo-Anwendung im Developer Portal |
| Weiterleitungs-Adresse | Leer = Symcon-Connect-Adresse + `/hook/volvo`. Muss exakt der Redirect URI der Volvo-Anwendung entsprechen |
| Angefragte Rechte (Scopes) | Muss zu den in der Volvo-Anwendung angehakten Rechten passen (siehe Fehlersuche) |
| Fahrgestellnummer | Leer = erstes Fahrzeug im Konto |
| Abrufintervall | Standard 5 Minuten (Volvo erlaubt 10.000 Abfragen pro Tag; ein Abruf braucht 6–7, die gleichzeitig laufen) |
| Standort abrufen | Schalter; braucht das Recht `location:read` (auch im Feld „Angefragte Rechte“ ergänzen) |
| „Zu Hause“ im Umkreis von | Radius um den Standort aus Symcons Location Control (Standard 150 m) |
| Adresse ermitteln | Straße und Ort des Standorts über OpenStreetMap (Standard: an) |
| Tippen auf den Standort öffnet | Kachel „Volvo Karte“ (Standard), OpenStreetMap, Google Maps oder Apple Karten |
| Farbschema der Kachel | Symcon-Design (Farben der Visualisierung), Dunkel oder Hell |
| Visualisierung für Push | Meldung, wenn die Anmeldung erneuert werden muss |

## Variablen und Darstellungen

Alle Variablen nutzen **Darstellungen** (Symcon ≥ 8.0) statt Profilen: der Ladestatus als Aufzählung mit Symbol und Farbe, der Akkustand mit der Vorlage „Batterie“, Verriegelung, Türen, Fenster und „Zu Hause“ als Ja/Nein-Werte mit Text und Farbe.

| Variable | Beschreibung |
|---|---|
| Akkustand | Ladestand der Hochvoltbatterie in % |
| Reichweite elektrisch | in km |
| Ladestatus | Bereit, Lädt, Fertig geladen, Geplant, Smart Charging, Fehler … |
| Ladekabel angeschlossen | |
| Restladezeit | Minuten bis zum Ziel-Akkustand (nur wenn geliefert) |
| Ziel-Akkustand (Fahrzeug) | im Auto eingestelltes Ladeziel (nur wenn geliefert) |
| Tankinhalt, Reichweite Tank | nur bei Fahrzeugen mit Tank |
| Kilometerstand | |
| Verriegelt | Zentralverriegelung (nur mit den Rechten conve:lock_status **und** conve:doors_status) |
| Türen und Klappen | Geschlossen/Offen (Türen, Heckklappe, Motorhaube, Tankdeckel; Recht conve:doors_status) |
| Fenster | Geschlossen/Offen (inkl. Schiebedach; Recht conve:windows_status) |
| Geöffnet | Was gerade offen ist, z. B. „Fenster hinten links, Heckklappe“ |
| Breitengrad, Längengrad | Standort (nur mit Schalter „Standort abrufen“) |
| Standort vom | Zeitpunkt, zu dem Volvo den Standort ermittelt hat |
| Adresse | Straße, Hausnummer, PLZ und Ort (Schalter „Adresse ermitteln“) |
| Standort auf Karte | Link zum gewählten Kartendienst |
| Entfernung von zu Hause, Zu Hause | aus dem Symcon-Standort (Location Control) berechnet |
| Fahrzeug, Akkugröße | Modell/Baujahr und nutzbare Akkugröße laut Volvo |
| Letzte Aktualisierung, Letzter Fehler | |

Hinweis: Die Werte stammen aus der Volvo-Cloud. Das Auto meldet sie nicht live, sondern in Abständen – vor allem wenn es
geparkt ist, kann ein Wert einige Minuten alt sein.

## Kachel-Visualisierung

Die Instanz bringt eine eigene Kachel mit – einfach in der Kachel-Visualisierung hinzufügen:

- **Akku-Ring** mit Akkustand (grün ab 50 %, gelb ab 20 %, darunter rot), elektrischer Reichweite und – falls im Auto
  eingestellt – einer Markierung für das Ladeziel. Beim Laden pulsiert der Ring.
- **Offizielles Fahrzeugbild** von Volvo (braucht das Recht `conve:vehicle_relation`).
- **Chips** für Verriegelung, Türen, Fenster und „Zu Hause“ – grün, wenn alles in Ordnung ist, orange, wenn etwas offen ist.
- **Werteliste:** Laden (mit Restzeit), Reichweite elektrisch und Tank, was geöffnet ist, Standort als Adresse, Kilometerstand.
  Ein Tipp auf den Standort öffnet die Kachel **„Volvo Karte“** direkt in der Visualisierung (ab Symcon 8.2, sofern eine Karten-Instanz
  für dieses Fahrzeug existiert) – oder wahlweise OpenStreetMap, Google Maps bzw. Apple Karten.
- Auf dem **Handy** passt sich die Kachel der Größe an: klein (1×1) nur Status und Akku-Ring; breit (2×1) Ring links, Laden und
  Reichweite rechts; quadratisch (2×2) zusätzlich Standort und Kilometerstand. Verriegelung, Türen und Fenster als farbige Symbole.
- **Farbschema** unter „Kachel“: **Symcon-Design** (Standard) übernimmt Schrift- und Akzentfarbe der gewählten Visualisierung,
  **Dunkel** und **Hell** sind feste Schemas.
- Optional ein **Hintergrundbild** unter „Kachel“ in der Instanz. Große Fotos werden einmal auf 1600 Pixel verkleinert und zwischengespeichert.
- Die Systemeinstellung „Bewegung reduzieren“ wird beachtet; solange die Kachel nicht zu sehen ist, ruhen die Animationen.

Es werden nur Werte angezeigt, die das Fahrzeug tatsächlich liefert.

## Volvo Karte

Eigene Kachel mit einer **OpenStreetMap-Karte**, die den Standort aus der Volvo-Instanz zeigt.

**Einrichten**
1. In der Volvo-Instanz **„Standort abrufen“** aktivieren (Recht `location:read`).
2. **Instanz hinzufügen → „Volvo Karte“**, dort die Volvo-Instanz auswählen.
3. Die Instanz in der Kachel-Visualisierung hinzufügen – am besten groß (2×2 oder breiter).

**Was die Karte zeigt**
- Das Fahrzeug als Markierung. Kommt ein neuer Standort, **wandert die Markierung in der offenen Kachel mit** – ohne Neuladen.
  Wer die Karte verschiebt oder zoomt, behält seine Ansicht; **„Auto“** zentriert wieder auf das Fahrzeug.
- Optional das **Zuhause** als grüner Kreis (Standort aus Kern-Instanzen → Location Control, Radius aus der Volvo-Instanz).
- Eine Infoleiste unter der Karte: **Straße mit Hausnummer** (bei Geschäften, Parkhäusern usw. auch deren **Name**),
  darunter **PLZ und Ort**, „Zu Hause“ bzw. Entfernung, „steht seit …“ und der Akkustand.
- Bedienung: ziehen, Mausrad bzw. zwei Finger zum Zoomen, Doppelklick; **+ / −** auf großen Kacheln.
- **Farbschema der Kachel** (unter „Darstellung“): Symcon-Design (Farben der Visualisierung), Dunkel oder Hell – wie bei der Fahrzeug-Kachel.
  Fahrzeug, Verlauf und aktive Knöpfe nehmen die Akzentfarbe an. Unabhängig davon lässt sich die Karte selbst dunkel oder hell zeigen.

**Adresse (per Schalter)**
- **„Straße und Ort unter der Karte anzeigen“** (Standard: an). Die Adresse wird bei OpenStreetMap (Nominatim) nachgeschlagen –
  nur **einmal je neuem Standort** (Bewegung über 30 m), nicht bei jeder Aktualisierung. Dazu wird die Position an
  nominatim.openstreetmap.org übertragen.
- Zusätzlich legt die Instanz die Variable **„Adresse“** an (z. B. für Benachrichtigungen oder das Archiv).
- Klappt die Abfrage nicht, zeigt die Leiste wie bisher „Zu Hause“ bzw. die Entfernung; neuer Versuch frühestens nach 5 Minuten.

**Fahrzeugsymbol**
- **Standard-Symbol**, **Fahrzeugbild von Volvo** (freigestellt, steht auf dem Standortpunkt) oder **eigenes Foto / Bild** hochladen.
- Eigenes Bild wahlweise **rund zugeschnitten** (gut für Fotos) oder **frei stehend** (gut für PNG mit transparentem Hintergrund).
- Größe einstellbar (28–140 px). Hochgeladene Bilder werden automatisch auf 256 px verkleinert, damit die Kachel schnell bleibt.
  Auf kleinen Kacheln wird das Symbol automatisch kleiner.

**Verlauf (per Schalter)**
- **„Verlauf mitschreiben“** in der Instanz aktivieren. Ein neuer Punkt wird nur gespeichert, wenn sich das Auto um mehr als 30 m bewegt hat.
- Aufbewahrung einstellbar (Standard 7 Tage, bis 90 Tage). Ältere Punkte werden automatisch entfernt.
- In der Kachel zwischen **24 h**, **7 Tage** und **Alles** umschalten; die Karte zoomt dann auf den Verlauf.
- Ausschalten stoppt das Mitschreiben, der bisherige Verlauf bleibt bis „Verlauf löschen“ erhalten.

**Gut zu wissen**
- Die Karte zeigt unten, von wann der Standort laut Volvo stammt („Standort 07:12“, ältere Werte mit Datum). Volvo meldet einen
  neuen Standort vor allem beim Abstellen bzw. Verriegeln – während der Fahrt bleibt der letzte Parkplatz stehen.
- Volvo meldet den Standort nicht live, sondern vor allem beim Abstellen und dann in Abständen. Der Verlauf ist deshalb eine
  Folge von Standorten (Parkplätzen), keine exakte Fahrtroute.
- Die Kartenbilder kommen von OpenStreetMap – das Gerät, auf dem die Kachel angezeigt wird, braucht Internet. Es wird keine
  fremde Skript-Bibliothek nachgeladen und kein API-Schlüssel benötigt. Der Standort selbst bleibt in deinem Symcon.

PHP-Befehle: `VOLVOMAP_Refresh($id)`, `VOLVOMAP_GetAddress($id)`, `VOLVOMAP_GetHistory($id)` (JSON: `[[Zeitstempel, Breite, Länge], …]`), `VOLVOMAP_ClearHistory($id)`

### Adresse und Kartenlink

Im Bereich **„Standort“** der Volvo-Instanz:
- **„Adresse ermitteln“** (Standard: an): Straße, Hausnummer und Ort werden bei OpenStreetMap (Nominatim) nachgeschlagen –
  nur wenn sich das Auto mehr als 30 m bewegt hat. Dazu geht die Position an nominatim.openstreetmap.org.
  Die Kachel zeigt dann unter „Standort“ die **Adresse statt der Entfernung**: die Straße als Link, darunter PLZ und Ort (daheim „Zu Hause“).
- **„Tippen auf den Standort öffnet“**: die Kachel **„Volvo Karte“** (Standard, ab Symcon 8.2), OpenStreetMap, Google Maps oder
  Apple Karten. Gibt es keine Karten-Instanz für dieses Fahrzeug, öffnet sich OpenStreetMap.
- Die Instanz **„Volvo Karte“** übernimmt diese Adresse automatisch, es wird also nicht doppelt nachgefragt.

## Zusammenspiel mit dem Easee-Wallbox-Modul

1. In der Easee-Instanz unter **„Akkustand Fahrzeug“** den Schalter aktivieren und die Variable **„Akkustand“** dieser
   Volvo-Instanz auswählen.
2. Als nutzbare Akkugröße etwa 80 % der Variable **„Akkugröße“** eintragen – Volvo meldet die Brutto-Größe (z. B. XC60 T6/T8 2023: 18,8 kWh brutto, ca. 15 kWh nutzbar).
3. Fahrzeugbild: hier **„Fahrzeugbild öffnen“** klicken, das Bild speichern und in der Easee-Instanz unter
   „Kachel → Bild des Fahrzeugs“ auswählen.

## Anmeldung abgelaufen?

Volvo begrenzt die Gültigkeit der Freigabe für private Anwendungen. Läuft sie ab, wechselt die Instanz auf
„Anmeldung bei Volvo erforderlich“ und schickt – falls eingestellt – eine Push-Nachricht. Dann einfach erneut
**„Bei Volvo anmelden“** klicken.

## PHP-Befehle

```php
VOLVO_Update(int $InstanzID): bool            // Sofort abrufen
VOLVO_GetAddressData(int $InstanzID): string  // Adresse als JSON {lat, lon, name, street, city}
VOLVO_ListVehicles(int $InstanzID): string    // Fahrzeuge im Konto
VOLVO_GetVehicleImageUrl(int $InstanzID): string
VOLVO_GetLoginUrl(int $InstanzID): string
VOLVO_CompleteLogin(int $InstanzID, string $AdresseOderCode): string
VOLVO_Logout(int $InstanzID)
```

## Sicherheit und Geschwindigkeit

**Sicherheit**

- **Nur Leserechte:** Das Modul fragt keine Rechte zum Entriegeln, Starten oder Hupen an.
- **Anmeldung nach OAuth2 mit PKCE** (S256). Der `state` wird mit `hash_equals` geprüft, gilt nur **einmal** und nur **15 Minuten** –
  ein mitgeschnittener oder alter Anmelde-Link lässt sich nicht einlösen.
- Die Anmelde-Seite unter `/hook/volvo` wird nicht zwischengespeichert, nicht eingebettet und gibt die Adresse (mit dem Code) nicht weiter
  (`Cache-Control: no-store`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, Content-Security-Policy). Alle Texte darauf werden maskiert, und es werden nur einfache Texte angenommen (kein `code[]=…`).
- Client-Secret und API-Key stehen nur in der Instanz (Passwortfelder). Tokens, Fahrgestellnummer und Secret erscheinen nicht im Debug-Fenster.
- Alle Verbindungen (Volvo, OpenStreetMap) nur über HTTPS mit geprüftem Zertifikat; Fahrzeugbild und Kartenlinks werden nur als `https://` an die Kachel gegeben.
- Die Kacheln setzen alle Werte per `textContent`, nie als HTML. Die Startdaten werden mit `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
  eingebettet, damit kein Wert das Skript der Kachel beenden kann. Ungültige Zeichen in Fremddaten werden ersetzt, statt die Kachel abbrechen zu lassen.
  Die Tests prüfen beides.
- Hochgeladene Bilder (Hintergrund, Fahrzeugsymbol der Karte) werden nur eingebettet, wenn es wirklich Bilder sind.
- An OpenStreetMap geht für die Adresse nur die Position, keine Fahrzeugdaten.

**Geschwindigkeit**

- Alle Bereiche (Energie, Tank, Statistik, Kilometerstand, Türen, Fenster, Standort) werden **gleichzeitig** abgefragt (`curl_multi`) –
  ein Abruf dauert so etwa so lange wie die langsamste Antwort statt der Summe aller. Antworten werden komprimiert (gzip) übertragen.
- Modell, Akkugröße und Fahrzeugbild werden nur einmal am Tag geladen; die Adresse nur, wenn sich das Auto mehr als 30 m bewegt hat.
- Variablen werden nur geschrieben, wenn sich ihr Wert ändert.
- Die Kacheln bekommen nur dann Daten, wenn sich etwas Sichtbares geändert hat. Das gilt besonders für die Karte mit langem Verlauf.
- Hintergrundbilder werden einmal verkleinert und zwischengespeichert; Animationen ruhen, solange die Kachel nicht sichtbar ist.

## Fehlersuche

- **„The requested scope is invalid, unknown, malformed …“:** In der Instanz stehen Rechte, die in der Volvo-Anwendung
  nicht angehakt sind. Entweder in der Volvo-Anwendung anhaken oder aus dem Feld „Angefragte Rechte“ entfernen.
  Fehlende Rechte sind kein Problem – die zugehörigen Werte werden dann nur nicht abgerufen.

- **„VCC API Key ungültig“:** Den Primary Key aus derselben Volvo-Anwendung nehmen wie Client-ID und Client-Secret (nicht die Client-ID).
- **HTTP 403:** API Key falsch oder Scopes in der Volvo-Anwendung nicht freigeschaltet.
- **Anmeldung abgelehnt:** Client-ID/-Secret prüfen; Redirect URI in Volvo-Anwendung und Instanz müssen exakt gleich sein.
- Alle Anfragen stehen im **Debug-Fenster** der Instanz (Fahrgestellnummer und Tokens werden ausgeblendet).

## Entwicklung und Tests

| Pfad | Inhalt |
|---|---|
| `Volvo/` | Instanz „Volvo Fahrzeug“: Anmeldung, Abruf, Variablen und Kachel (`tile.html`) |
| `VolvoKarte/` | Instanz „Volvo Karte“: Kartenkachel (`tile.html`), Verlauf, Adresse und Fahrzeugsymbol |
| `libs/VolvoGeocoder.php` | Adresse über OpenStreetMap (Nominatim), von beiden Instanzen genutzt |
| `tests/bootstrap.php` | Testumgebung ohne Symcon (bildet `IPSModuleStrict` nach) |
| `tests/run.php` | Testsuite mit simulierter Volvo-Cloud (eigene Beispieldaten im Format der Volvo-API) |
| `tests/stubs.php` | Ladetest mit den offiziellen [Symcon-Stubs](https://github.com/symcon/SymconStubs) |
| `tests/structure.php` | Strukturprüfung nach Hausstil ([`STYLEGUIDE.md`](STYLEGUIDE.md)), in allen Repositorys gleich |

```
php tests/structure.php
php tests/run.php
php tests/stubs.php <Pfad zu SymconStubs>
```

Die Testsuite prüft unter anderem die Anmeldung (PKCE, falscher, fehlender, abgelaufener und doppelt benutzter State, maskierte
Fehlertexte), einen älteren und einen neueren Plug-in-Hybrid, Token-Erneuerung und abgelaufene Freigabe, dass Debug-Ausgaben keine
Geheimnisse zeigen, den parallelen Abruf, sparsame Kachel-Updates, Standort, Adresse und Kartenlinks, `openObject` zur Karte, das
Farbschema, den Schutz der Kachel vor eingeschleustem HTML, die Darstellungen sowie die Karte mit Verlauf, Adresse und Fahrzeugsymbol.

`tests/stubs.php` lädt die Bibliothek mit den offiziellen Symcon-Stubs wie Symcon selbst, legt beide Instanzen an, öffnet die
Formulare und prüft Variablen, Darstellungen und Kacheln.

GitHub Actions (`.github/workflows/tests.yml`) prüft bei jedem Push mit PHP 8.3 und 8.5 die Syntax, alle JSON-Dateien, die Testsuite und den Ladetest.

## Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 3.1 | 22 | 06.10.2026 | Hausstil: Kacheln heißen `tile.html` und nutzen die gemeinsame Kachel-Grundlage (Systemschrift, Farben aus den Tokens, Zustandsfarben einheitlich); „Volvo Karte“ bekommt ebenfalls das Farbschema Symcon-Design / Dunkel / Hell; Knöpfe der Karte mindestens 36 px; `STYLEGUIDE.md`, Strukturprüfung und gemeinsamer Test-Workflow |
| 3.0 | 21 | 04.10.2026 | Prüfung nachgeschärft: Variablen werden nur bei Änderung geschrieben, Anmelde-Seite nimmt nur einfache Texte an, ungültige Zeichen brechen die Kachel nicht mehr ab, Standort-Link in der Akzentfarbe des Symcon-Designs |
| 3.0 | 20 | 04.10.2026 | Symcon-9.0-Technik: `IPSModuleStrict`, `RegisterHook` im Modul, Darstellungen statt Profile, `openObject` (Standort öffnet die Kachel „Volvo Karte“), Farbschema (Symcon-Design / Dunkel / Hell); Sicherheit: State einmalig und 15 Minuten gültig, Sicherheits-Header der Anmelde-Seite, nur HTTPS mit Zertifikatsprüfung, sichere Einbettung der Kachel-Daten; Geschwindigkeit: paralleler Abruf aller Bereiche, Kachel-Updates nur bei Änderung, Bilder verkleinert und zwischengespeichert, gzip; Tests, Ladetest und MIT-Lizenz |
| 2.5 | 19 | 02.10.2026 | Volvo Karte: auf flachen, breiten Kacheln (z. B. Tablet) einzeilige, niedrige Infoleiste und kleinere Knöpfe – mehr Platz für die Karte |
| 2.4 | 18 | 02.10.2026 | Volvo Karte aktualisiert sich nach einem Neustart von Symcon wieder (Anmeldung an die Volvo-Instanz wird jedes Mal erneuert), zusätzlich Prüfung alle 5 Minuten; neue Variable „Standort vom“ (Zeitpunkt des Standorts laut Volvo), die Karte zeigt diese Zeit |
| 2.3 | 17 | 01.10.2026 | Kachel: Standort zweizeilig (Straße als Link, darunter PLZ und Ort) statt abgeschnitten |
| 2.2 | 16 | 01.10.2026 | Volvo Fahrzeug: Adresse des Standorts (Variable und Kachel statt Entfernung), Link wahlweise zu OpenStreetMap, Google Maps oder Apple Karten; Volvo Karte nutzt dieselbe Adresse |
| 2.1 | 15 | 01.10.2026 | Volvo Karte: Straße, Name und Ort unter der Karte (OpenStreetMap), Variable „Adresse“; Fahrzeugsymbol als Volvo-Fahrzeugbild oder eigenes Foto |
| 2.0 | 14 | 30.09.2026 | Neuer Instanz-Typ **„Volvo Karte“**: Kartenkachel mit Standort, Zuhause-Kreis und optionalem Verlauf |
| 1.10 | – | – | Kachel für alle Handy-Größen überarbeitet (1×1, 2×1, 2×2) |
| 1.9 | – | – | Eigene Kachel für die Kachel-Visualisierung (mit optionalem Hintergrundbild) |
| 1.8 | – | – | Optionaler Standort mit Kartenlink, Entfernung von zu Hause und „Zu Hause“ |
| 1.7 | – | – | Türen/Klappen und Fenster: geschlossen ja/nein und Liste, was offen ist |
| 1.6 | – | – | Recht conve:doors_status in der Standardliste ergänzt (für „Verriegelt“ nötig) |
| 1.5 | – | – | „Verriegelt“ wird erst angelegt, wenn Volvo den Wert liefert (Recht conve:lock_status) |
| 1.4 | – | – | Klare Meldung bei ungültigem VCC API Key, Leerzeichen in den Zugangsdaten werden ignoriert |
| 1.3 | – | – | Funktioniert auch ohne das Recht conve:vehicle_relation (dann Fahrgestellnummer eintragen) |
| 1.2 | – | – | Angefragte Rechte (Scopes) einstellbar, fehlende Rechte führen nicht mehr zum Fehler |
| 1.1 | – | – | „Bei Volvo anmelden“ erst aktiv, wenn alle Zugangsdaten eingetragen sind; zusätzlich „Anmelde-Adresse anzeigen“ |
| 1.0 | – | – | Erste Version |

## Lizenz

Diese Module stehen unter der **MIT-Lizenz** (siehe Datei [`LICENSE`](LICENSE)).

Sie dürfen kostenlos genutzt, verändert und weitergegeben werden, auch kommerziell. Bedingung ist nur, dass der Copyright-Hinweis
und der Lizenztext in Kopien erhalten bleiben. Eine Gewährleistung gibt es nicht.

Jede Code-Datei trägt einen Lizenzkopf mit `SPDX-License-Identifier: MIT`. Wer die Module weitergibt oder Teile davon übernimmt,
behält diesen Kopf und die Datei `LICENSE` bei.

**Hinweise:**
- Volvo ist eine Marke der Volvo Car Corporation. Diese Module sind kein offizielles Produkt von Volvo und stehen in keiner Verbindung
  zu Volvo Cars. Sie nutzen die öffentliche Volvo Cars Developer Platform mit den eigenen Zugangsdaten; deren Nutzungsbedingungen gelten.
- Kartendaten und Adressen: © [OpenStreetMap](https://www.openstreetmap.org/copyright)-Mitwirkende, verfügbar unter der Open Database License (ODbL).
  Der Hinweis wird in der Karte angezeigt. Die Adresssuche (Nominatim) wird sparsam genutzt – nur bei einem neuen Standort.
