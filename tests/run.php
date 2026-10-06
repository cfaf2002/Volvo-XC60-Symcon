<?php

declare(strict_types=1);

/**
 * Testsuite für „Volvo Fahrzeug“ und „Volvo Karte“: php tests/run.php  (DEBUG=1 zeigt Debug-Ausgaben)
 *
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

ob_start();                    // Header der Anmelde-Seite in der Konsole zulassen
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../Volvo/module.php';
require __DIR__ . '/../VolvoKarte/module.php';

/** Antworten der Volvo-API im offiziellen Format (eigene Beispieldaten) */
function fixtures(string $car): array
{
    $ok = fn ($v, $unit = null) => ['status' => 'OK', 'value' => $v] + ($unit ? ['unit' => $unit] : []) + ['updatedAt' => '2026-09-30T18:00:00Z'];
    $na = ['status' => 'ERROR', 'code' => 'NOT_SUPPORTED', 'message' => 'Resource is not supported for this vehicle'];
    $val = fn ($v, $unit = null) => ['value' => $v] + ($unit ? ['unit' => $unit] : []) + ['timestamp' => '2026-09-30T18:00:00Z'];
    $closed = $val('CLOSED');

    $old = $car === 'alt';
    return [
        'vehicle'    => ['vin' => 'YV1ABCDEFG1234567', 'modelYear' => $old ? 2020 : 2024, 'fuelType' => 'PETROL/ELECTRIC',
                         'batteryCapacityKWH' => $old ? 11.832 : 18.819, 'descriptions' => ['model' => $old ? 'XC60' : 'XC90'],
                         'images' => ['exteriorImageUrl' => 'https://cas.volvocars.com/image/dynamic/car.png?angle=1&bg=00000000&w=1920']],
        'energy'     => $old
            ? ['batteryChargeLevel' => $na, 'electricRange' => $na, 'chargerConnectionStatus' => $ok('DISCONNECTED'),
               'chargingStatus' => $ok('IDLE'), 'estimatedChargingTimeToTargetBatteryChargeLevel' => $na, 'targetBatteryChargeLevel' => $ok(80)]
            : ['batteryChargeLevel' => $ok(87.3, 'percentage'), 'electricRange' => $ok(26, 'miles'), 'chargerConnectionStatus' => $ok('DISCONNECTED'),
               'chargingStatus' => $ok('IDLE'), 'estimatedChargingTimeToTargetBatteryChargeLevel' => $ok(0, 'minutes'), 'targetBatteryChargeLevel' => $na],
        'statistics' => $old ? ['distanceToEmptyTank' => $val(920, 'km'), 'distanceToEmptyBattery' => $val(29, 'km')] : ['distanceToEmptyTank' => $val(780, 'km')],
        'fuel'       => ['fuelAmount' => $val('47.3', 'l'), 'batteryChargeLevel' => $val('87.3', '%')],
        'odometer'   => ['odometer' => $val(30000, 'km')],
        'doors'      => ['centralLock' => $val('LOCKED'), 'frontLeftDoor' => $closed, 'frontRightDoor' => $closed, 'rearLeftDoor' => $closed,
                         'rearRightDoor' => $closed, 'hood' => $closed, 'tailgate' => $closed, 'tankLid' => $closed],
        'windows'    => ['frontLeftWindow' => $closed, 'frontRightWindow' => $closed, 'rearLeftWindow' => $val('OPEN'),
                         'rearRightWindow' => $closed, 'sunroof' => $val('UNSPECIFIED')],
        'location'   => ['type' => 'Feature', 'properties' => ['timestamp' => '2024-12-30T15:00:00.000Z', 'heading' => '90'],
                         'geometry' => ['type' => 'Point', 'coordinates' => [11.849843629550225, 57.72537482589284, 0.0]]]
    ];
}

/** Volvo-Instanz mit simulierter Volvo-Cloud */
class T extends Volvo
{
    public string $car = 'alt';
    public bool $refreshFails = false;
    public array $calls = [];
    public int $multi = 0;
    public string $lastTokenBody = '';
    public int $geo = 0;
    public ?array $geoResp = ['name' => '', 'address' => ['road' => 'Assar Gabrielssons väg', 'house_number' => '9', 'postcode' => '418 78', 'city' => 'Göteborg']];

    protected function HttpGetJson(string $url): ?array
    {
        $this->geo++;
        return $this->geoResp;
    }

    protected function HttpRequestMulti(array $requests): array
    {
        $this->multi++;
        $out = [];
        foreach ($requests as $key => [$method, $url, $headers, $body]) {
            $out[$key] = $this->HttpRequest($method, $url, $headers, $body);
        }
        return $out;
    }

    protected function HttpRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = $method . ' ' . preg_replace('#https://[^/]+#', '', $url);
        if (str_contains($url, 'token.oauth2')) {
            $this->lastTokenBody = (string) $body;
            if (str_contains((string) $body, 'refresh_token') && $this->refreshFails) {
                return [400, '{"error":"invalid_grant","error_description":"grant expired"}'];
            }
            return [200, json_encode(['access_token' => 'AT' . count($this->calls), 'refresh_token' => 'RT', 'expires_in' => 1799])];
        }
        if (!in_array('vcc-api-key: KEY', $headers, true)) {
            return [401, ''];
        }
        $f = fixtures($this->car);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $data = fn (array $d) => [200, json_encode(['data' => $d])];
        return match (true) {
            $path === '/connected-vehicle/v2/vehicles'                    => $data([['vin' => 'YV1ABCDEFG1234567']]),
            (bool) preg_match('#/connected-vehicle/v2/vehicles/\w+$#', $path) => $data($f['vehicle']),
            str_ends_with($path, '/energy/v2/vehicles/YV1ABCDEFG1234567/state') => [200, json_encode($f['energy'])],
            str_ends_with($path, '/fuel')       => $data($f['fuel']),
            str_ends_with($path, '/statistics') => $data($f['statistics']),
            str_ends_with($path, '/odometer')   => $data($f['odometer']),
            str_ends_with($path, '/doors')      => $data($f['doors']),
            str_ends_with($path, '/windows')    => $data($f['windows']),
            str_ends_with($path, '/location')   => $data($f['location']),
            default                             => [404, '']
        };
    }
}

/** Karte mit simulierter Adresssuche */
class TK extends VolvoKarte
{
    public array $calls = [];
    public ?array $resp = null;

    protected function HttpGetJson(string $url): ?array
    {
        $this->calls[] = $url;
        return $this->resp;
    }
}

function hook(T $m, array $get): string
{
    $_GET = $get;
    ob_start();
    call($m, 'ProcessHookData');
    return (string) ob_get_clean();
}

// =====================================================================
echo "Anmeldung (OAuth2 mit PKCE)\n";
$m = new T();
$m->Create();
$m->ApplyChanges();
check($m->status === 201, 'Ohne Zugangsdaten: Status 201');
check($m->hooks === ['volvo'], 'WebHook /hook/volvo über RegisterHook (Symcon ≥ 8.1) registriert');
$m->p['ApiKey'] = 'KEY'; $m->p['ClientId'] = 'cid'; $m->p['ClientSecret'] = 'sec';
$m->ApplyChanges();
check($m->status === 202, 'Mit Zugangsdaten, nicht angemeldet: Status 202');
$url = $m->GetLoginUrl();
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
check($q['redirect_uri'] === 'https://abc123.ipmagic.de/hook/volvo' && $q['code_challenge_method'] === 'S256' && str_contains($q['scope'], 'energy:state:read'),
    'Login-Adresse mit Connect-Weiterleitung, PKCE und Rechten');
$form = json_decode($m->GetConfigurationForm(), true);
$f0 = new T(); $f0->Create();
$fa = json_decode($f0->GetConfigurationForm(), true);
check($fa['actions'][2]['items'][0]['enabled'] === false && $form['actions'][2]['items'][0]['enabled'] === true, 'Anmelde-Button erst mit Zugangsdaten aktiv');
check(str_contains(json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'https://abc123.ipmagic.de/hook/volvo'), 'Formular zeigt passende Weiterleitungs-Adresse');

echo "Sicherheit der Anmeldung\n";
check(str_contains(hook($m, ['code' => 'XYZ', 'state' => 'falsch']), 'nicht zugeordnet') && $m->a['RefreshToken'] === '', 'Falscher State wird abgelehnt');
check(str_contains(hook($m, ['code' => 'XYZ', 'state' => '']), 'nicht zugeordnet'), 'Fehlender State wird abgelehnt');
$m->a['AuthStarted'] = time() - 3600;
check(str_contains(hook($m, ['code' => 'XYZ', 'state' => $q['state']]), 'nicht zugeordnet'), 'Abgelaufener Anmelde-Link (älter als 15 min) wird abgelehnt');
$m->a['AuthStarted'] = time();
$page = hook($m, ['code' => 'XYZ', 'state' => $q['state'], 'error' => '<script>alert(1)</script>']);
check(str_contains($page, 'Anmeldung erfolgreich') && $m->a['RefreshToken'] === 'RT', 'Anmeldung über den WebHook');
check(str_contains($m->lastTokenBody, 'code_verifier=') && str_contains($m->lastTokenBody, 'grant_type=authorization_code'), 'Token-Tausch mit code_verifier');
check($m->a['AuthState'] === '' && str_contains(hook($m, ['code' => 'XYZ', 'state' => $q['state']]), 'nicht zugeordnet'), 'State gilt nur einmal (kein zweites Einlösen)');
$e = hook($m, ['state' => 'x', 'error_description' => '<img src=x onerror=alert(1)>']);
check(!str_contains($e, '<img'), 'Fehlertexte auf der Anmelde-Seite werden maskiert');
check(str_contains(hook($m, ['code' => ['x'], 'state' => ['y']]), 'nicht zugeordnet'), 'Werte als Array (code[]=…) werden abgelehnt');

echo "Fahrzeugdaten: älterer Plug-in-Hybrid (Akku nur über den Tank-Bereich)\n";
check($m->v['BatteryLevel'] === 87, 'Akkustand 87 %');
check($m->v['ElectricRange'] === 29 && $m->v['Odometer'] === 30000, 'Reichweite 29 km, Kilometerstand 30.000');
check($m->v['ChargingStatus'] === 1 && $m->v['CableConnected'] === false, 'Ladestatus Bereit, Kabel nicht angeschlossen');
check($m->v['TargetLevel'] === 80 && !isset($m->v['ChargingTimeLeft']), 'Ziel 80 %, Restladezeit nicht unterstützt');
check($m->v['Model'] === 'XC60 2020' && $m->v['BatteryCapacity'] == 11.8, 'Modell und Akkugröße 11,8 kWh');
check(str_contains($m->GetVehicleImageUrl(), 'bg=00000000') && str_contains($m->GetVehicleImageUrl(), 'w=800'), 'Fahrzeugbild (transparent, 800 px)');
check($m->v['Locked'] === true && $m->v['DoorsClosed'] === true && $m->v['WindowsClosed'] === false && $m->v['OpenParts'] === 'Fenster hinten links',
    'Verriegelt, Türen zu, Fenster hinten links offen');
check($m->status === 102 && $m->v['LastError'] === '', 'Status 102');

echo "Fahrzeugdaten: neuerer Plug-in-Hybrid (Energy API)\n";
$m2 = new T(); $m2->car = 'neu'; $m2->Create();
$m2->p['ApiKey'] = 'KEY'; $m2->p['ClientId'] = 'c'; $m2->p['ClientSecret'] = 's';
$m2->ApplyChanges();
$m2->a['RefreshToken'] = 'RT';
$m2->Update();
check($m2->v['BatteryLevel'] === 87 && $m2->v['ChargingTimeLeft'] === 0 && !isset($m2->v['TargetLevel']), 'Akku aus Energy API, Restladezeit da, Ziel fehlt');
check($m2->v['ElectricRange'] === 42, 'Reichweite von Meilen in km umgerechnet (26 mi = 42 km)');

echo "Token\n";
$n = count($m->calls); $m->Update();
check(!in_array('POST /as/token.oauth2', array_slice($m->calls, $n), true), 'Gültiges Token wird wiederverwendet');
$m->a['TokenExpires'] = 0; $n = count($m->calls); $m->Update();
check(in_array('POST /as/token.oauth2', array_slice($m->calls, $n), true) && str_contains($m->lastTokenBody, 'grant_type=refresh_token'), 'Abgelaufenes Token wird erneuert');
$m->a['TokenExpires'] = 0; $m->refreshFails = true; $m->Update();
check($m->status === 202 && $m->a['RefreshToken'] === '' && str_contains($m->v['LastError'], 'abgelaufen'), 'Abgelaufene Freigabe: Status 202 mit Hinweis');
$m->refreshFails = false;
$u = $m->GetLoginUrl();
parse_str((string) parse_url($u, PHP_URL_QUERY), $q2);
$m->CompleteLogin('https://abc123.ipmagic.de/hook/volvo?code=ABC&state=' . $q2['state']);
check($m->a['RefreshToken'] === 'RT' && $m->status === 102, 'Anmeldung per eingefügter Adresse');
check(str_contains($m->ListVehicles(), 'YV1ABCDEFG1234567  –  XC60 2020'), 'Fahrzeugliste');
$m->GetLoginUrl();
$n = count($m->calls);
$msg = $m->CompleteLogin('https://abc123.ipmagic.de/hook/volvo?code=ABC&state=fremd');
check(str_contains($msg, 'gehört nicht') && count($m->calls) === $n, 'Fremde Anmelde-Adresse wird nicht eingelöst');
$dbg = implode("\n", $GLOBALS['debug']);
check(!str_contains($dbg, 'YV1ABCDEFG1234567') && !str_contains($dbg, 'sec') && !str_contains($dbg, 'AT1'), 'Debug zeigt weder Fahrgestellnummer noch Secret oder Token');

echo "Geschwindigkeit\n";
$m->multi = 0; $m->Update();
check($m->multi === 1, 'Alle Bereiche in einem parallelen Abruf');
$m->pushes = [];
$m->Update(); $m->Update();
check(count($m->pushes) === 0, 'Unveränderte Daten werden nicht erneut an die Kachel gesendet');

echo "Standort und Adresse\n";
check(!isset($m->v['Latitude']), 'Ohne Schalter kein Standort');
$m->p['EnableLocation'] = true;
$m->Update();
check(abs($m->v['Latitude'] - 57.725375) < 0.0001 && $m->v['AtHome'] === true && $m->v['DistanceHome'] < 0.2, 'Standort, Zu Hause, Entfernung');
check($m->v['LocationTime'] === strtotime('2024-12-30T15:00:00Z'), 'Standort vom (Zeit laut Volvo)');
check($m->geo === 1 && $m->v['Address'] === 'Assar Gabrielssons väg 9, 418 78 Göteborg', 'Adresse ermittelt');
$m->Update();
check($m->geo === 1, 'Gleicher Standort: keine zweite Adressabfrage');
$d = json_decode($m->GetAddressData(), true);
check(abs($d['lat'] - 57.725375) < 0.0001 && $d['street'] === 'Assar Gabrielssons väg 9', 'GetAddressData für die Karte');
$m->p['MapService'] = 1; $m->ApplyChanges();
check(str_starts_with($m->v['MapLink'], 'https://www.google.com/maps/search/?api=1&query=57.72'), 'Link auf Google Maps');
$m->p['MapService'] = 2; $m->ApplyChanges();
check(str_starts_with($m->v['MapLink'], 'https://maps.apple.com/?ll=57.72'), 'Link auf Apple Karten');
$m->p['ShowAddress'] = false; $m->ApplyChanges();
check(!isset($m->v['Address']) && $m->GetAddressData() === '', 'Schalter aus: Variable Adresse entfernt');
$m->p['ShowAddress'] = true; $m->p['MapService'] = 3; $m->ApplyChanges(); $m->Update();

echo "Kachel\n";
Sym::$instances[GUID_MAP] = [950];
Sym::$props[950] = ['VolvoInstance' => 777];
$td = call($m, 'TileData');
check($td['mapObject'] === 950 && str_starts_with($td['mapLink'], 'https://www.openstreetmap.org/'), 'Tippen auf den Standort öffnet die Kachel „Volvo Karte“ (openObject), Link als Ersatz');
check($td['address']['street'] === 'Assar Gabrielssons väg 9' && $td['address']['city'] === '418 78 Göteborg', 'Kachel bekommt Adresse');
check($td['soc'] === 87 && $td['model'] === 'XC60 2020' && str_contains($td['image'], 'w=800') && $td['locked'] === true && $td['atHome'] === true, 'Kachel-Daten vollständig');
$m->p['TileTheme'] = 2;
check(call($m, 'TileData')['theme'] === 2, 'Farbschema „Hell“');
$m->p['TileTheme'] = 0;
$m->v['Model'] = '</script><img src=x onerror=alert(1)>';
$html = $m->GetVisualizationTile();
$err = $m->v['LastError']; $m->v['LastError'] = "Fehler \xC3\x28";
check(str_contains($m->GetVisualizationTile(), 'handleMessage("{'), 'Ungültige Zeichen in Fehlertexten brechen die Kachel nicht ab');
$m->v['LastError'] = $err;
$own = substr_count((string) file_get_contents(__DIR__ . '/../Volvo/tile.html'), '</script>');
check(!str_contains($html, '</script><img') && substr_count($html, '</script>') === $own + 1, 'Eingeschleustes HTML kann das Kachel-Skript nicht beenden');
check(!str_contains((string) file_get_contents(__DIR__ . '/../Volvo/tile.html'), '.innerHTML'), 'Kachel setzt Werte nur als Text (kein innerHTML)');

echo "Symcon 9.0: Darstellungen\n";
check($m->pres['ChargingStatus']['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION && str_contains($m->pres['ChargingStatus']['OPTIONS'], 'Lädt'), 'Ladestatus als Aufzählung');
check(($m->pres['BatteryLevel']['TEMPLATE'] ?? '') === VARIABLE_TEMPLATE_VALUE_PRESENTATION_BATTERY, 'Akkustand mit Vorlage „Batterie“');
check($m->pres['LastUpdate']['PRESENTATION'] === VARIABLE_PRESENTATION_DATE_TIME, 'Zeitpunkte als Datum/Uhrzeit');
check(count(array_filter($m->pres, 'is_string')) === 0, 'Keine Variablenprofile mehr');

// =====================================================================
echo "Volvo Karte\n";
function setPos(float $lat, float $lon): void { Sym::$vars[1] = $lat; Sym::$vars[2] = $lon; }
Sym::reset();
Sym::$props[700] = ['Location' => json_encode(['latitude' => 52.5200, 'longitude' => 13.4050])];
Sym::$props[900] = ['HomeRadius' => 200];
$k = new TK();
$k->resp = ['name' => '', 'address' => ['road' => 'Unter den Linden', 'house_number' => '77', 'postcode' => '10117', 'city' => 'Berlin', 'suburb' => 'Mitte']];
$k->Create(); $k->ApplyChanges();
check($k->status === 201, 'Ohne Volvo-Instanz: Status 201');
Sym::$idents = ['LastUpdate' => 10, 'Model' => 11, 'BatteryLevel' => 12, 'AtHome' => 13, 'DistanceHome' => 14];
Sym::$vars = [10 => time(), 11 => 'XC60 2023', 12 => 94, 13 => true, 14 => 0.1];
$k->p['VolvoInstance'] = 900; $k->ApplyChanges();
check($k->status === 102 && isset($k->msgs[10]) && $k->timers['RefreshTimer'] === 300000, 'Status 102, lauscht auf die Volvo-Instanz, Sicherheits-Timer 5 min');
$k->msgs = []; $k->ApplyChanges();
check(isset($k->msgs[10]), 'Nach einem Neustart wieder angemeldet');
$t = json_decode((string) $k->tile, true);
check($t['pos'] === null && str_contains($t['hint'], 'Standort abrufen'), 'Ohne Standort: Hinweis');
Sym::$idents += ['Latitude' => 1, 'Longitude' => 2];
setPos(52.5201, 13.4051);
$k->MessageSink(0, 10, VM_UPDATE, []);
$t = json_decode((string) $k->tile, true);
check(abs($t['pos']['lat'] - 52.5201) < 1e-6 && $t['home']['r'] === 200 && $t['track'] === null, 'Standort, Zuhause-Kreis 200 m, kein Verlauf ohne Schalter');
$k->p['RecordHistory'] = true; $k->ApplyChanges();
setPos(52.5300, 13.4100); $k->Refresh();
setPos(52.5301, 13.4101); $k->Refresh();
setPos(52.5500, 13.4500); $k->Refresh();
check(count(json_decode($k->GetHistory(), true)) === 2, 'Verlauf: kleiner Sprung (13 m) wird ignoriert');
$k->ClearHistory();
check($k->GetHistory() === '[]', 'Verlauf löschen');
$n = count($k->calls); setPos(52.8, 13.7); $k->Refresh();
$t = json_decode((string) $k->tile, true);
check(count($k->calls) === $n + 1 && $t['address']['street'] === 'Unter den Linden 77' && $t['address']['city'] === '10117 Berlin-Mitte', 'Adresse bei Bewegung genau einmal nachgeschlagen');
check(str_starts_with($k->calls[$n], 'https://nominatim.openstreetmap.org/'), 'Adresssuche nur über https');
setPos(52.80005, 13.70005); $k->Refresh(); $k->Refresh();
check(count($k->calls) === $n + 1, 'Gleicher Ort: keine weitere Abfrage');
Sym::$volvoAddress = json_encode(['lat' => 53.2, 'lon' => 14.1, 'name' => '', 'street' => 'Volvoweg 1', 'city' => '12345 Testdorf']);
setPos(53.2, 14.1); $n = count($k->calls); $k->Refresh();
check(count($k->calls) === $n && json_decode((string) $k->tile, true)['address']['street'] === 'Volvoweg 1', 'Adresse der Volvo-Instanz wird übernommen');
$k->p['MarkerType'] = 1; $k->ApplyChanges();
check(json_decode((string) $k->tile, true)['marker']['type'] === 'free', 'Volvo-Fahrzeugbild als Symbol');
$k->p['MarkerType'] = 2; $k->p['MarkerImage'] = base64_encode('<svg onload="alert(1)"/>'); $k->ApplyChanges();
check(json_decode((string) $k->tile, true)['marker']['type'] === 'icon', 'Keine Bilddatei hochgeladen → Standard-Symbol');
$k->pushes = []; $k->Refresh(); $k->Refresh();
check(count($k->pushes) === 0, 'Unveränderte Karte wird nicht erneut gesendet');
check(($k->pres['Address']['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'Adresse mit Darstellung „Wertanzeige“');

$out = (string) ob_get_clean();
echo $out;
echo PHP_EOL . sprintf('%d Prüfungen bestanden, %d fehlgeschlagen.', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
