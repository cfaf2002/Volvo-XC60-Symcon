<?php

/**
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Eigene Kachel für die Kachel-Visualisierung (HTML-SDK).
 * Das HTML liegt in tile.html; die Daten werden als JSON geschickt.
 */
trait VolvoTile
{
    /** JSON so einbetten, dass kein Wert das Skript der Kachel beenden kann (z. B. "</script>"). */
    private const TILE_JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;   // kaputte Zeichen aus Fremddaten ersetzen statt Kachel abbrechen

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
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
            // Farbschema: 0 = Symcon-Design, 1 = Dunkel, 2 = Hell
            'theme'       => max(0, min(2, $this->ReadPropertyInteger('TileTheme')))
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
     * auf 1200 Pixel verkleinert und im Buffer zwischengespeichert (nicht im Attribut,
     * das in der settings.json landet).
     */
    private function TileImageDataUrl(string $property): string
    {
        $base64 = trim($this->ReadPropertyString($property));
        if ($base64 === '') {
            return '';
        }
        $key = $property . ':' . md5($base64);
        $cache = json_decode($this->GetBuffer('ImageCache'), true) ?: [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $raw = base64_decode($base64, true);
        $url = $raw === false ? '' : self::ShrinkImage($raw, 1200);
        $this->SetBuffer('ImageCache', (string) json_encode([$key => $url]));
        return $url;
    }

    /**
     * Erlaubt nur echte Bilder (PNG, JPEG, WebP, GIF) und verkleinert sie auf höchstens $max Pixel.
     * Bilder ohne Transparenz werden JPEG (viel kleiner als PNG), freigestellte Bilder bleiben PNG.
     * Ist das Ergebnis noch zu groß, wird schrittweise kleiner gerechnet – die Kachel soll schnell öffnen.
     */
    private static function ShrinkImage(string $raw, int $max): string
    {
        if (strncmp($raw, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        } elseif (strncmp($raw, "\xFF\xD8", 2) === 0) {
            $mime = 'image/jpeg';
        } elseif (strncmp($raw, 'RIFF', 4) === 0 && substr($raw, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
        } elseif (strncmp($raw, 'GIF8', 4) === 0) {
            $mime = 'image/gif';
        } else {
            return '';                        // kein Bild -> nicht einbetten
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[0] * $info[1] > 40000000 || !function_exists('imagecreatefromstring')
            || ($img = @imagecreatefromstring($raw)) === false) {
            // ohne Bildbearbeitung (oder bei Riesenbildern) nur kleine Bilder unverändert übernehmen
            return strlen($raw) <= 700000 ? 'data:' . $mime . ';base64,' . base64_encode($raw) : '';
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $alpha = self::HasAlpha($raw, $mime);
        $best = '';
        foreach ([[$max, 80], [(int) round($max * 0.8), 72], [(int) round($max * 0.6), 65]] as [$size, $quality]) {
            $f = min(1.0, $size / max($w, $h));
            $nw = max(1, (int) round($w * $f));
            $nh = max(1, (int) round($h * $f));
            $out = imagecreatetruecolor($nw, $nh);
            if ($alpha) {                     // Transparenz (z. B. freigestelltes Auto) erhalten
                imagealphablending($out, false);
                imagesavealpha($out, true);
                imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            }
            imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            $alpha ? imagepng($out, null, 9) : imagejpeg($out, null, $quality);
            $data = (string) ob_get_clean();
            unset($out);
            // ein kleines Original ist manchmal schon kleiner als die Neuberechnung
            if ($f >= 1.0 && strlen($raw) <= strlen($data) && ($alpha ? $mime === 'image/png' : $mime === 'image/jpeg')) {
                $data = $raw;
            }
            $best = 'data:' . ($data === $raw ? $mime : ($alpha ? 'image/png' : 'image/jpeg')) . ';base64,' . base64_encode($data);
            if (strlen($data) <= 300000) {
                break;
            }
        }
        unset($img);
        return strlen($best) * 3 / 4 <= 700000 ? $best : '';
    }

    /** Hat das Bild einen Alphakanal? (PNG: Farbtyp 4/6 oder tRNS, WebP: ALPH/VP8X-Flag, GIF: immer möglich) */
    private static function HasAlpha(string $raw, string $mime): bool
    {
        if ($mime === 'image/png') {
            $type = ord($raw[25] ?? "\0");
            return $type === 4 || $type === 6 || str_contains(substr($raw, 0, 4096), 'tRNS');
        }
        if ($mime === 'image/webp') {
            return substr($raw, 12, 4) === 'VP8X' ? (ord($raw[20] ?? "\0") & 0x10) !== 0 : false;
        }
        return $mime === 'image/gif';
    }
}
