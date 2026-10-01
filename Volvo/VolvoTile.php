<?php

declare(strict_types=1);

/**
 * Eigene Kachel für die Kachel-Visualisierung (HTML-SDK).
 * Das HTML liegt in module.html; die Daten werden als JSON geschickt.
 */
trait VolvoTile
{
    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->TileData(true))) . ');</script>';
    }

    /** @param bool $withBackground Hintergrundbild mitschicken (nur beim Laden/nach Änderung) */
    private function PushTile(bool $withBackground = false): void
    {
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode($this->TileData($withBackground)));
        }
    }

    private function TileData(bool $withBackground = false): array
    {
        $vehicle = json_decode($this->ReadAttributeString('Vehicle'), true) ?: [];
        $get = function (string $ident) {
            return @$this->GetIDForIdent($ident) !== false ? $this->GetValue($ident) : null;
        };

        $status = (int) $get('ChargingStatus');
        $statusText = [0 => '', 1 => 'Bereit', 2 => 'Lädt', 3 => 'Fertig geladen', 4 => 'Ladung geplant',
                       5 => 'Smart Charging', 6 => 'Ladefehler', 7 => 'Entlädt'][$status] ?? '';
        $cable = (bool) $get('CableConnected');
        $lastUpdate = (int) $get('LastUpdate');
        $image = (string) ($vehicle['image'] ?? '');

        $data = [
            'model'       => (string) $get('Model'),
            'soc'         => $get('BatteryLevel') !== null && $lastUpdate > 0 ? (int) $get('BatteryLevel') : null,
            'rangeE'      => $get('ElectricRange'),
            'rangeFuel'   => $get('FuelRange'),
            'fuel'        => $get('FuelAmount'),
            'status'      => $status,
            'statusText'  => $statusText,
            'cable'       => $cable,
            'timeLeft'    => $get('ChargingTimeLeft'),
            'target'      => $get('TargetLevel'),
            'odometer'    => $get('Odometer'),
            'locked'      => $get('Locked'),
            'doorsClosed' => $get('DoorsClosed'),
            'winClosed'   => $get('WindowsClosed'),
            'openParts'   => $get('OpenParts'),
            'atHome'      => $get('AtHome'),
            'distance'    => $get('DistanceHome'),
            'mapLink'     => $get('MapLink'),
            'address'     => $this->TileAddress(),
            'image'       => $image !== '' ? preg_replace('/([?&]w=)\d+/', '${1}800', $image) : '',
            'ok'          => $this->GetStatus() === 102,
            'error'       => (string) $get('LastError'),
            'updated'     => $lastUpdate > 0 ? date('H:i', $lastUpdate) : '–'
        ];

        if ($withBackground) {
            $data['bg'] = [
                'image' => $this->TileImageDataUrl('TileBackground'),
                'dim'   => max(0, min(90, $this->ReadPropertyInteger('TileDim'))) / 100
            ];
        }

        return $data;
    }

    /** @return array{name:string,street:string,city:string}|null */
    private function TileAddress(): ?array
    {
        $a = json_decode($this->GetAddressData(), true);
        if (!is_array($a) || (($a['street'] ?? '') === '' && ($a['name'] ?? '') === '')) {
            return null;
        }
        return ['name' => (string) ($a['name'] ?? ''), 'street' => (string) ($a['street'] ?? ''), 'city' => (string) ($a['city'] ?? '')];
    }

    /** Bild aus einer Eigenschaft (SelectFile, base64) als data-URL. */
    private function TileImageDataUrl(string $property): string
    {
        $base64 = trim($this->ReadPropertyString($property));
        if ($base64 === '') {
            return '';
        }
        $head = base64_decode(substr($base64, 0, 24), true) ?: '';
        if (strncmp($head, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        } elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
        } else {
            $mime = 'image/jpeg';
        }
        return 'data:' . $mime . ';base64,' . $base64;
    }
}
