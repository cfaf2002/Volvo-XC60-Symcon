<?php

declare(strict_types=1);

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
    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const MIN_MOVE_METERS = 30;     // kleinere Sprünge sind GPS-Rauschen
    private const MAX_POINTS = 5000;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('VolvoInstance', 0);
        $this->RegisterPropertyBoolean('RecordHistory', false);
        $this->RegisterPropertyInteger('HistoryDays', 7);
        $this->RegisterPropertyBoolean('ShowHome', true);
        $this->RegisterPropertyInteger('Zoom', 15);
        $this->RegisterPropertyBoolean('DarkMap', true);

        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeInteger('Watched', 0);
        $this->RegisterAttributeString('LastPos', '');
        $this->RegisterAttributeInteger('ParkedSince', 0);

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

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
            $moved = !is_array($last) || self::Distance($last['lat'], $last['lon'], $pos['lat'], $pos['lon']) >= self::MIN_MOVE_METERS;

            if ($moved) {
                $this->WriteAttributeString('LastPos', json_encode($pos));
                $this->WriteAttributeInteger('ParkedSince', time());

                if ($this->ReadPropertyBoolean('RecordHistory')) {
                    $history = $this->ReadHistory();
                    $history[] = [time(), round($pos['lat'], 6), round($pos['lon'], 6)];
                    $this->WriteHistory($history);
                }
            }
        }

        $this->PushTile();
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
            'hint'        => $this->Hint($pos)
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

    private static function Distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }
}
