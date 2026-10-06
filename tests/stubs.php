<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon über den Modul-Loader, legt die Instanz an,
 * öffnet die Formulare und prüft Variablen, Darstellungen und Kacheln – ohne Volvo-Cloud.
 *
 * Copyright (c) 2026 Armin Frohwerk
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

// Veraltete Stub-Aufrufe und Ident-Hinweise der Stubs ausblenden
set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
$copy = sys_get_temp_dir() . '/volvo-stubs-' . getmypid();
@mkdir($copy);
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
        // RegisterHook ist in den Stubs nur ein leerer Rumpf
        $code = preg_replace('/(function Register(?:Hook|OAuth)\(string \$\w+\): bool\s*\{)\s*\}/', '$1 return true; }', $code);
    }
    file_put_contents($copy . '/' . basename($file), $code);
}
register_shutdown_function(static function () use ($copy): void {
    array_map('unlink', glob($copy . '/*.php'));
    @rmdir($copy);
});

require $copy . '/autoload.php';

\IPS\Kernel::reset();
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

echo 'Volvo Fahrzeug' . PHP_EOL;
try {
    $id = IPS_CreateInstance('{3E673B62-F8FD-4C29-9848-EEACA895B4F3}');
    ok($id > 0, 'Instanz angelegt');
    $form = json_decode(IPS_GetConfigurationForm($id), true);
    ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');
    ok(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Ohne Zugangsdaten Status 201');
    foreach (['BatteryLevel', 'ElectricRange', 'ChargingStatus', 'CableConnected', 'Odometer', 'LastUpdate'] as $ident) {
        ok(@IPS_GetObjectIDByIdent($ident, $id) !== false, 'Variable ' . $ident);
    }
    $p = IPS_GetVariable(IPS_GetObjectIDByIdent('ChargingStatus', $id))['VariablePresentation'];
    ok(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION, 'Ladestatus als Aufzählung');
    IPS_SetProperty($id, 'ApiKey', 'KEY');
    IPS_SetProperty($id, 'ClientId', 'cid');
    IPS_SetProperty($id, 'ClientSecret', 'sec');
    IPS_SetProperty($id, 'RedirectUri', 'https://example.org/hook/volvo');
    IPS_ApplyChanges($id);
    ok(IPS_GetInstance($id)['InstanceStatus'] === 202, 'Mit Zugangsdaten Status 202 (Anmeldung nötig)');
    ok(str_starts_with(VOLVO_GetLoginUrl($id), 'https://volvoid.eu.volvocars.com/'), 'VOLVO_GetLoginUrl');
    ok(str_contains(VOLVO_GetVisualizationTile($id), 'handleMessage('), 'Kachel-HTML mit Startdaten');
    foreach ([0, 1, 2] as $theme) {
        IPS_SetProperty($id, 'TileTheme', $theme);
        IPS_ApplyChanges($id);
        ok(str_contains(VOLVO_GetVisualizationTile($id), '\\u0022theme\\u0022:' . $theme), 'Farbschema ' . $theme . ' in den Kacheldaten');
    }

    echo 'Volvo Karte' . PHP_EOL;
    $map = IPS_CreateInstance('{BEC364E9-0470-407D-829E-BC42DC2EB4BC}');
    ok($map > 0, 'Instanz angelegt');
    ok(is_array(json_decode(IPS_GetConfigurationForm($map), true)), 'Formular ist gültiges JSON');
    IPS_SetProperty($map, 'VolvoInstance', $id);
    IPS_ApplyChanges($map);
    ok(IPS_GetInstance($map)['InstanceStatus'] === 102, 'Mit Volvo-Instanz Status 102');
    ok(str_contains(VOLVOMAP_GetVisualizationTile($map), 'handleMessage('), 'Kachel-HTML mit Startdaten');
    foreach ([0, 1, 2] as $theme) {
        IPS_SetProperty($map, 'TileTheme', $theme);
        IPS_ApplyChanges($map);
        ok(str_contains(VOLVOMAP_GetVisualizationTile($map), '\\u0022theme\\u0022:' . $theme), 'Farbschema ' . $theme . ' in den Kacheldaten');
    }
} catch (Throwable $e) {
    ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
