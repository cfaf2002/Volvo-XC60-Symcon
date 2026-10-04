<?php

/**
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Eigene Kachel für die Kachel-Visualisierung (HTML-SDK).
 * Das HTML liegt in module.html; die Daten werden als JSON geschickt.
 */
trait VolvoTile
{
    /** JSON so einbetten, dass kein Wert das Skript der Kachel beenden kann (z. B. "</script>"). */
    private const TILE_JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;   // kaputte Zeichen aus Fremddaten ersetzen statt Kachel abbrechen

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/module.html');
        $data = json_encode($this->TileData(true), self::TILE_JSON);
        $this->SetBuffer('TileHash', '');
        return $html . '<script>handleMessage(' . json_encode($data, self::TILE_JSON) . ');</script>';
    }

    /** @param bool $withBackground Hintergrundbild mitschicken (nur beim Laden/nach Änderung) */
    private function PushTile(bool $withBackground = false): void
    {
        $json = json_encode($this->TileData($withBackground), self::TILE_JSON);

        // Nur senden, wenn sich etwas geändert hat
        $hash = md5($json);
        if (!$withBackground && $this->GetBuffer('TileHash') === $hash) {
            return;
        }
        $this->SetBuffer('TileHash', $hash);
        $this->UpdateVisualizationValue($json);
    }

    private function TileData(bool $withBackground = false): array
    {
        $vehicle = json_decode($this->ReadAttributeString('Vehicle'), true) ?: [];
        $get = function (string $ident) {
            return @$this->GetIDForIdent($ident) !== false ? $this->GetValue($ident) : null;
        };

        $status = (int) $get('ChargingStatus');
        $statusText = $status === 0 ? '' : ($status === 4 ? 'Ladung geplant' : ($status === 6 ? 'Ladefehler' : self::ChargingText($status)));
        $cable = (bool) $get('CableConnected');
        $lastUpdate = (int) $get('LastUpdate');
        $image = (string) ($vehicle['image'] ?? '');
        $mapLink = (string) $get('MapLink');

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
            // Nur https-Adressen an die Kachel geben
            'mapLink'     => str_starts_with($mapLink, 'https://') ? $mapLink : '',
            // Tippen auf den Standort öffnet die Kachel „Volvo Karte“ (openObject, ab Symcon 8.2)
            'mapObject'   => $this->ReadPropertyInteger('MapService') === 3 ? $this->MapInstance() : 0,
            'address'     => $this->TileAddress(),
            'image'       => str_starts_with($image, 'https://') ? preg_replace('/([?&]w=)\d+/', '${1}800', $image) : '',
            'ok'          => $this->GetStatus() === 102,
            'error'       => (string) $get('LastError'),
            'updated'     => $lastUpdate > 0 ? date('H:i', $lastUpdate) : '–',
            'theme'       => ['symcon', 'dark', 'light'][$this->ReadPropertyInteger('TileTheme')] ?? 'symcon'
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

    /**
     * Bild aus einer Eigenschaft (SelectFile, base64) als data-URL – einmal geprüft,
     * auf 1600 Pixel verkleinert und zwischengespeichert.
     */
    private function TileImageDataUrl(string $property): string
    {
        $base64 = trim($this->ReadPropertyString($property));
        if ($base64 === '') {
            return '';
        }
        $key = $property . ':' . md5($base64);
        $cache = json_decode($this->ReadAttributeString('ImageCache'), true) ?: [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $raw = base64_decode($base64, true);
        $url = $raw === false ? '' : self::ShrinkImage($raw, 1600);
        $this->WriteAttributeString('ImageCache', json_encode([$key => $url]));
        return $url;
    }

    /** Erlaubt nur echte Bilder (PNG, JPEG, WebP) und verkleinert sie auf $max Pixel. */
    private static function ShrinkImage(string $raw, int $max): string
    {
        if (strncmp($raw, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        } elseif (strncmp($raw, "\xFF\xD8", 2) === 0) {
            $mime = 'image/jpeg';
        } elseif (strncmp($raw, 'RIFF', 4) === 0 && substr($raw, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
        } else {
            return '';
        }

        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($raw)) !== false) {
            $w = imagesx($img);
            $h = imagesy($img);
            if (max($w, $h) > $max) {
                $f = $max / max($w, $h);
                $out = imagecreatetruecolor(max(1, (int) round($w * $f)), max(1, (int) round($h * $f)));
                imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
                ob_start();
                imagejpeg($out, null, 82);
                $raw = (string) ob_get_clean();
                $mime = 'image/jpeg';
            }
        }
        return 'data:' . $mime . ';base64,' . base64_encode($raw);
    }
}
