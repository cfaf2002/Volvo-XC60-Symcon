<?php

declare(strict_types=1);

/**
 * Testumgebung für die Volvo-Module – ohne laufendes IP-Symcon.
 * Bildet IPSModuleStrict und die benötigten Symcon-Funktionen schlank nach.
 *
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELSTARTED = 10001;
const VM_UPDATE = 10603;
const KL_WARNING = 10204;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_DATE_TIME = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
const VARIABLE_PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
const VARIABLE_TEMPLATE_VALUE_PRESENTATION_BATTERY = '{7BD38CF5-07F2-5B5B-8F7F-15398B823BFC}';

const GUID_LOCATION = '{45E97A63-F870-408A-B259-2933F7EABF74}';
const GUID_CONNECT = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';
const GUID_MAP = '{BEC364E9-0470-407D-829E-BC42DC2EB4BC}';

/** Simulierter Symcon-Zustand */
final class Sym
{
    public static array $vars = [];          // Variablen-ID => Wert (für die Karte)
    public static array $idents = [];        // Ident der Volvo-Instanz => Variablen-ID
    public static array $instances = [];     // Modul-GUID => [IDs]
    public static array $props = [];        // Instanz-ID => [Eigenschaft => Wert]
    public static string $volvoAddress = '';

    public static function reset(): void
    {
        self::$vars = [];
        self::$idents = [];
        self::$instances = [GUID_LOCATION => [700], GUID_CONNECT => [600]];
        self::$props = [700 => ['Location' => json_encode(['latitude' => 57.7260, 'longitude' => 11.8500])]];
        self::$volvoAddress = '';
    }
}
Sym::reset();

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetInstanceListByModuleID(string $guid): array { return Sym::$instances[$guid] ?? []; }
function IPS_GetProperty(int $id, string $name): mixed { return Sym::$props[$id][$name] ?? null; }
function IPS_InstanceExists(int $id): bool { return $id === 900; }
function IPS_SemaphoreEnter(string $n, int $t): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false { return Sym::$idents[$ident] ?? false; }
function GetValue(int $id): mixed { return Sym::$vars[$id]; }
function CC_GetUrl(int $id): string { return 'https://abc123.ipmagic.de/'; }
function VOLVO_GetAddressData(int $id): string { return Sym::$volvoAddress; }
function VOLVO_GetVehicleImageUrl(int $id): string { return 'https://cas.volvocars.com/image/xc60.png?w=800&bg=00000000'; }

class IPSModuleStrict
{
    public int $InstanceID = 777;
    public array $p = [];
    public array $a = [];
    public array $v = [];
    public array $pres = [];
    public array $timers = [];
    public array $msgs = [];
    public array $buffers = [];
    public array $pushes = [];
    public array $hooks = [];
    public ?string $tile = null;
    public int $status = 0;

    public function Create(): void {}
    public function ApplyChanges(): void {}

    protected function RegisterPropertyBoolean(string $n, bool $d): bool { $this->p[$n] = $d; return true; }
    protected function RegisterPropertyInteger(string $n, int $d): bool { $this->p[$n] = $d; return true; }
    protected function RegisterPropertyFloat(string $n, float $d): bool { $this->p[$n] = $d; return true; }
    protected function RegisterPropertyString(string $n, string $d): bool { $this->p[$n] = $d; return true; }
    protected function ReadPropertyBoolean(string $n): bool { return (bool) $this->p[$n]; }
    protected function ReadPropertyInteger(string $n): int { return (int) $this->p[$n]; }
    protected function ReadPropertyFloat(string $n): float { return (float) $this->p[$n]; }
    protected function ReadPropertyString(string $n): string { return (string) $this->p[$n]; }
    protected function RegisterAttributeInteger(string $n, int $d): bool { $this->a[$n] = $d; return true; }
    protected function RegisterAttributeString(string $n, string $d): bool { $this->a[$n] = $d; return true; }
    protected function ReadAttributeInteger(string $n): int { return (int) $this->a[$n]; }
    protected function ReadAttributeString(string $n): string { return (string) $this->a[$n]; }
    protected function WriteAttributeInteger(string $n, int $v): bool { $this->a[$n] = $v; return true; }
    protected function WriteAttributeString(string $n, string $v): bool { $this->a[$n] = $v; return true; }

    private function variable(string $ident, int $type, string|array $presentation): bool
    {
        if (is_string($presentation) && $presentation !== '' && $presentation[0] !== '~') {
            throw new Exception("Eigenes Profil statt Darstellung: $presentation");
        }
        $this->pres[$ident] = $presentation;
        if (array_key_exists($ident, $this->v)) {
            return false;
        }
        $this->v[$ident] = [false, 0, 0.0, ''][$type];
        return true;
    }
    protected function RegisterVariableBoolean(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 0, $p); }
    protected function RegisterVariableInteger(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 1, $p); }
    protected function RegisterVariableFloat(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 2, $p); }
    protected function RegisterVariableString(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 3, $p); }
    protected function MaintainVariable(string $i, string $n, int $t, string|array $p, int $pos, bool $keep): bool
    {
        if ($keep) {
            return $this->variable($i, $t, $p);
        }
        unset($this->v[$i], $this->pres[$i]);
        return true;
    }
    protected function GetIDForIdent(string $i): int|false { return array_key_exists($i, $this->v) ? crc32($i) : false; }
    protected function GetValue(string $i): mixed { return $this->v[$i]; }
    protected function SetValue(string $i, mixed $x): bool { if (!array_key_exists($i, $this->v)) { throw new Exception("Variable fehlt: $i"); } $this->v[$i] = $x; return true; }
    protected function RegisterTimer(string $n, int $ms, string $s): bool { $this->timers[$n] = $ms; return true; }
    protected function SetTimerInterval(string $n, int $ms): bool { $this->timers[$n] = $ms; return true; }
    protected function RegisterMessage(int $s, int $m): bool { $this->msgs[$s] = $m; return true; }
    protected function UnregisterMessage(int $s, int $m): bool { unset($this->msgs[$s]); return true; }
    protected function RegisterHook(string $path): bool { $this->hooks[] = $path; return true; }
    public function SetVisualizationType(int $t): void {}
    protected function UpdateVisualizationValue(mixed $v) { $this->tile = $v; $this->pushes[] = $v; }
    protected function SetBuffer(string $n, string $d): bool { $this->buffers[$n] = $d; return true; }
    protected function GetBuffer(string $n): string { return $this->buffers[$n] ?? ''; }
    protected function SetStatus(int $s): bool { $this->status = $s; return true; }
    protected function GetStatus(): int { return $this->status; }
    protected function ReloadForm(): bool { return true; }
    protected function SendDebug(string $m, string $d, int $f): bool { $GLOBALS['debug'][] = "$m: $d"; if (getenv('DEBUG')) { echo "  DBG $m: $d\n"; } return true; }
    protected function LogMessage(string $m, int $t): bool { return true; }
}

$failed = 0;
$passed = 0;
function check(bool $condition, string $message): void
{
    global $failed, $passed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
}

/** Private Methode aufrufen (nur für Tests) */
function call(object $obj, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($obj, $method))->invoke($obj, ...$args);
}
