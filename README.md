# Volvo für IP-Symcon

Modul zum Auslesen eines **Volvo** über die offiziellen Schnittstellen der
[Volvo Cars Developer Platform](https://developer.volvocars.com/) (Connected Vehicle API und Energy API).
Gedacht vor allem für Plug-in-Hybride und Elektroautos, z. B. um den Akkustand an das Easee-Wallbox-Modul zu geben.

Die Bibliothek enthält zwei Instanz-Typen:

- **Volvo** – liest die Fahrzeugdaten aus der Volvo-Cloud (siehe unten)
- **Volvo Karte** – Kartenkachel mit dem Standort des Fahrzeugs und optionalem Verlauf

## Funktionen

- **Akkustand** in Prozent – bei älteren Plug-in-Hybriden automatisch über den Tank-Endpunkt, wenn die Energy API ihn nicht liefert
- Elektrische Reichweite, Ladestatus, Ladekabel angeschlossen
- Restladezeit und Ziel-Akkustand (sofern das Fahrzeug sie liefert)
- Tankinhalt und Reichweite Tank (Plug-in-Hybride/Verbrenner)
- Optional **Standort**: Koordinaten, Kartenlink, Entfernung von zu Hause und „Zu Hause“ ja/nein
- Kilometerstand, Zentralverriegelung, Türen/Klappen und Fenster offen oder geschlossen
- Modell, Baujahr, Akkugröße und das **offizielle Fahrzeugbild** (PNG mit transparentem Hintergrund)
- Offizielle Anmeldung per OAuth2 mit **eigenen** Zugangsdaten, Token werden automatisch erneuert
- **Eigene Kachel** für die Kachel-Visualisierung (Akku-Ring, Fahrzeugbild, Reichweite, Laden, Sicherheit, Standort)
- Push-Benachrichtigung, wenn die Anmeldung erneuert werden muss

Werte, die dein Fahrzeug nicht liefert, werden nicht angelegt bzw. bleiben leer.

## Voraussetzungen

- IP-Symcon ab Version 7.0
- Volvo-ID (Konto der Volvo Cars App), mit der das Fahrzeug verknüpft ist
- Konto im [Volvo Developer Portal](https://developer.volvocars.com/) mit **derselben E-Mail-Adresse** wie die Volvo-ID
- Für die bequemste Anmeldung: **Symcon Connect** aktiv (sonst geht es auch von Hand, siehe unten)

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
2. **Instanz hinzufügen → „Volvo“**.
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
| Abrufintervall | Standard 5 Minuten (Volvo erlaubt 10.000 Abfragen pro Tag; ein Abruf braucht ca. 5) |
| Standort abrufen | Schalter; braucht das Recht `location:read` (auch im Feld „Angefragte Rechte“ ergänzen) |
| „Zu Hause“ im Umkreis von | Radius um den Standort aus Symcons Location Control (Standard 150 m) |
| Visualisierung für Push | Meldung, wenn die Anmeldung erneuert werden muss |

## Variablen

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
| Standort auf Karte | Link zu OpenStreetMap |
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
- **Werteliste:** Laden (mit Restzeit), Reichweite elektrisch und Tank, was geöffnet ist, Standort (mit Link zur Karte), Kilometerstand.
- Auf dem **Handy** passt sich die Kachel der Größe an: klein (1×1) nur Status und Akku-Ring; breit (2×1) Ring links, Laden und
  Reichweite rechts; quadratisch (2×2) zusätzlich Standort und Kilometerstand. Verriegelung, Türen und Fenster als farbige Symbole.
- Optional ein **Hintergrundbild** unter „Kachel“ in der Instanz.

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
- Eine Infoleiste: „Zu Hause“ bzw. Entfernung, „steht seit …“, Stand der Daten und Akkustand.
- Bedienung: ziehen, Mausrad bzw. zwei Finger zum Zoomen, Doppelklick; **+ / −** auf großen Kacheln.
- Dunkle oder helle Karte (Schalter in der Instanz).

**Verlauf (per Schalter)**
- **„Verlauf mitschreiben“** in der Instanz aktivieren. Ein neuer Punkt wird nur gespeichert, wenn sich das Auto um mehr als 30 m bewegt hat.
- Aufbewahrung einstellbar (Standard 7 Tage, bis 90 Tage). Ältere Punkte werden automatisch entfernt.
- In der Kachel zwischen **24 h**, **7 Tage** und **Alles** umschalten; die Karte zoomt dann auf den Verlauf.
- Ausschalten stoppt das Mitschreiben, der bisherige Verlauf bleibt bis „Verlauf löschen“ erhalten.

**Gut zu wissen**
- Volvo meldet den Standort nicht live, sondern vor allem beim Abstellen und dann in Abständen. Der Verlauf ist deshalb eine
  Folge von Standorten (Parkplätzen), keine exakte Fahrtroute.
- Die Kartenbilder kommen von OpenStreetMap – das Gerät, auf dem die Kachel angezeigt wird, braucht Internet. Es wird keine
  fremde Skript-Bibliothek nachgeladen und kein API-Schlüssel benötigt. Der Standort selbst bleibt in deinem Symcon.

PHP-Befehle: `VOLVOMAP_Refresh($id)`, `VOLVOMAP_GetHistory($id)` (JSON: `[[Zeitstempel, Breite, Länge], …]`), `VOLVOMAP_ClearHistory($id)`

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
VOLVO_ListVehicles(int $InstanzID): string    // Fahrzeuge im Konto
VOLVO_GetVehicleImageUrl(int $InstanzID): string
VOLVO_GetLoginUrl(int $InstanzID): string
VOLVO_CompleteLogin(int $InstanzID, string $AdresseOderCode): string
VOLVO_Logout(int $InstanzID)
```

## Fehlersuche

- **„The requested scope is invalid, unknown, malformed …“:** In der Instanz stehen Rechte, die in der Volvo-Anwendung
  nicht angehakt sind. Entweder in der Volvo-Anwendung anhaken oder aus dem Feld „Angefragte Rechte“ entfernen.
  Fehlende Rechte sind kein Problem – die zugehörigen Werte werden dann nur nicht abgerufen.

- **„VCC API Key ungültig“:** Den Primary Key aus derselben Volvo-Anwendung nehmen wie Client-ID und Client-Secret (nicht die Client-ID).
- **HTTP 403:** API Key falsch oder Scopes in der Volvo-Anwendung nicht freigeschaltet.
- **Anmeldung abgelehnt:** Client-ID/-Secret prüfen; Redirect URI in Volvo-Anwendung und Instanz müssen exakt gleich sein.
- Alle Anfragen stehen im **Debug-Fenster** der Instanz (Fahrgestellnummer und Tokens werden ausgeblendet).

## Autor

Armin Frohwerk

## Versionen

- **2.0** – Neuer Instanz-Typ **„Volvo Karte“**: Kartenkachel mit Standort, Zuhause-Kreis und optionalem Verlauf
- **1.10** – Kachel für alle Handy-Größen überarbeitet (1×1, 2×1, 2×2)
- **1.9** – Eigene Kachel für die Kachel-Visualisierung (mit optionalem Hintergrundbild)
- **1.8** – Optionaler Standort mit Kartenlink, Entfernung von zu Hause und „Zu Hause“
- **1.7** – Türen/Klappen und Fenster: geschlossen ja/nein und Liste, was offen ist
- **1.6** – Recht conve:doors_status in der Standardliste ergänzt (für „Verriegelt“ nötig)
- **1.5** – „Verriegelt“ wird erst angelegt, wenn Volvo den Wert liefert (Recht conve:lock_status)
- **1.4** – Klare Meldung bei ungültigem VCC API Key, Leerzeichen in den Zugangsdaten werden ignoriert
- **1.3** – Funktioniert auch ohne das Recht conve:vehicle_relation (dann Fahrgestellnummer eintragen)
- **1.2** – Angefragte Rechte (Scopes) einstellbar, fehlende Rechte führen nicht mehr zum Fehler
- **1.1** – „Bei Volvo anmelden“ erst aktiv, wenn alle Zugangsdaten eingetragen sind; zusätzlich „Anmelde-Adresse anzeigen“
- **1.0** – Erste Version
