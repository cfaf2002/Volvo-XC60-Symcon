<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VolvoGeocoder.php';

/**
 * Volvo Karte
 *
 * Kachel mit einer OpenStreetMap-Karte, die den Standort aus einer
 * Volvo-Instanz anzeigt und bei jedem neuen Standort mitwandert.
 * Optional wird der Verlauf mitgeschrieben (Schalter in der Instanz).
 *
 * Autor: Armin Frohwerk
 */
class VolvoKarte extends IPSModule
{
    use VolvoGeocoder;

    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const MIN_MOVE_METERS = 30;     // kleinere Sprünge sind GPS-Rauschen
    private const MAX_POINTS = 5000;
    private const GEOCODE_RETRY = 300;      // nach Fehler frühestens nach 5 Minuten erneut
    private const MARKER_MAX_PX = 256;      // hochgeladene Bilder werden auf diese Größe verkleinert

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('VolvoInstance', 0);
        $this->RegisterPropertyBoolean('RecordHistory', false);
        $this->RegisterPropertyInteger('HistoryDays', 7);
        $this->RegisterPropertyBoolean('ShowHome', true);
        $this->RegisterPropertyInteger('Zoom', 15);
        $this->RegisterPropertyBoolean('DarkMap', true);
        $this->RegisterPropertyBoolean('ShowAddress', true);
        $this->RegisterPropertyInteger('MarkerType', 0);       // 0 Symbol, 1 Volvo-Fahrzeugbild, 2 eigenes Bild
        $this->RegisterPropertyString('MarkerImage', '');
        $this->RegisterPropertyBoolean('MarkerRound', true);
        $this->RegisterPropertyInteger('MarkerSize', 56);

        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeInteger('Watched', 0);
        $this->RegisterAttributeString('LastPos', '');
        $this->RegisterAttributeInteger('ParkedSince', 0);
        $this->RegisterAttributeString('Address', '');
        $this->RegisterAttributeInteger('GeocodeFailed', 0);
        $this->RegisterAttributeString('MarkerCache', '');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->MaintainVariable('Address', 'Adresse', 3, '', 1, $this->ReadPropertyBoolean('ShowAddress'));
        if ($this->ReadPropertyBoolean('ShowAddress') && ($vid = @$this->GetIDForIdent('Address'))) {
            SetValueString($vid, $this->GetAddress());
        }
        $this->BuildMarkerCache();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // Auf "Letzte Aktualisierung" der Volvo-Instanz lauschen: die gibt es
        // immer, auch wenn die Standort-Variablen erst später entstehen
        $old = $this->ReadAttributeInteger('Watched');
        $new = $this->SourceVariable('LastUpdate');
        if ($old > 0 && $old !== $new) {
            $this->UnregisterMessage($old, VM_UPDATE);
        }
        if ($new > 0 && $old !== $new) {
            $this->RegisterMessage($new, VM_UPDATE);
        }
        $this->WriteAttributeInteger('Watched', $new);

        if ($this->ReadPropertyInteger('VolvoInstance') <= 0 || $new === 0) {
            $this->SetStatus(201);
        } else {
            $this->SetStatus(102);
            $this->Refresh();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE && $SenderID === $this->ReadAttributeInteger('Watched')) {
            $this->Refresh();
        }
    }

    // =================================================================
    // Öffentliche Funktionen
    // =================================================================

    /** Standort neu lesen, Verlauf ergänzen und Kachel aktualisieren. */
    public function Refresh(): void
    {
        $pos = $this->CurrentPosition();

        if ($pos !== null) {
            $last = json_decode($this->ReadAttributeString('LastPos'), true);
            $moved = !is_array($last) || self::GeoDistance($last['lat'], $last['lon'], $pos['lat'], $pos['lon']) >= self::MIN_MOVE_METERS;

            if ($moved) {
                $this->WriteAttributeString('LastPos', json_encode($pos));
                $this->WriteAttributeInteger('ParkedSince', time());

                $this->WriteAttributeString('Address', '');     // neuer Ort -> Adresse neu ermitteln

                if ($this->ReadPropertyBoolean('RecordHistory')) {
                    $history = $this->ReadHistory();
                    $history[] = [time(), round($pos['lat'], 6), round($pos['lon'], 6)];
                    $this->WriteHistory($history);
                }
            }

            if ($this->ReadPropertyBoolean('ShowAddress')) {
                $this->UpdateAddress($pos);
            }
        }

        $this->PushTile();
    }

    /** Aktuelle Adresse als Text, z. B. „Hauptstraße 5, 49074 Osnabrück“. */
    public function GetAddress(): string
    {
        return self::GeocodeText(json_decode($this->ReadAttributeString('Address'), true));
    }

    /** Verlauf als JSON: [[Zeitstempel, Breite, Länge], ...] */
    public function GetHistory(): string
    {
        return json_encode($this->ReadHistory());
    }

    public function ClearHistory(): void
    {
        $this->WriteAttributeString('History', '[]');
        $this->PushTile();
    }

    // =================================================================
    // Kachel
    // =================================================================

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->TileData())) . ');</script>';
    }

    private function PushTile(): void
    {
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode($this->TileData()));
        }
    }

    private function TileData(): array
    {
        $pos = $this->CurrentPosition();
        $home = $this->ReadPropertyBoolean('ShowHome') ? $this->HomePosition() : null;

        $value = function (string $ident) {
            $id = $this->SourceVariable($ident);
            return $id > 0 ? GetValue($id) : null;
        };

        $model = (string) ($value('Model') ?? '');
        $lastUpdate = (int) ($value('LastUpdate') ?? 0);

        return [
            'pos'         => $pos,
            'home'        => $home,
            'track'       => $this->ReadPropertyBoolean('RecordHistory') ? $this->ReadHistory() : null,
            'zoom'        => max(3, min(19, $this->ReadPropertyInteger('Zoom'))),
            'dark'        => $this->ReadPropertyBoolean('DarkMap'),
            'model'       => $model,
            'soc'         => $value('BatteryLevel'),
            'atHome'      => $value('AtHome'),
            'distance'    => $value('DistanceHome'),
            'parkedSince' => $this->ReadAttributeInteger('ParkedSince'),
            'updated'     => $lastUpdate > 0 ? date('H:i', $lastUpdate) : '–',
            'hint'        => $this->Hint($pos),
            'address'     => $this->ReadPropertyBoolean('ShowAddress') ? (json_decode($this->ReadAttributeString('Address'), true) ?: null) : null,
            'marker'      => $this->MarkerData()
        ];
    }

    private function Hint(?array $pos): string
    {
        if ($this->ReadPropertyInteger('VolvoInstance') <= 0) {
            return 'Bitte in der Instanz „Volvo Karte“ die Volvo-Instanz auswählen.';
        }
        if ($pos === null) {
            return 'Noch kein Standort – in der Volvo-Instanz „Standort abrufen“ aktivieren (Recht location:read).';
        }
        return '';
    }

    // =================================================================
    // Adresse (Rückwärtssuche über OpenStreetMap/Nominatim)
    // =================================================================

    private function UpdateAddress(array $pos): void
    {
        $cached = json_decode($this->ReadAttributeString('Address'), true);

        // Ermittelt die Volvo-Instanz die Adresse schon selbst, wird sie übernommen (keine doppelte Abfrage)
        $fromVolvo = $this->VolvoAddress($pos);
        if ($fromVolvo !== null) {
            if ($fromVolvo !== $cached) {
                $this->StoreAddress($fromVolvo);
            }
            return;
        }

        if (is_array($cached)) {
            return;                                     // für diesen Ort schon bekannt
        }
        if (time() - $this->ReadAttributeInteger('GeocodeFailed') < self::GEOCODE_RETRY) {
            return;
        }

        $address = $this->GeocodeLookup($pos['lat'], $pos['lon']);
        if ($address === null) {
            $this->WriteAttributeInteger('GeocodeFailed', time());
            $this->SendDebug('Adresse', 'Keine Antwort von OpenStreetMap', 0);
            return;
        }
        $this->StoreAddress($address);
    }

    private function StoreAddress(array $address): void
    {
        $this->WriteAttributeString('Address', json_encode($address));
        $this->WriteAttributeInteger('GeocodeFailed', 0);
        $id = @$this->GetIDForIdent('Address');
        if ($id) {
            SetValueString($id, $this->GetAddress());
        }
    }

    /** Adresse aus der Volvo-Instanz, wenn sie zur aktuellen Position passt. */
    private function VolvoAddress(array $pos): ?array
    {
        $volvo = $this->ReadPropertyInteger('VolvoInstance');
        if ($volvo <= 0 || !@IPS_InstanceExists($volvo) || !function_exists('VOLVO_GetAddressData')) {
            return null;
        }
        $a = json_decode((string) @VOLVO_GetAddressData($volvo), true);
        if (!is_array($a) || !isset($a['lat'], $a['lon'])) {
            return null;
        }
        if (self::GeoDistance((float) $a['lat'], (float) $a['lon'], $pos['lat'], $pos['lon']) > self::MIN_MOVE_METERS * 2) {
            return null;
        }
        return ['name' => (string) ($a['name'] ?? ''), 'street' => (string) ($a['street'] ?? ''), 'city' => (string) ($a['city'] ?? '')];
    }

    // =================================================================
    // Fahrzeugsymbol
    // =================================================================

    private function MarkerData(): array
    {
        $size = max(28, min(140, $this->ReadPropertyInteger('MarkerSize')));
        $type = $this->ReadPropertyInteger('MarkerType');
        $src = '';

        if ($type === 1) {
            $src = $this->VolvoImageUrl();
        } elseif ($type === 2) {
            $src = $this->ReadAttributeString('MarkerCache');
        }

        if ($src === '') {
            return ['type' => 'icon', 'size' => $size];
        }
        // Volvo-Bild ist freigestellt -> frei stehend; eigenes Foto wahlweise rund
        $round = $type === 2 && $this->ReadPropertyBoolean('MarkerRound');
        return ['type' => $round ? 'round' : 'free', 'src' => $src, 'size' => $size];
    }

    private function VolvoImageUrl(): string
    {
        $volvo = $this->ReadPropertyInteger('VolvoInstance');
        if ($volvo <= 0 || !@IPS_InstanceExists($volvo) || !function_exists('VOLVO_GetVehicleImageUrl')) {
            return '';
        }
        $url = (string) @VOLVO_GetVehicleImageUrl($volvo);
        if (!preg_match('#^https://#', $url)) {
            return '';
        }
        // für das Symbol reicht eine kleine Breite
        return preg_replace('/([?&]w=)\d+/', '${1}300', $url);
    }

    /** Hochgeladenes Bild einmal verkleinern und als data-URL merken. */
    private function BuildMarkerCache(): void
    {
        $raw = base64_decode($this->ReadPropertyString('MarkerImage'), true);
        if ($raw === false || $raw === '') {
            $this->WriteAttributeString('MarkerCache', '');
            return;
        }
        $mime = self::ImageMime($raw);
        if ($mime === '') {
            $this->WriteAttributeString('MarkerCache', '');
            return;
        }

        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($raw)) !== false) {
            $w = imagesx($img);
            $h = imagesy($img);
            $square = $this->ReadPropertyBoolean('MarkerRound');
            if ($square) {
                // Mitte quadratisch ausschneiden, damit der Kreis gefüllt ist
                $side = min($w, $h);
                $sx = (int) (($w - $side) / 2);
                $sy = (int) (($h - $side) / 2);
                $tw = $th = min(self::MARKER_MAX_PX, $side);
                $sw = $sh = $side;
            } else {
                $sx = $sy = 0;
                $sw = $w;
                $sh = $h;
                $f = min(1, self::MARKER_MAX_PX / max($w, $h));
                $tw = max(1, (int) round($w * $f));
                $th = max(1, (int) round($h * $f));
            }
            $out = imagecreatetruecolor($tw, $th);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $img, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);
            ob_start();
            imagepng($out, null, 9);
            $raw = (string) ob_get_clean();
            $mime = 'image/png';
            imagedestroy($img);
            imagedestroy($out);
        }

        $this->WriteAttributeString('MarkerCache', 'data:' . $mime . ';base64,' . base64_encode($raw));
    }

    private static function ImageMime(string $raw): string
    {
        if (strncmp($raw, "\x89PNG", 4) === 0) {
            return 'image/png';
        }
        if (strncmp($raw, "\xFF\xD8", 2) === 0) {
            return 'image/jpeg';
        }
        if (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strncmp($raw, 'GIF8', 4) === 0) {
            return 'image/gif';
        }
        return '';
    }

    // =================================================================
    // Hilfen
    // =================================================================

    private function SourceVariable(string $ident): int
    {
        $volvo = $this->ReadPropertyInteger('VolvoInstance');
        if ($volvo <= 0 || !@IPS_InstanceExists($volvo)) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $volvo);
        return $id === false ? 0 : (int) $id;
    }

    /** @return array{lat:float,lon:float}|null */
    private function CurrentPosition(): ?array
    {
        $lat = $this->SourceVariable('Latitude');
        $lon = $this->SourceVariable('Longitude');
        if ($lat === 0 || $lon === 0) {
            return null;
        }
        $la = (float) GetValue($lat);
        $lo = (float) GetValue($lon);
        if ($la == 0.0 && $lo == 0.0) {
            return null;
        }
        return ['lat' => $la, 'lon' => $lo];
    }

    /** @return array{lat:float,lon:float,r:int}|null */
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

        $radius = 150;
        $volvo = $this->ReadPropertyInteger('VolvoInstance');
        if ($volvo > 0 && @IPS_InstanceExists($volvo)) {
            $radius = (int) @IPS_GetProperty($volvo, 'HomeRadius') ?: 150;
        }

        return ['lat' => (float) $location['latitude'], 'lon' => (float) $location['longitude'], 'r' => $radius];
    }

    private function ReadHistory(): array
    {
        $history = json_decode($this->ReadAttributeString('History'), true);
        return is_array($history) ? $history : [];
    }

    private function WriteHistory(array $history): void
    {
        $from = time() - max(1, $this->ReadPropertyInteger('HistoryDays')) * 86400;
        $history = array_values(array_filter($history, fn ($p) => $p[0] >= $from));
        $history = array_slice($history, -self::MAX_POINTS);
        $this->WriteAttributeString('History', json_encode($history));
    }
}
