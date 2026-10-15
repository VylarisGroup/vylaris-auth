<?php
declare(strict_types=1);

/**
 * Aktualisierung per Kommandozeile / Plesk-Aufgabe.
 *   php bin/update.php status
 *   php bin/update.php check
 *   php bin/update.php install      Neueste Version laden und Dateien austauschen (danach: finish)
 *   php bin/update.php finish       Update abschließen (Migrationen) bzw. bei Fehler zurückstellen
 *   php bin/update.php rollback [sicherung.zip]
 * Notfall bei hängendem Wartungsmodus: finish oder rollback ausführen (oder var/update/maintenance.lock löschen).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
require $root . '/src/autoload.php';

use Vylaris\Core\Bootstrap;
use Vylaris\Update\UpdateException;

$app = Bootstrap::app($root);
$u = $app->updater;
$cmd = $argv[1] ?? 'status';
$say = static fn (string $m) => print($m . "\n");

try {
    switch ($cmd) {
        case 'status':
            $say('Installiert: ' . $u->currentVersion());
            $say('Quelle: ' . ($u->configured() ? 'GitHub ' . (string) $app->config->get('update.repo') : 'nicht eingerichtet'));
            $a = $u->updateAvailable();
            $say($a ? 'Verfügbar: ' . $a['version'] : 'Keine neuere Version bekannt (letzte Prüfung zählt; "check" ausführen).');
            if ($u->locked() || $u->pending()) {
                $say('ACHTUNG: Update unvollständig (Wartungsmodus). "finish" oder "rollback" ausführen.');
            }
            break;
        case 'check':
            $r = $u->check();
            $say('Neuestes Release: ' . $r['version'] . ($u->updateAvailable() ? ' (neuer als ' . $u->currentVersion() . ')' : ' (aktuell)'));
            break;
        case 'install':
            $r = $u->install();
            $say("Dateien ausgetauscht: {$r['from']} -> {$r['to']}.");
            // Abschluss in neuem Prozess, damit der neue Code die Migrationen ausführt.
            if (function_exists('passthru') && !in_array('passthru', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
                passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' finish', $code);
                exit($code);
            }
            $say('Jetzt ausführen: php bin/update.php finish');
            break;
        case 'finish':
            $r = $u->finish();
            $say("Update auf {$r['to']} abgeschlossen. Migrationen: " . ($r['migrations'] ? implode(', ', $r['migrations']) : 'keine'));
            break;
        case 'rollback':
            $say('Zurückgestellt auf Version ' . $u->rollback($argv[2] ?? null) . '. Datenbank unverändert.');
            break;
        default:
            $say('Unbekannter Befehl. Erlaubt: status | check | install | finish | rollback');
            exit(2);
    }
} catch (UpdateException $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
