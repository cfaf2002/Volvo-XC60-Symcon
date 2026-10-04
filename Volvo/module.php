<?php

/**
 * Volvo für IP-Symcon
 *
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Volvo
 *
 * Liest Akkustand, Reichweite, Ladestatus, Kilometerstand u. a. eines Volvo
 * über die offiziellen APIs der Volvo Cars Developer Platform
 * (Connected Vehicle API v2, Energy API v2).
 *
 * Anmeldung: OAuth2 (Authorization Code + PKCE) mit eigener Client-ID und
 * eigenem Client-Secret aus dem Volvo Developer Portal.
 *
 * Autor: Armin Frohwerk
 */
require_once __DIR__ . '/VolvoTile.php';
require_once __DIR__ . '/../libs/VolvoGeocoder.php';

class Volvo extends IPSModuleStrict
{
    use VolvoTile;
    use VolvoGeocoder;

    private const AUTHORIZE_URL = 'https://volvoid.eu.volvocars.com/as/authorization.oauth2';
    private const TOKEN_URL = 'https://volvoid.eu.volvocars.com/as/token.oauth2';
    private const API = 'https://api.volvocars.com';
    private const CONNECTED = '/connected-vehicle/v2/vehicles';
    private const ENERGY = '/energy/v2/vehicles';
    private const LOCATION = '/location/v1/vehicles';
    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    private const CONNECT_GUID = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
    private const MAP_GUID = '{BEC364E9-0470-407D-829E-BC42DC2EB4BC}';
    private const HOOK = '/hook/volvo';
    private const LOGIN_VALID = 900;        // Anmelde-Link gilt 15 Minuten

    /** Ladestatus: [Wert, Text, Symbol, Farbe] */
    private const CHARGING_OPTIONS = [
        [0, 'Unbekannt', 'circle-question', 0x7F8C8D],
        [1, 'Bereit', 'plug', 0x95A5A6],
        [2, 'Lädt', 'bolt', 0x2ECC71],
        [3, 'Fertig geladen', 'circle-check', 0x3498DB],
        [4, 'Geplant', 'clock', 0xF1C40F],
        [5, 'Smart Charging', 'leaf', 0x1ABC9C],
        [6, 'Fehler', 'triangle-exclamation', 0xE74C3C],
        [7, 'Entlädt', 'arrow-down', 0xE67E22]
    ];

    // Nur die Rechte, die das Modul wirklich braucht (müssen in der
    // Volvo-Anwendung freigeschaltet sein)
    private const SCOPES = [
        'openid',
        'conve:vehicle_relation',
        'conve:battery_charge_level',
        'conve:fuel_status',
        'conve:odometer_status',
        'conve:trip_statistics',
        'conve:lock_status',
        'conve:doors_status',
        'conve:windows_status',
        'energy:state:read',
        'energy:capability:read'
    ];

    // =================================================================
    // Symcon-Lebenszyklus
    // =================================================================

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('ApiKey', '');
        $this->RegisterPropertyString('ClientId', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('RedirectUri', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyInteger('UpdateInterval', 5);
        $this->RegisterPropertyInteger('NotifyInstance', 0);
        $this->RegisterPropertyString('Scopes', implode(' ', self::SCOPES));
        $this->RegisterPropertyBoolean('EnableLocation', false);
        $this->RegisterPropertyInteger('HomeRadius', 150);
        $this->RegisterPropertyBoolean('ShowAddress', true);
        $this->RegisterPropertyInteger('MapService', 3);       // 0 OpenStreetMap, 1 Google Maps, 2 Apple Karten, 3 Volvo Karte

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('CodeVerifier', '');
        $this->RegisterAttributeString('AuthState', '');
        $this->RegisterAttributeInteger('AuthStarted', 0);
        $this->RegisterAttributeString('ActiveVin', '');
        $this->RegisterAttributeString('Vehicle', '{}');
        $this->RegisterAttributeInteger('LoginNotified', 0);
        $this->RegisterAttributeString('Address', '');
        $this->RegisterAttributeInteger('GeocodeFailed', 0);

        $this->RegisterTimer('UpdateTimer', 0, 'VOLVO_Update($_IPS[\'TARGET\']);');

        // Eigene Kachel in der Kachel-Visualisierung
        $this->RegisterPropertyString('TileBackground', '');
        $this->RegisterPropertyInteger('TileDim', 55);
        $this->RegisterPropertyInteger('TileTheme', 0);         // 0 = Symcon-Design, 1 = Dunkel, 2 = Hell
        $this->RegisterAttributeString('ImageCache', '{}');
        $this->SetVisualizationType(1);

        // Rückruf von Volvo nach der Anmeldung (ab Symcon 8.1 direkt im Modul, wird beim Löschen automatisch entfernt)
        $this->RegisterHook('volvo');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->CreateVariables();
        $this->ApplyLocationSettings();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->PushTile(true);

        $wanted = strtoupper(trim($this->ReadPropertyString('VIN')));
        if ($wanted !== '' && $wanted !== $this->ReadAttributeString('ActiveVin')) {
            $this->WriteAttributeString('ActiveVin', $wanted);
            $this->WriteAttributeString('Vehicle', '{}');
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(104);
            return;
        }

        if (trim($this->ReadPropertyString('ApiKey')) === '' || trim($this->ReadPropertyString('ClientId')) === ''
            || trim($this->ReadPropertyString('ClientSecret')) === '') {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(201);
            return;
        }

        $this->SetTimerInterval('UpdateTimer', max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);

        if ($this->ReadAttributeString('RefreshToken') === '') {
            $this->SetStatus(202);
            return;
        }

        $this->SetStatus(102);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    /** Formular mit Hinweis auf die passende Weiterleitungs-Adresse ergänzen. */
    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $suggest = $this->SuggestedRedirectUri();
        $loggedIn = $this->ReadAttributeString('RefreshToken') !== '';
        $vehicle = json_decode($this->ReadAttributeString('Vehicle'), true) ?: [];

        // "Bei Volvo anmelden" erst freigeben, wenn alle Angaben da sind -
        // sonst würde der Browser einen Hinweistext als Adresse öffnen
        $ready = trim($this->ReadPropertyString('ApiKey')) !== '' && trim($this->ReadPropertyString('ClientId')) !== ''
            && trim($this->ReadPropertyString('ClientSecret')) !== '' && $this->RedirectUri() !== '';
        $hint = $ready
            ? 'Alles eingetragen – jetzt „Bei Volvo anmelden“ klicken.'
            : 'Zum Anmelden zuerst VCC API Key, Client-ID, Client-Secret und Weiterleitungs-Adresse eintragen und „Übernehmen“ klicken.';
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'LoginHint') {
                $action['caption'] = $hint;
            }
            foreach ($action['items'] ?? [] as $i => $item) {
                if (in_array($item['name'] ?? '', ['LoginButton', 'LoginUrlButton'], true)) {
                    $action['items'][$i]['enabled'] = $ready;
                }
            }
        }
        unset($action);

        array_walk_recursive($form, function (&$value) use ($suggest, $loggedIn, $vehicle) {
            if (!is_string($value)) {
                return;
            }
            $value = str_replace(
                ['{REDIRECT}', '{LOGIN}', '{VEHICLE}'],
                [
                    $suggest !== '' ? $suggest : '(Symcon Connect ist nicht aktiv – siehe README)',
                    $loggedIn ? 'Angemeldet' : 'Noch nicht angemeldet',
                    isset($vehicle['model']) ? trim($vehicle['model'] . ' ' . ($vehicle['year'] ?? '')) . ' · ' . $this->ReadAttributeString('ActiveVin') : '–'
                ],
                $value
            );
        });

        return json_encode($form);
    }

    // =================================================================
    // Anmeldung (OAuth2 mit PKCE)
    // =================================================================

    /** Liefert die Anmelde-Adresse bei Volvo (wird im Formular als Link geöffnet). */
    public function GetLoginUrl(): string
    {
        $redirect = $this->RedirectUri();
        if (trim($this->ReadPropertyString('ClientId')) === '' || $redirect === '') {
            return 'about:blank';
        }

        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));
        $this->WriteAttributeString('CodeVerifier', $verifier);
        $this->WriteAttributeString('AuthState', $state);
        $this->WriteAttributeInteger('AuthStarted', time());

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => trim($this->ReadPropertyString('ClientId')),
            'redirect_uri'          => $redirect,
            'scope'                 => $this->RequestedScopes(),
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Angefragte Rechte: aus der Instanz, "openid" ist immer dabei. */
    private function RequestedScopes(): string
    {
        $list = preg_split('/[\s,;]+/', trim($this->ReadPropertyString('Scopes'))) ?: [];
        $list = array_values(array_unique(array_filter(array_merge(['openid'], $list))));
        return implode(' ', $list);
    }

    /**
     * Anmeldung von Hand abschließen: die komplette Adresse aus der
     * Browser-Zeile nach der Anmeldung (oder nur den Code) übergeben.
     */
    public function CompleteLogin(string $UrlOrCode): string
    {
        $UrlOrCode = trim($UrlOrCode);
        $code = $UrlOrCode;

        if (strpos($UrlOrCode, 'code=') !== false) {
            parse_str((string) parse_url($UrlOrCode, PHP_URL_QUERY), $query);
            $code = self::QueryString($query, 'code');
            if (!$this->ValidState(self::QueryString($query, 'state'))) {
                return 'Die Adresse gehört nicht zur letzten Anmeldung oder ist abgelaufen. Bitte erneut „Bei Volvo anmelden“ klicken.';
            }
        }

        if ($code === '') {
            return 'Kein Anmelde-Code gefunden.';
        }

        try {
            $this->ExchangeCode($code);
            $this->Update();
            return 'Anmeldung erfolgreich.';
        } catch (Exception $e) {
            $this->SetValue('LastError', $e->getMessage());
            return 'Anmeldung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    /** Abmelden: gespeicherte Zugangsdaten verwerfen. */
    public function Logout(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenExpires', 0);
        $this->SetStatus(202);
        $this->ReloadForm();
    }

    /** Rückruf von Volvo nach der Anmeldung (WebHook /hook/volvo). */
    protected function ProcessHookData(): void
    {
        // Nur einfache Texte annehmen (z. B. kein code[]=… als Array)
        $code = self::QueryString($_GET, 'code');
        $state = self::QueryString($_GET, 'state');

        header('Content-Type: text/html; charset=utf-8');
        // Seite nicht zwischenspeichern, nicht einbetten und den Code nicht weiterreichen
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");

        if (!$this->ValidState($state)) {
            echo self::HookPage('Anmeldung nicht zugeordnet', 'Bitte in Symcon erneut „Bei Volvo anmelden“ klicken.', false);
            return;
        }
        if ($code === '') {
            $error = self::QueryString($_GET, 'error_description') ?: self::QueryString($_GET, 'error') ?: 'Kein Code erhalten.';
            $error = mb_substr($error, 0, 300);
            echo self::HookPage('Anmeldung abgebrochen', $error, false);
            return;
        }

        try {
            $this->ExchangeCode($code);
            $this->Update();
            echo self::HookPage('Anmeldung erfolgreich', 'Du kannst dieses Fenster jetzt schließen.', true);
        } catch (Exception $e) {
            $this->SetValue('LastError', $e->getMessage());
            echo self::HookPage('Anmeldung fehlgeschlagen', $e->getMessage(), false);
        }
    }

    /** Wert aus einer Adresszeile, nur wenn es ein einfacher Text ist. */
    private static function QueryString(array $query, string $key): string
    {
        $value = $query[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** State aus der Anmeldung prüfen: muss passen und darf höchstens 15 Minuten alt sein. */
    private function ValidState(string $state): bool
    {
        $expected = $this->ReadAttributeString('AuthState');
        return $state !== '' && $expected !== '' && hash_equals($expected, $state)
            && time() - $this->ReadAttributeInteger('AuthStarted') <= self::LOGIN_VALID;
    }

    private function ExchangeCode(string $code): void
    {
        $this->RequestToken([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->RedirectUri(),
            'code_verifier' => $this->ReadAttributeString('CodeVerifier')
        ]);

        $this->WriteAttributeString('AuthState', '');
        $this->WriteAttributeString('CodeVerifier', '');
        $this->WriteAttributeInteger('LoginNotified', 0);
        $this->SetStatus(102);
    }

    private function GetAccessToken(): string
    {
        $lock = 'VOLVO_Token_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 15000)) {
            throw new Exception('Token-Sperre konnte nicht belegt werden.');
        }

        try {
            $token = $this->ReadAttributeString('AccessToken');
            if ($token !== '' && $this->ReadAttributeInteger('TokenExpires') > time()) {
                return $token;
            }

            $refresh = $this->ReadAttributeString('RefreshToken');
            if ($refresh === '') {
                throw new VolvoLoginException('Nicht angemeldet. Bitte in der Instanz „Bei Volvo anmelden“.');
            }

            try {
                return $this->RequestToken(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
            } catch (VolvoLoginException $e) {
                // Freigabe abgelaufen -> neu anmelden
                $this->WriteAttributeString('AccessToken', '');
                $this->WriteAttributeString('RefreshToken', '');
                throw new VolvoLoginException('Die Anmeldung bei Volvo ist abgelaufen. Bitte in der Instanz neu anmelden.');
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function RequestToken(array $body): string
    {
        $basic = base64_encode(trim($this->ReadPropertyString('ClientId')) . ':' . trim($this->ReadPropertyString('ClientSecret')));

        [$code, $raw] = $this->HttpRequest('POST', self::TOKEN_URL, [
            'Authorization: Basic ' . $basic,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ], http_build_query($body));

        $json = json_decode($raw, true);

        if ($code === 400 || $code === 401) {
            $reason = is_array($json) ? ($json['error_description'] ?? $json['error'] ?? '') : '';
            throw new VolvoLoginException('Volvo hat die Anmeldung abgelehnt (HTTP ' . $code . ($reason !== '' ? ': ' . $reason : '') . ').');
        }
        if ($code !== 200 || !is_array($json) || empty($json['access_token'])) {
            throw new Exception('Token-Abruf fehlgeschlagen (HTTP ' . $code . ').');
        }

        $this->WriteAttributeString('AccessToken', (string) $json['access_token']);
        if (!empty($json['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', (string) $json['refresh_token']);
        }
        $this->WriteAttributeInteger('TokenExpires', time() + (int) ($json['expires_in'] ?? 1800) - 60);
        $this->SendDebug('Token', 'Neues Token erhalten', 0);

        return (string) $json['access_token'];
    }

    // =================================================================
    // Daten abrufen
    // =================================================================

    /** Alle Fahrzeugdaten abrufen. */
    public function Update(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        try {
            $vin = $this->GetVin();
            // Modell/Akkugröße/Bild braucht das Recht conve:vehicle_relation - optional
            $this->Optional(function () use ($vin) {
                $this->LoadVehicleDetails($vin);
                return [];
            });

            // Energie (Akku, Laden) - ältere Hybride liefern hier nicht alles
            // Einzelne Bereiche dürfen fehlen (Recht nicht freigegeben oder
            // vom Fahrzeug nicht unterstützt) - dann einfach überspringen
            // Alle Bereiche gleichzeitig abrufen (parallel statt nacheinander: ein Abruf dauert so
            // nur etwa so lange wie die langsamste Antwort)
            $paths = [
                'energy'  => self::ENERGY . '/' . $vin . '/state',
                'fuel'    => self::CONNECTED . '/' . $vin . '/fuel',
                'stats'   => self::CONNECTED . '/' . $vin . '/statistics',
                'odo'     => self::CONNECTED . '/' . $vin . '/odometer',
                'doors'   => self::CONNECTED . '/' . $vin . '/doors',
                'windows' => self::CONNECTED . '/' . $vin . '/windows'
            ];
            if ($this->ReadPropertyBoolean('EnableLocation')) {
                $paths['location'] = self::LOCATION . '/' . $vin . '/location';
            }
            $r = $this->ApiParallel($paths);
            $data = fn (string $key) => is_array($r[$key]['data'] ?? null) ? $r[$key]['data'] : [];

            if (isset($paths['location'])) {
                $this->ProcessLocation($data('location'));
            }
            $this->Process($r['energy'], $data('fuel'), $data('stats'), $data('odo'), $data('doors'), $data('windows'));

            $this->SetValue('LastError', '');
            $this->SetValue('LastUpdate', time());
            if ($this->GetStatus() !== 102) {
                $this->SetStatus(102);
            }
            $this->PushTile();
            return true;
        } catch (VolvoLoginException $e) {
            $this->ReportError($e->getMessage(), 202);
            if ($this->ReadAttributeInteger('LoginNotified') === 0) {
                $this->WriteAttributeInteger('LoginNotified', 1);
                $this->Notify('🚗 Volvo', $e->getMessage());
            }
        } catch (Exception $e) {
            $this->ReportError($e->getMessage(), 203);
        }

        return false;
    }

    /** Liste aller Fahrzeuge im Konto (auch als Verbindungstest). */
    public function ListVehicles(): string
    {
        try {
            $list = $this->Api(self::CONNECTED);
            $lines = ['Fahrzeuge im Volvo-Konto:', ''];
            foreach ($list['data'] ?? [] as $v) {
                $vin = (string) ($v['vin'] ?? '');
                $details = $this->ApiData(self::CONNECTED . '/' . $vin);
                $lines[] = $vin . '  –  ' . trim(($details['descriptions']['model'] ?? '') . ' ' . ($details['modelYear'] ?? ''));
            }
            return count($lines) > 2 ? implode("\n", $lines) : 'Anmeldung ok, aber kein Fahrzeug gefunden.';
        } catch (Exception $e) {
            return 'Fehler: ' . $e->getMessage();
        }
    }

    /**
     * Zuletzt ermittelte Adresse als JSON {lat, lon, name, street, city}, leer wenn ausgeschaltet.
     * Wird von „Volvo Karte“ genutzt, damit die Adresse nur einmal abgefragt wird.
     */
    public function GetAddressData(): string
    {
        if (!$this->ReadPropertyBoolean('ShowAddress') || !$this->ReadPropertyBoolean('EnableLocation')) {
            return '';
        }
        return $this->ReadAttributeString('Address');
    }

    /** Adresse des offiziellen Fahrzeugbilds (PNG mit transparentem Hintergrund). */
    public function GetVehicleImageUrl(): string
    {
        $vehicle = json_decode($this->ReadAttributeString('Vehicle'), true) ?: [];
        $url = (string) ($vehicle['image'] ?? '');
        if ($url === '') {
            return 'Noch kein Fahrzeugbild bekannt – bitte zuerst anmelden und aktualisieren.';
        }
        // Kleinere Breite reicht für die Kachel (hält die Datei klein)
        return preg_replace('/([?&]w=)\d+/', '${1}800', $url);
    }

    private function GetVin(): string
    {
        $vin = $this->ReadAttributeString('ActiveVin');
        if ($vin !== '') {
            return $vin;
        }

        try {
            $list = $this->Api(self::CONNECTED);
        } catch (VolvoForbiddenException $e) {
            throw new Exception('Fahrzeugliste nicht erlaubt (Recht conve:vehicle_relation fehlt). Bitte die Fahrgestellnummer in der Instanz eintragen.');
        }
        $vin = (string) ($list['data'][0]['vin'] ?? '');
        if ($vin === '') {
            throw new Exception('Kein Fahrzeug im Volvo-Konto gefunden.');
        }

        $this->WriteAttributeString('ActiveVin', $vin);
        return $vin;
    }

    /** Modell, Baujahr, Akkugröße und Bild einmal pro Fahrzeug laden. */
    private function LoadVehicleDetails(string $vin): void
    {
        $vehicle = json_decode($this->ReadAttributeString('Vehicle'), true) ?: [];
        if (($vehicle['vin'] ?? '') === $vin && ($vehicle['loaded'] ?? 0) > time() - 86400) {
            return;
        }

        $d = $this->ApiData(self::CONNECTED . '/' . $vin);
        $vehicle = [
            'vin'      => $vin,
            'loaded'   => time(),
            'model'    => (string) ($d['descriptions']['model'] ?? ''),
            'year'     => (int) ($d['modelYear'] ?? 0),
            'fuel'     => (string) ($d['fuelType'] ?? ''),
            'capacity' => (float) ($d['batteryCapacityKWH'] ?? 0),
            'image'    => (string) ($d['images']['exteriorImageUrl'] ?? '')
        ];
        $this->WriteAttributeString('Vehicle', json_encode($vehicle));

        $this->SetValue('Model', trim($vehicle['model'] . ' ' . ($vehicle['year'] ?: '')));
        if ($vehicle['capacity'] > 0) {
            $this->SetValue('BatteryCapacity', round($vehicle['capacity'], 1));
        }
    }

    private function Process(array $energy, array $fuel, array $stats, array $odo, array $doors, array $windows = []): void
    {
        // Akkustand: Energy API, sonst Tank-Endpunkt (ältere Plug-in-Hybride)
        $soc = self::EnergyValue($energy, 'batteryChargeLevel') ?? self::Value($fuel, 'batteryChargeLevel');
        if ($soc !== null) {
            $this->SetValue('BatteryLevel', (int) round((float) $soc));
        }

        // Elektrische Reichweite: Statistik liefert zuverlässig km
        $range = self::Value($stats, 'distanceToEmptyBattery');
        if ($range === null) {
            $range = self::EnergyValue($energy, 'electricRange');
            if ($range !== null && (($energy['electricRange']['unit'] ?? '') === 'miles')) {
                $range = (float) $range * 1.609344;
            }
        }
        if ($range !== null) {
            $this->SetValue('ElectricRange', (int) round((float) $range));
        }

        $status = self::EnergyValue($energy, 'chargingStatus');
        if ($status !== null) {
            $this->SetValue('ChargingStatus', self::ChargingStatusCode((string) $status));
        }

        $connection = self::EnergyValue($energy, 'chargerConnectionStatus');
        if ($connection !== null) {
            $this->SetValue('CableConnected', $connection === 'CONNECTED');
        }

        $remaining = self::EnergyValue($energy, 'estimatedChargingTimeToTargetBatteryChargeLevel');
        if ($remaining !== null) {
            $this->MaintainOptional('ChargingTimeLeft', 'Restladezeit', VARIABLETYPE_INTEGER, self::PValue(' min', 0, 'hourglass-half'), 7);
            $this->SetValue('ChargingTimeLeft', (int) $remaining);
        }

        $target = self::EnergyValue($energy, 'targetBatteryChargeLevel');
        if ($target !== null) {
            $this->MaintainOptional('TargetLevel', 'Ziel-Akkustand (Fahrzeug)', VARIABLETYPE_INTEGER, self::PValue(' %', 0, 'bullseye'), 8);
            $this->SetValue('TargetLevel', (int) round((float) $target));
        }

        $fuelAmount = self::Value($fuel, 'fuelAmount');
        if ($fuelAmount !== null) {
            $this->MaintainOptional('FuelAmount', 'Tankinhalt', VARIABLETYPE_FLOAT, self::PValue(' l', 1, 'gas-pump'), 20);
            $this->SetValue('FuelAmount', round((float) $fuelAmount, 1));
        }

        $tankRange = self::Value($stats, 'distanceToEmptyTank');
        if ($tankRange !== null) {
            $this->MaintainOptional('FuelRange', 'Reichweite Tank', VARIABLETYPE_INTEGER, self::PValue(' km', 0, 'gas-pump'), 21);
            $this->SetValue('FuelRange', (int) round((float) $tankRange));
        }

        $km = self::Value($odo, 'odometer');
        if ($km !== null) {
            if (($odo['odometer']['unit'] ?? 'km') === 'mi') {
                $km = (float) $km * 1.609344;
            }
            $this->SetValue('Odometer', (int) round((float) $km));
        }

        $lock = self::Value($doors, 'centralLock');
        if ($lock !== null) {
            $this->MaintainOptional('Locked', 'Verriegelt', VARIABLETYPE_BOOLEAN, self::PBool('Entriegelt', 'Verriegelt', 'lock', 0x2ECC71, 0xE67E22), 23);
            $this->SetValue('Locked', $lock === 'LOCKED');
        }

        // Türen/Klappen und Fenster: geschlossen ja/nein plus Liste, was offen ist
        $open = [];
        $doorsKnown = $this->CollectOpen($doors, [
            'frontLeftDoor' => 'Tür vorne links', 'frontRightDoor' => 'Tür vorne rechts',
            'rearLeftDoor' => 'Tür hinten links', 'rearRightDoor' => 'Tür hinten rechts',
            'tailgate' => 'Heckklappe', 'hood' => 'Motorhaube', 'tankLid' => 'Tankdeckel'
        ], $open);
        $doorsOpen = count($open);
        $windowsKnown = $this->CollectOpen($windows, [
            'frontLeftWindow' => 'Fenster vorne links', 'frontRightWindow' => 'Fenster vorne rechts',
            'rearLeftWindow' => 'Fenster hinten links', 'rearRightWindow' => 'Fenster hinten rechts',
            'sunroof' => 'Schiebedach'
        ], $open);

        if ($doorsKnown) {
            $this->MaintainOptional('DoorsClosed', 'Türen und Klappen', VARIABLETYPE_BOOLEAN, self::PBool('Offen', 'Geschlossen', 'door-closed', 0x2ECC71, 0xE74C3C), 24);
            $this->SetValue('DoorsClosed', $doorsOpen === 0);
        }
        if ($windowsKnown) {
            $this->MaintainOptional('WindowsClosed', 'Fenster', VARIABLETYPE_BOOLEAN, self::PBool('Offen', 'Geschlossen', 'window-frame', 0x2ECC71, 0xE74C3C), 25);
            $this->SetValue('WindowsClosed', count($open) === $doorsOpen);
        }
        if ($doorsKnown || $windowsKnown) {
            $this->MaintainOptional('OpenParts', 'Geöffnet', VARIABLETYPE_STRING, self::PValue('', 0, 'triangle-exclamation'), 26);
            $this->SetValue('OpenParts', count($open) > 0 ? implode(', ', $open) : 'Alles geschlossen');
        }
    }

    // =================================================================
    // HTTP
    // =================================================================

    /** GET auf die Volvo-API; 404 (nicht unterstützt) ergibt ein leeres Array. */
    private function Api(string $path): array
    {
        $token = $this->GetAccessToken();
        $headers = [
            'Authorization: Bearer ' . $token,
            'vcc-api-key: ' . trim($this->ReadPropertyString('ApiKey')),
            'Accept: application/json'
        ];

        [$code, $raw] = $this->HttpRequest('GET', self::API . $path, $headers, null);

        if ($code === 401) {
            // Token eventuell vorzeitig ungültig: einmal neu holen
            $this->WriteAttributeInteger('TokenExpires', 0);
            $headers[0] = 'Authorization: Bearer ' . $this->GetAccessToken();
            [$code, $raw] = $this->HttpRequest('GET', self::API . $path, $headers, null);
        }

        return $this->ParseApi($code, $raw, $path);
    }

    /**
     * Mehrere GET-Abrufe gleichzeitig. Fehlende Rechte (403) oder nicht unterstützte
     * Bereiche (404) ergeben ein leeres Array; bei 401 wird einzeln mit neuem Token wiederholt.
     *
     * @param array<string,string> $paths
     * @return array<string,array>
     */
    private function ApiParallel(array $paths): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->GetAccessToken(),
            'vcc-api-key: ' . trim($this->ReadPropertyString('ApiKey')),
            'Accept: application/json'
        ];
        $requests = [];
        foreach ($paths as $key => $path) {
            $requests[$key] = ['GET', self::API . $path, $headers, null];
        }

        $out = [];
        foreach ($this->HttpRequestMulti($requests) as $key => [$code, $raw]) {
            $out[$key] = $code === 401
                ? $this->Optional(fn () => $this->Api($paths[$key]))
                : $this->Optional(fn () => $this->ParseApi($code, $raw, $paths[$key]));
        }
        return $out;
    }

    /** Antwort der Volvo-API auswerten. */
    private function ParseApi(int $code, string $raw, string $path): array
    {
        if ($code === 401 && stripos($raw, 'VCC-API-KEY') !== false) {
            throw new Exception('VCC API Key ungültig – bitte den Primary Key aus derselben Volvo-Anwendung eintragen wie Client-ID und Client-Secret.');
        }
        if ($code === 404) {
            return [];
        }
        if ($code === 403) {
            throw new VolvoForbiddenException('Kein Zugriff (HTTP 403) auf ' . $path . ' – API-Key und freigeschaltete Rechte (Scopes) der Volvo-Anwendung prüfen.');
        }
        if ($code === 429) {
            throw new Exception('Volvo API-Limit erreicht (HTTP 429). Abrufintervall erhöhen.');
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception('Volvo API Fehler HTTP ' . $code . ' bei ' . $path . ': ' . mb_substr($raw, 0, 200));
        }

        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    /** Abruf, der bei fehlendem Recht (403) ein leeres Ergebnis liefert. */
    private function Optional(callable $call): array
    {
        try {
            return $call();
        } catch (VolvoForbiddenException $e) {
            $this->SendDebug('Übersprungen', $e->getMessage(), 0);
            return [];
        }
    }

    /** Wie Api(), liefert aber direkt den Inhalt von "data". */
    private function ApiData(string $path): array
    {
        $body = $this->Api($path);
        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /** @return array{0:int,1:string} */
    protected function HttpRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = self::Curl($method, $url, $headers, $body);
        $raw = curl_exec($ch);
        $result = $this->Finish($method, $url, $ch, $raw === false ? false : (string) $raw);
        return $result;
    }

    /**
     * Mehrere Anfragen gleichzeitig (curl_multi). Für Tests überschreibbar.
     *
     * @param array<string,array{0:string,1:string,2:array,3:?string}> $requests
     * @return array<string,array{0:int,1:string}>
     */
    protected function HttpRequestMulti(array $requests): array
    {
        if (count($requests) < 2 || !function_exists('curl_multi_init')) {
            $out = [];
            foreach ($requests as $key => [$method, $url, $headers, $body]) {
                $out[$key] = $this->HttpRequest($method, $url, $headers, $body);
            }
            return $out;
        }

        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $key => [$method, $url, $headers, $body]) {
            $handles[$key] = self::Curl($method, $url, $headers, $body);
            curl_multi_add_handle($multi, $handles[$key]);
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Ergebnis je Anfrage (Verbindungsfehler stehen nur hier, nicht in curl_error)
        $results = [];
        while (($info = curl_multi_info_read($multi)) !== false) {
            $results[spl_object_id($info['handle'])] = $info['result'];
        }

        $out = [];
        foreach ($handles as $key => $ch) {
            $result = $results[spl_object_id($ch)] ?? CURLE_OK;
            $raw = $result === CURLE_OK ? (string) curl_multi_getcontent($ch) : false;
            if ($raw === false) {
                $this->SendDebug('Fehler', curl_strerror($result), 0);
            }
            $out[$key] = $this->Finish($requests[$key][0], $requests[$key][1], $ch, $raw);
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);
        return $out;
    }

    private static function Curl(string $method, string $url, array $headers, ?string $body): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            // Nur verschlüsselt und mit geprüftem Zertifikat
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => ''      // gzip annehmen
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        return $ch;
    }

    /** @return array{0:int,1:string} */
    private function Finish(string $method, string $url, CurlHandle $ch, string|false $raw): array
    {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Fahrgestellnummer und Tokens nicht im Debug zeigen
        $shown = preg_replace('#/vehicles/[A-Z0-9]{17}#', '/vehicles/***', preg_replace('#\?.*$#', '', $url));
        $this->SendDebug($method, $shown . ' -> HTTP ' . $code, 0);

        if ($raw === false) {
            throw new Exception('Verbindungsfehler: ' . (curl_error($ch) ?: 'keine Antwort'));
        }
        if (strpos($url, 'volvoid') === false) {
            $this->SendDebug('Antwort', mb_substr(preg_replace('#[A-Z0-9]{17}#', '***', $raw), 0, 1500), 0);
        }

        return [$code, $raw];
    }

    // =================================================================
    // WebHook / Weiterleitung
    // =================================================================

    private function RedirectUri(): string
    {
        $uri = trim($this->ReadPropertyString('RedirectUri'));
        return $uri !== '' ? $uri : $this->SuggestedRedirectUri();
    }

    private function SuggestedRedirectUri(): string
    {
        $ids = IPS_GetInstanceListByModuleID(self::CONNECT_GUID);
        if (count($ids) === 0 || !function_exists('CC_GetUrl')) {
            return '';
        }
        $url = (string) @CC_GetUrl($ids[0]);
        return $url !== '' ? rtrim($url, '/') . self::HOOK : '';
    }

    private static function HookPage(string $title, string $text, bool $ok): string
    {
        $color = $ok ? '#2ecc71' : '#e74c3c';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Volvo – ' . htmlspecialchars($title, ENT_QUOTES) . '</title></head>'
            . '<body style="font-family:Segoe UI,Roboto,Arial,sans-serif;background:#1e2227;color:#eee;display:flex;'
            . 'align-items:center;justify-content:center;height:100vh;margin:0">'
            . '<div style="text-align:center;padding:24px"><div style="font-size:44px;color:' . $color . '">' . ($ok ? '✓' : '✕') . '</div>'
            . '<h2 style="margin:8px 0">' . htmlspecialchars($title, ENT_QUOTES) . '</h2><p style="opacity:.8">' . htmlspecialchars($text, ENT_QUOTES) . '</p></div>'
            . '</body></html>';
    }

    // =================================================================
    // Hilfen
    // =================================================================

    /** Wert aus der Energy API ({status, value}) - null wenn nicht unterstützt. */
    private static function EnergyValue(array $energy, string $key)
    {
        $field = $energy[$key] ?? null;
        if (!is_array($field) || ($field['status'] ?? '') !== 'OK' || !array_key_exists('value', $field)) {
            return null;
        }
        return $field['value'];
    }

    /** Wert aus der Connected Vehicle API ({value, unit, timestamp}). */
    private static function Value(array $data, string $key)
    {
        $field = $data[$key] ?? null;
        if (!is_array($field) || !array_key_exists('value', $field) || $field['value'] === '' || $field['value'] === null) {
            return null;
        }
        return $field['value'];
    }

    /** Standort (GeoJSON-Punkt: Länge, Breite) auswerten. */
    private function ProcessLocation(array $loc): void
    {
        $coords = $loc['geometry']['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return;
        }
        $lon = (float) $coords[0];
        $lat = (float) $coords[1];

        $this->MaintainOptional('Latitude', 'Breitengrad', VARIABLETYPE_FLOAT, self::PValue('°', 6, 'location-dot'), 50);
        $this->MaintainOptional('Longitude', 'Längengrad', VARIABLETYPE_FLOAT, self::PValue('°', 6, 'location-dot'), 51);
        $this->MaintainOptional('MapLink', 'Standort auf Karte', VARIABLETYPE_STRING, self::PValue('', 0, 'map'), 54);
        $this->SetValue('Latitude', $lat);
        $this->SetValue('Longitude', $lon);
        $this->SetValue('MapLink', $this->MapUrl($lat, $lon));

        // Zeitpunkt, zu dem Volvo diesen Standort ermittelt hat
        $stamp = strtotime((string) ($loc['properties']['timestamp'] ?? ''));
        if ($stamp !== false && $stamp > 0) {
            $this->MaintainOptional('LocationTime', 'Standort vom', VARIABLETYPE_INTEGER, self::PDateTime(), 56);
            $this->SetValue('LocationTime', $stamp);
        }

        if ($this->ReadPropertyBoolean('ShowAddress')) {
            $this->UpdateAddress($lat, $lon);
        }

        // Entfernung zum Zuhause aus der Symcon-Ortsangabe (Location Control)
        $home = $this->HomePosition();
        if ($home === null) {
            return;
        }
        $meters = self::Distance($lat, $lon, $home[0], $home[1]);
        $this->MaintainOptional('DistanceHome', 'Entfernung von zu Hause', VARIABLETYPE_FLOAT, self::PValue(' km', 1, 'route'), 52);
        $this->MaintainOptional('AtHome', 'Zu Hause', VARIABLETYPE_BOOLEAN, self::PBool('Unterwegs', 'Zu Hause', 'house', 0x2ECC71), 53);
        $this->SetValue('DistanceHome', round($meters / 1000, 1));
        $this->SetValue('AtHome', $meters <= max(20, $this->ReadPropertyInteger('HomeRadius')));
    }

    /** Link zum Standort im gewählten Kartendienst (öffnet auf dem Handy die Karten-App). */
    private function MapUrl(float $lat, float $lon): string
    {
        switch ($this->ReadPropertyInteger('MapService')) {
            case 1:
                return sprintf('https://www.google.com/maps/search/?api=1&query=%.6F,%.6F', $lat, $lon);
            case 2:
                return sprintf('https://maps.apple.com/?ll=%.6F,%.6F&q=%s', $lat, $lon, rawurlencode($this->VehicleLabel()));
            default:
                return sprintf('https://www.openstreetmap.org/?mlat=%.6F&mlon=%.6F#map=17/%.6F/%.6F', $lat, $lon, $lat, $lon);
        }
    }

    /** Instanz „Volvo Karte“, die dieses Fahrzeug zeigt (0 = keine). */
    private function MapInstance(): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::MAP_GUID) as $id) {
            if ((int) @IPS_GetProperty($id, 'VolvoInstance') === $this->InstanceID) {
                return $id;
            }
        }
        return 0;
    }

    private function VehicleLabel(): string
    {
        $id = @$this->GetIDForIdent('Model');
        $model = $id ? (string) $this->GetValue('Model') : '';
        return $model !== '' ? $model : 'Volvo';
    }

    /** Adresse nur neu nachschlagen, wenn sich das Auto mehr als 30 m bewegt hat. */
    private function UpdateAddress(float $lat, float $lon): void
    {
        $cached = json_decode($this->ReadAttributeString('Address'), true);
        if (is_array($cached) && isset($cached['lat'], $cached['lon'])
            && self::GeoDistance((float) $cached['lat'], (float) $cached['lon'], $lat, $lon) < 30) {
            return;
        }
        if (time() - $this->ReadAttributeInteger('GeocodeFailed') < 300) {
            return;                                     // nach einem Fehler 5 Minuten Pause
        }

        $address = $this->GeocodeLookup($lat, $lon);
        if ($address === null) {
            $this->WriteAttributeInteger('GeocodeFailed', time());
            $this->SendDebug('Adresse', 'Keine Antwort von OpenStreetMap', 0);
            return;
        }

        $this->WriteAttributeInteger('GeocodeFailed', 0);
        $this->WriteAttributeString('Address', (string) json_encode(['lat' => round($lat, 6), 'lon' => round($lon, 6)] + $address, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->MaintainOptional('Address', 'Adresse', VARIABLETYPE_STRING, self::PValue('', 0, 'location-dot'), 55);
        $this->SetValue('Address', self::GeocodeText($address));
    }

    /** Schalter für Adresse und Kartendienst übernehmen. */
    private function ApplyLocationSettings(): void
    {
        if (!$this->ReadPropertyBoolean('ShowAddress') || !$this->ReadPropertyBoolean('EnableLocation')) {
            if (@$this->GetIDForIdent('Address') !== false) {
                $this->MaintainVariable('Address', 'Adresse', VARIABLETYPE_STRING, self::PValue('', 0, 'location-dot'), 55, false);
            }
        }
        // Link sofort auf den gewählten Kartendienst umstellen
        if (@$this->GetIDForIdent('MapLink') !== false && @$this->GetIDForIdent('Latitude') !== false) {
            $lat = (float) $this->GetValue('Latitude');
            $lon = (float) $this->GetValue('Longitude');
            if ($lat != 0.0 || $lon != 0.0) {
                $this->SetValue('MapLink', $this->MapUrl($lat, $lon));
            }
        }
    }

    /** @return array{0:float,1:float}|null Breite/Länge aus der Instanz "Location Control" */
    private function HomePosition(): ?array
    {
        $ids = IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL_GUID);
        if (count($ids) === 0) {
            return null;
        }
        $location = json_decode((string) @IPS_GetProperty($ids[0], 'Location'), true);
        if (!is_array($location) || !isset($location['latitude'], $location['longitude'])) {
            return null;
        }
        if ((float) $location['latitude'] == 0.0 && (float) $location['longitude'] == 0.0) {
            return null;
        }
        return [(float) $location['latitude'], (float) $location['longitude']];
    }

    private static function Distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** Sammelt offene Teile ein; liefert false, wenn keine Daten vorliegen. */
    private function CollectOpen(array $data, array $names, array &$open): bool
    {
        $known = false;
        foreach ($names as $key => $name) {
            $value = self::Value($data, $key);
            if ($value === null || $value === 'UNSPECIFIED') {
                continue;
            }
            $known = true;
            if ($value === 'OPEN' || $value === 'AJAR') {
                $open[] = $name . ($value === 'AJAR' ? ' (angelehnt)' : '');
            }
        }
        return $known;
    }

    private static function ChargingStatusCode(string $status): int
    {
        return [
            'IDLE'           => 1,
            'CHARGING'       => 2,
            'DONE'           => 3,
            'SCHEDULED'      => 4,
            'SMART_CHARGING' => 5,
            'FAULT'          => 6,
            'ERROR'          => 6,
            'DISCHARGING'    => 7
        ][$status] ?? 0;
    }

    private function ReportError(string $message, int $status): void
    {
        $this->SetValue('LastError', $message);
        $this->SetStatus($status);
        $this->PushTile();
        $this->LogMessage($message, KL_WARNING);
    }

    private function Notify(string $title, string $text): void
    {
        $id = $this->ReadPropertyInteger('NotifyInstance');
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return;
        }
        $prefix = IPS_GetModule(IPS_GetInstance($id)['ModuleInfo']['ModuleID'])['Prefix'] ?? '';
        try {
            if ($prefix === 'VISU' && function_exists('VISU_PostNotification')) {
                VISU_PostNotification($id, $title, $text, 'Car', 0);
            } elseif ($prefix === 'WFC' && function_exists('WFC_PushNotification')) {
                WFC_PushNotification($id, $title, $text, '', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Push', $e->getMessage(), 0);
        }
    }

    /** Variable erst anlegen, wenn das Fahrzeug den Wert liefert (Darstellung wird dabei aktualisiert). */
    /**
     * Variable nur schreiben, wenn sich der Wert wirklich ändert.
     * Spart bei jedem Abruf Dutzende Schreibvorgänge samt Ereignissen und Nachrichten.
     */
    protected function SetValue(string $Ident, mixed $Value): bool
    {
        if (@$this->GetIDForIdent($Ident) !== false && $this->GetValue($Ident) === $Value) {
            return true;
        }
        return parent::SetValue($Ident, $Value);
    }

    private function MaintainOptional(string $ident, string $name, int $type, string|array $presentation, int $position): void
    {
        $key = 'Maintained.' . $ident;
        if (@$this->GetIDForIdent($ident) === false || $this->GetBuffer($key) === '') {
            $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
            $this->SetBuffer($key, '1');
        }
    }

    private function CreateVariables(): void
    {
        // Darstellungen (ab Symcon 8.0) statt eigener Variablenprofile
        $this->RegisterVariableInteger('BatteryLevel', 'Akkustand', self::PBattery(), 1);
        $this->RegisterVariableInteger('ElectricRange', 'Reichweite elektrisch', self::PValue(' km', 0, 'route'), 2);
        $this->RegisterVariableInteger('ChargingStatus', 'Ladestatus', self::PEnum(self::CHARGING_OPTIONS, 'charging-station'), 3);
        $this->RegisterVariableBoolean('CableConnected', 'Ladekabel angeschlossen', self::PBool('Nicht angeschlossen', 'Angeschlossen', 'plug', 0x2ECC71), 4);
        $this->RegisterVariableInteger('Odometer', 'Kilometerstand', self::PValue(' km', 0, 'gauge') + ['THOUSANDS_SEPARATOR' => '.'], 22);
        $this->RegisterVariableString('Model', 'Fahrzeug', self::PValue('', 0, 'car'), 30);
        $this->RegisterVariableFloat('BatteryCapacity', 'Akkugröße', self::PValue(' kWh', 1, 'battery-full'), 31);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', self::PDateTime(), 40);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', self::PValue('', 0, 'triangle-exclamation'), 41);
    }

    // =================================================================
    // Darstellungen (Symcon >= 8.0)
    // =================================================================

    private static function PValue(string $suffix, int $digits, string $icon): array
    {
        $p = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $icon];
        if ($suffix !== '') {
            $p['SUFFIX'] = $suffix;
        }
        if ($digits > 0) {
            $p['DIGITS'] = $digits;
        }
        return $p;
    }

    private static function PBattery(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'TEMPLATE' => VARIABLE_TEMPLATE_VALUE_PRESENTATION_BATTERY];
    }

    /** @param array $options Liste aus [Wert, Text, Symbol, Farbe] */
    private static function PEnum(array $options, string $icon): array
    {
        $list = [];
        foreach ($options as [$value, $caption, $optIcon, $color]) {
            $list[] = ['Value' => $value, 'Caption' => $caption, 'IconActive' => $optIcon !== '', 'IconValue' => $optIcon, 'Color' => $color];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => $icon, 'DISPLAY' => 2, 'OPTIONS' => json_encode($list, JSON_UNESCAPED_UNICODE)];
    }

    /** Nur lesbarer Ja/Nein-Wert mit Text und Farbe */
    private static function PBool(string $false, string $true, string $icon, int $colorTrue, int $colorFalse = -1): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => $icon,
            'OPTIONS'      => json_encode([
                ['Value' => false, 'Caption' => $false, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => $colorFalse !== -1, 'ColorValue' => $colorFalse],
                ['Value' => true, 'Caption' => $true, 'IconActive' => false, 'IconValue' => '', 'ColorActive' => $colorTrue !== -1, 'ColorValue' => $colorTrue]
            ], JSON_UNESCAPED_UNICODE)
        ];
    }

    private static function PDateTime(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'DATE' => 1, 'MONTH_TEXT' => false, 'DAY_OF_THE_WEEK' => false, 'TIME' => 1];
    }

    private static function ChargingText(int $status): string
    {
        foreach (self::CHARGING_OPTIONS as [$value, $text]) {
            if ($value === $status) {
                return $text;
            }
        }
        return '';
    }
}

class VolvoLoginException extends Exception
{
}

class VolvoForbiddenException extends Exception
{
}
