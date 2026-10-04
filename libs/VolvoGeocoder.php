<?php

declare(strict_types=1);

/**
 * Adresse zu einer Position über OpenStreetMap (Nominatim) nachschlagen.
 * Wird von „Volvo Fahrzeug“ und „Volvo Karte“ gemeinsam genutzt.
 *
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */
trait VolvoGeocoder
{
    /** @return array{name:string,street:string,city:string}|null */
    protected function GeocodeLookup(float $lat, float $lon): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'format'          => 'jsonv2',
            'lat'             => sprintf('%.6F', $lat),
            'lon'             => sprintf('%.6F', $lon),
            'zoom'            => 18,
            'addressdetails'  => 1,
            'accept-language' => 'de'
        ]);
        $data = $this->HttpGetJson($url);
        if (!is_array($data) || !isset($data['address']) || !is_array($data['address'])) {
            return null;
        }
        return self::GeocodeFormat($data);
    }

    /** „REWE, Hauptstraße 5, 49074 Osnabrück“ */
    protected static function GeocodeText(?array $address): string
    {
        if (!is_array($address)) {
            return '';
        }
        return implode(', ', array_filter([$address['name'] ?? '', $address['street'] ?? '', $address['city'] ?? '']));
    }

    /** Antwort von Nominatim -> {name, street, city} */
    protected static function GeocodeFormat(array $data): array
    {
        $a = $data['address'];

        $street = trim(($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? $a['path'] ?? $a['square'] ?? '') . ' ' . ($a['house_number'] ?? ''));
        $place = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['hamlet'] ?? $a['county'] ?? '';
        $district = $a['suburb'] ?? $a['city_district'] ?? $a['quarter'] ?? '';
        $city = trim(($a['postcode'] ?? '') . ' ' . $place);
        if ($district !== '' && $district !== $place) {
            $city .= ($city !== '' ? '-' : '') . $district;
        }

        // Name eines Ortes (Supermarkt, Parkhaus …), aber keine Straßennamen doppelt
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || $name === ($a['road'] ?? '') || $name === ($a['house_number'] ?? '') || $name === $place) {
            $name = '';
        }

        if ($street === '' && $name === '') {
            $street = $district !== '' ? $district : (string) ($data['display_name'] ?? '');
        }

        return ['name' => $name, 'street' => $street, 'city' => $city];
    }

    /** Für Tests überschreibbar */
    protected function HttpGetJson(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            // Nominatim verlangt eine aussagekräftige Kennung
            CURLOPT_USERAGENT      => 'IP-Symcon Volvo (github.com/cfaf2002/Volvo-XC60-Symcon)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json']
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false || $code !== 200) {
            return null;
        }
        $json = json_decode((string) $raw, true);
        return is_array($json) ? $json : null;
    }

    protected static function GeoDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }
}
