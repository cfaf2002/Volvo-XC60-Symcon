# Volvo für IP-Symcon

Modul zum Auslesen eines **Volvo** über die offiziellen Schnittstellen der
[Volvo Cars Developer Platform](https://developer.volvocars.com/) (Connected Vehicle API und Energy API).
Gedacht vor allem für Plug-in-Hybride und Elektroautos, z. B. um den Akkustand an das Easee-Wallbox-Modul zu geben.

## Funktionen

- **Akkustand** in Prozent – bei älteren Plug-in-Hybriden automatisch über den Tank-Endpunkt, wenn die Energy API ihn nicht liefert
- Elektrische Reichweite, Ladestatus, Ladekabel angeschlossen
- Restladezeit und Ziel-Akkustand (sofern das Fahrzeug sie liefert)
- Tankinhalt und Reichweite Tank (Plug-in-Hybride/Verbrenner)
- Kilometerstand, Zentralverriegelung
- Modell, Baujahr, Akkugröße und das **offizielle Fahrzeugbild** (PNG mit transparentem Hintergrund)
- Offizielle Anmeldung per OAuth2 mit **eigenen** Zugangsdaten, Token werden automatisch erneuert
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
     `conve:trip_statistics`, `conve:lock_status`, `energy:state:read`, `energy:capability:read`
4. **Client-ID** und **Client-Secret** notieren.

Die Bezeichnungen im Portal können sich ändern – entscheidend sind API Key, Client-ID, Client-Secret, Redirect URI und die Scopes.

### 2. Modul installieren

1. **Kern-Instanzen → Modules → Hinzufügen**: `https://github.com/cfaf2002/Volvo-Symcon`
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
| Fahrgestellnummer | Leer = erstes Fahrzeug im Konto |
| Abrufintervall | Standard 5 Minuten (Volvo erlaubt 10.000 Abfragen pro Tag; ein Abruf braucht ca. 5) |
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
| Verriegelt | Zentralverriegelung |
| Fahrzeug, Akkugröße | Modell/Baujahr und nutzbare Akkugröße laut Volvo |
| Letzte Aktualisierung, Letzter Fehler | |

Hinweis: Die Werte stammen aus der Volvo-Cloud. Das Auto meldet sie nicht live, sondern in Abständen – vor allem wenn es
geparkt ist, kann ein Wert einige Minuten alt sein.

## Zusammenspiel mit dem Easee-Wallbox-Modul

1. In der Easee-Instanz unter **„Akkustand Fahrzeug“** den Schalter aktivieren und die Variable **„Akkustand“** dieser
   Volvo-Instanz auswählen.
2. Als nutzbare Akkugröße den Wert der Variable **„Akkugröße“** übernehmen.
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

- **HTTP 403:** API Key falsch oder Scopes in der Volvo-Anwendung nicht freigeschaltet.
- **Anmeldung abgelehnt:** Client-ID/-Secret prüfen; Redirect URI in Volvo-Anwendung und Instanz müssen exakt gleich sein.
- Alle Anfragen stehen im **Debug-Fenster** der Instanz (Fahrgestellnummer und Tokens werden ausgeblendet).

## Autor

Armin Frohwerk

## Versionen

- **1.1** – „Bei Volvo anmelden“ erst aktiv, wenn alle Zugangsdaten eingetragen sind; zusätzlich „Anmelde-Adresse anzeigen“
- **1.0** – Erste Version
