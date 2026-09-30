<?php

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
class Volvo extends IPSModule
{
    private const AUTHORIZE_URL = 'https://volvoid.eu.volvocars.com/as/authorization.oauth2';
    private const TOKEN_URL = 'https://volvoid.eu.volvocars.com/as/token.oauth2';
    private const API = 'https://api.volvocars.com';
    private const CONNECTED = '/connected-vehicle/v2/vehicles';
    private const ENERGY = '/energy/v2/vehicles';

    private const WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const CONNECT_GUID = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
    private const HOOK = '/hook/volvo';

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
        'energy:state:read',
        'energy:capability:read'
    ];

    // =================================================================
    // Symcon-Lebenszyklus
    // =================================================================

    public function Create()
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

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('CodeVerifier', '');
        $this->RegisterAttributeString('AuthState', '');
        $this->RegisterAttributeString('ActiveVin', '');
        $this->RegisterAttributeString('Vehicle', '{}');
        $this->RegisterAttributeInteger('LoginNotified', 0);

        $this->RegisterTimer('UpdateTimer', 0, 'VOLVO_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->CreateProfiles();
        $this->CreateVariables();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->RegisterHook(self::HOOK);

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

        if ($this->ReadPropertyString('ApiKey') === '' || $this->ReadPropertyString('ClientId') === ''
            || $this->ReadPropertyString('ClientSecret') === '') {
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

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    /** Formular mit Hinweis auf die passende Weiterleitungs-Adresse ergänzen. */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $suggest = $this->SuggestedRedirectUri();
        $loggedIn = $this->ReadAttributeString('RefreshToken') !== '';
        $vehicle = json_decode($this->ReadAttributeString('Vehicle'), true) ?: [];

        // "Bei Volvo anmelden" erst freigeben, wenn alle Angaben da sind -
        // sonst würde der Browser einen Hinweistext als Adresse öffnen
        $ready = $this->ReadPropertyString('ApiKey') !== '' && $this->ReadPropertyString('ClientId') !== ''
            && $this->ReadPropertyString('ClientSecret') !== '' && $this->RedirectUri() !== '';
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
        if ($this->ReadPropertyString('ClientId') === '' || $redirect === '') {
            return 'about:blank';
        }

        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(16));
        $this->WriteAttributeString('CodeVerifier', $verifier);
        $this->WriteAttributeString('AuthState', $state);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->ReadPropertyString('ClientId'),
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
            $code = (string) ($query['code'] ?? '');
            if (isset($query['state']) && $query['state'] !== $this->ReadAttributeString('AuthState')) {
                return 'Die Adresse gehört nicht zur letzten Anmeldung. Bitte erneut „Bei Volvo anmelden“ klicken.';
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
    protected function ProcessHookData()
    {
        $code = (string) ($_GET['code'] ?? '');
        $state = (string) ($_GET['state'] ?? '');

        header('Content-Type: text/html; charset=utf-8');

        if ($state === '' || $state !== $this->ReadAttributeString('AuthState')) {
            echo self::HookPage('Anmeldung nicht zugeordnet', 'Bitte in Symcon erneut „Bei Volvo anmelden“ klicken.', false);
            return;
        }
        if ($code === '') {
            $error = (string) ($_GET['error_description'] ?? $_GET['error'] ?? 'Kein Code erhalten.');
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
        $basic = base64_encode($this->ReadPropertyString('ClientId') . ':' . $this->ReadPropertyString('ClientSecret'));

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
            $this->LoadVehicleDetails($vin);

            // Energie (Akku, Laden) - ältere Hybride liefern hier nicht alles
            // Einzelne Bereiche dürfen fehlen (Recht nicht freigegeben oder
            // vom Fahrzeug nicht unterstützt) - dann einfach überspringen
            $energy = $this->Optional(fn () => $this->Api(self::ENERGY . '/' . $vin . '/state'));
            $fuel = $this->Optional(fn () => $this->ApiData(self::CONNECTED . '/' . $vin . '/fuel'));
            $stats = $this->Optional(fn () => $this->ApiData(self::CONNECTED . '/' . $vin . '/statistics'));
            $odo = $this->Optional(fn () => $this->ApiData(self::CONNECTED . '/' . $vin . '/odometer'));
            $doors = $this->Optional(fn () => $this->ApiData(self::CONNECTED . '/' . $vin . '/doors'));

            $this->Process($energy, $fuel, $stats, $odo, $doors);

            $this->SetValue('LastError', '');
            $this->SetValue('LastUpdate', time());
            if ($this->GetStatus() !== 102) {
                $this->SetStatus(102);
            }
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

        $list = $this->Api(self::CONNECTED);
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

    private function Process(array $energy, array $fuel, array $stats, array $odo, array $doors): void
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
            $this->MaintainOptional('ChargingTimeLeft', 'Restladezeit', VARIABLETYPE_INTEGER, 'VOLVO.Minutes', 7);
            $this->SetValue('ChargingTimeLeft', (int) $remaining);
        }

        $target = self::EnergyValue($energy, 'targetBatteryChargeLevel');
        if ($target !== null) {
            $this->MaintainOptional('TargetLevel', 'Ziel-Akkustand (Fahrzeug)', VARIABLETYPE_INTEGER, 'VOLVO.Percent', 8);
            $this->SetValue('TargetLevel', (int) round((float) $target));
        }

        $fuelAmount = self::Value($fuel, 'fuelAmount');
        if ($fuelAmount !== null) {
            $this->MaintainOptional('FuelAmount', 'Tankinhalt', VARIABLETYPE_FLOAT, 'VOLVO.Liter', 20);
            $this->SetValue('FuelAmount', round((float) $fuelAmount, 1));
        }

        $tankRange = self::Value($stats, 'distanceToEmptyTank');
        if ($tankRange !== null) {
            $this->MaintainOptional('FuelRange', 'Reichweite Tank', VARIABLETYPE_INTEGER, 'VOLVO.Km', 21);
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
            $this->SetValue('Locked', $lock === 'LOCKED');
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
            'vcc-api-key: ' . $this->ReadPropertyString('ApiKey'),
            'Accept: application/json'
        ];

        [$code, $raw] = $this->HttpRequest('GET', self::API . $path, $headers, null);

        if ($code === 401) {
            // Token eventuell vorzeitig ungültig: einmal neu holen
            $this->WriteAttributeInteger('TokenExpires', 0);
            $headers[0] = 'Authorization: Bearer ' . $this->GetAccessToken();
            [$code, $raw] = $this->HttpRequest('GET', self::API . $path, $headers, null);
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Fahrgestellnummer und Tokens nicht im Debug zeigen
        $shown = preg_replace('#/vehicles/[A-Z0-9]{17}#', '/vehicles/***', preg_replace('#\?.*$#', '', $url));
        $this->SendDebug($method, $shown . ' -> HTTP ' . $code, 0);

        if ($raw === false) {
            throw new Exception('Verbindungsfehler: ' . $error);
        }
        if (strpos($url, 'volvoid') === false) {
            $this->SendDebug('Antwort', mb_substr(preg_replace('#[A-Z0-9]{17}#', '***', (string) $raw), 0, 1500), 0);
        }

        return [$code, (string) $raw];
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

    private function RegisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }

        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        foreach ($hooks as $index => $entry) {
            if ($entry['Hook'] === $hook) {
                if ($entry['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }

        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    private static function HookPage(string $title, string $text, bool $ok): string
    {
        $color = $ok ? '#2ecc71' : '#e74c3c';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Volvo – ' . htmlspecialchars($title) . '</title></head>'
            . '<body style="font-family:Segoe UI,Roboto,Arial,sans-serif;background:#1e2227;color:#eee;display:flex;'
            . 'align-items:center;justify-content:center;height:100vh;margin:0">'
            . '<div style="text-align:center;padding:24px"><div style="font-size:44px;color:' . $color . '">' . ($ok ? '✓' : '✕') . '</div>'
            . '<h2 style="margin:8px 0">' . htmlspecialchars($title) . '</h2><p style="opacity:.8">' . htmlspecialchars($text) . '</p></div>'
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

    private function MaintainOptional(string $ident, string $name, int $type, string $profile, int $position): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->MaintainVariable($ident, $name, $type, $profile, $position, true);
        }
    }

    private function CreateProfiles(): void
    {
        $this->Profile('VOLVO.Percent', VARIABLETYPE_INTEGER, ' %', 'Battery', 0, 100);
        $this->Profile('VOLVO.Km', VARIABLETYPE_INTEGER, ' km', 'Distance', 0, 0);
        $this->Profile('VOLVO.Minutes', VARIABLETYPE_INTEGER, ' min', 'Clock', 0, 0);
        $this->Profile('VOLVO.Liter', VARIABLETYPE_FLOAT, ' l', 'Drops', 0, 0);
        $this->Profile('VOLVO.kWh', VARIABLETYPE_FLOAT, ' kWh', 'Electricity', 0, 0);

        $this->Profile('VOLVO.ChargingStatus', VARIABLETYPE_INTEGER, '', 'Electricity', 0, 0);
        foreach ([
            [0, 'Unbekannt', 0x7F8C8D], [1, 'Bereit', 0x95A5A6], [2, 'Lädt', 0x2ECC71], [3, 'Fertig geladen', 0x3498DB],
            [4, 'Geplant', 0xF1C40F], [5, 'Smart Charging', 0x1ABC9C], [6, 'Fehler', 0xE74C3C], [7, 'Entlädt', 0xE67E22]
        ] as [$v, $t, $c]) {
            IPS_SetVariableProfileAssociation('VOLVO.ChargingStatus', $v, $t, '', $c);
        }
    }

    private function Profile(string $name, int $type, string $suffix, string $icon, float $min, float $max): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileIcon($name, $icon);
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, 1);
        }
        IPS_SetVariableProfileValues($name, $min, $max, 1);
    }

    private function CreateVariables(): void
    {
        $this->RegisterVariableInteger('BatteryLevel', 'Akkustand', 'VOLVO.Percent', 1);
        $this->RegisterVariableInteger('ElectricRange', 'Reichweite elektrisch', 'VOLVO.Km', 2);
        $this->RegisterVariableInteger('ChargingStatus', 'Ladestatus', 'VOLVO.ChargingStatus', 3);
        $this->RegisterVariableBoolean('CableConnected', 'Ladekabel angeschlossen', '~Switch', 4);
        $this->RegisterVariableInteger('Odometer', 'Kilometerstand', 'VOLVO.Km', 22);
        $this->RegisterVariableBoolean('Locked', 'Verriegelt', '~Lock', 23);
        $this->RegisterVariableString('Model', 'Fahrzeug', '', 30);
        $this->RegisterVariableFloat('BatteryCapacity', 'Akkugröße', 'VOLVO.kWh', 31);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 40);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 41);
    }
}

class VolvoLoginException extends Exception
{
}

class VolvoForbiddenException extends Exception
{
}
