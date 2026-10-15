<?php
declare(strict_types=1);

namespace Vylaris\Update;

use Vylaris\Core\Clock;

/**
 * Aktualisierung aus GitHub-Releases (ohne Shell, ohne Git – läuft auf Shared Hosting).
 *
 * Ablauf install(): Release laden → Archiv prüfen (Pfade, Symlinks, Größen) → entpacken → PHP-Syntax aller Dateien prüfen →
 * Sicherung der betroffenen Dateien → Wartungsmodus → Dateien austauschen. finish() läuft in einer NEUEN Anfrage
 * (damit der neue Code die Migrationen ausführt): Migrationen → Prüfung → Wartungsmodus aus. Scheitert etwas, wird
 * automatisch aus der Sicherung zurückgestellt (nur Dateien; Migrationen sind additiv und werden nicht rückgängig gemacht).
 *
 * Nie angefasst werden: config/config.php, config/setup.token und alles unter var/ (Schlüssel, Logs, Branding, Sicherungen).
 */
final class Updater
{
    /** Verzeichnisse, die 1:1 dem Release entsprechen (veraltete Dateien darin werden entfernt). */
    public const MIRRORED = ['src', 'templates', 'migrations', 'bin', 'docs', 'examples', 'scripts', 'assets-src', 'tests', 'public/assets'];
    public const PROTECTED = ['config/config.php', 'config/setup.token'];
    private const MAX_ZIP = 52428800;       // 50 MiB
    private const MAX_UNPACKED = 209715200; // 200 MiB
    private const MAX_FILES = 6000;
    private const KEEP_BACKUPS = 3;

    /** @var callable */
    private $migrate;

    /** @param callable():array $migrate führt Migrationen aus, liefert Namen der angewendeten */
    public function __construct(private string $root, private ?UpdateSource $source, private int $checkHours, callable $migrate)
    {
        $this->root = rtrim($root, '/');
        $this->migrate = $migrate;
    }

    public function configured(): bool
    {
        return $this->source !== null;
    }

    // ------------------------------------------------------------------ Zustand

    private function dir(string $sub = ''): string
    {
        return $this->root . '/var/update' . ($sub !== '' ? '/' . $sub : '');
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            throw new UpdateException('Verzeichnis kann nicht angelegt werden: ' . str_replace($this->root . '/', '', $path) . ' (Schreibrechte für var/ prüfen).');
        }
    }

    public static function validVersion(string $v): bool
    {
        return (bool) preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}(?:-[0-9A-Za-z.-]{1,30})?$/', $v);
    }

    public function currentVersion(): string
    {
        $v = trim((string) @file_get_contents($this->root . '/VERSION'));
        return self::validVersion($v) ? $v : '0.0.0';
    }

    private function readJson(string $file): ?array
    {
        $raw = @file_get_contents($file);
        $j = $raw === false ? null : json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function writeJson(string $file, array $data): void
    {
        $this->ensureDir(dirname($file));
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false || !rename($tmp, $file)) {
            throw new UpdateException('Zustandsdatei kann nicht geschrieben werden (var/ beschreibbar?).');
        }
    }

    public function locked(): bool
    {
        return is_file($this->dir('maintenance.lock'));
    }

    /** @return array<string,mixed>|null laufendes/ausstehendes Update */
    public function pending(): ?array
    {
        $s = $this->readJson($this->dir('state.json'));
        return $s !== null && in_array($s['status'] ?? '', ['applying', 'applied'], true) ? $s : null;
    }

    /** @return array<string,mixed>|null Ergebnis des letzten Updates/Rollbacks */
    public function last(): ?array
    {
        return $this->readJson($this->dir('last.json'));
    }

    // ------------------------------------------------------------------ Prüfen

    /** @return array<string,mixed>|null zuletzt gespeicherte Prüfung */
    public function latest(): ?array
    {
        return $this->readJson($this->dir('latest.json'));
    }

    /** Neueres Release laut letzter Prüfung (ohne Netzwerk), sonst null. */
    public function updateAvailable(): ?array
    {
        $l = $this->latest();
        $r = is_array($l['release'] ?? null) ? $l['release'] : null;
        return $r !== null && ($l['ok'] ?? false) && self::validVersion((string) ($r['version'] ?? '')) && version_compare($r['version'], $this->currentVersion(), '>') ? $r : null;
    }

    /** @throws UpdateException */
    public function check(): array
    {
        $src = $this->source ?? throw new UpdateException('Aktualisierung ist nicht eingerichtet (update.repo in config/config.php).');
        try {
            $rel = $src->latestRelease();
            if (!self::validVersion((string) $rel['version'])) {
                throw new UpdateException('Das neueste Release hat keine gültige Versionsnummer („' . mb_substr((string) $rel['tag'], 0, 40) . '“; erwartet z. B. v1.2.3).');
            }
        } catch (UpdateException $e) {
            $this->writeJson($this->dir('latest.json'), ['checked_at' => Clock::now(), 'ok' => false, 'error' => $e->getMessage(), 'release' => null]);
            throw $e;
        }
        $this->writeJson($this->dir('latest.json'), ['checked_at' => Clock::now(), 'ok' => true, 'error' => null, 'release' => $rel]);
        return $rel;
    }

    /** Cron: prüft höchstens alle update.check_hours Stunden. Fehler werden gespeichert, nicht geworfen. @return bool Prüfung durchgeführt */
    public function checkIfDue(): bool
    {
        if ($this->source === null || $this->locked()) {
            return false;
        }
        $l = $this->latest();
        if ($l !== null && Clock::now() - (int) ($l['checked_at'] ?? 0) < max(1, $this->checkHours) * 3600) {
            return false;
        }
        try {
            $this->check();
        } catch (UpdateException) {
            // in latest.json vermerkt
        }
        return true;
    }

    // ------------------------------------------------------------------ Installieren

    /**
     * Lädt und tauscht Dateien aus; der Wartungsmodus bleibt bis finish() aktiv.
     * @param string $expected Version, die der Administrator gesehen hat (verhindert, dass unbemerkt eine andere installiert wird)
     * @return array{from:string,to:string}
     * @throws UpdateException
     */
    public function install(string $expected = ''): array
    {
        $src = $this->source ?? throw new UpdateException('Aktualisierung ist nicht eingerichtet (update.repo in config/config.php).');
        if (!class_exists(\ZipArchive::class)) {
            throw new UpdateException('Die PHP-Erweiterung zip (ZipArchive) fehlt.');
        }
        if ($this->locked() || $this->pending() !== null) {
            throw new UpdateException('Ein Update läuft oder ist unvollständig. Zuerst abschließen („Update abschließen“) oder zurücksetzen.');
        }
        @set_time_limit(0);
        @ignore_user_abort(true);

        $rel = $this->check();
        $from = $this->currentVersion();
        $to = (string) $rel['version'];
        if (version_compare($to, $from, '<=')) {
            throw new UpdateException('Die installierte Version (' . $from . ') ist bereits aktuell.');
        }
        if ($expected !== '' && ltrim($expected, 'vV') !== $to) {
            throw new UpdateException('Inzwischen ist Version ' . $to . ' erschienen (angezeigt wurde ' . $expected . '). Bitte Änderungen prüfen und erneut bestätigen.');
        }

        $work = $this->dir('work');
        $this->rmtree($work);
        $this->ensureDir($work);
        $zipFile = $work . '/release.zip';
        $stage = $work . '/stage';
        try {
            $src->download((string) $rel['zip_url'], $zipFile, self::MAX_ZIP);
            $this->extract($zipFile, $stage);
            $this->validateStage($stage, $to);
            $plan = $this->plan($stage);
            $backup = $this->backup($plan, $from, $to);
        } catch (\Throwable $e) {
            $this->rmtree($work);
            throw $e instanceof UpdateException ? $e : new UpdateException('Vorbereitung fehlgeschlagen: ' . $e->getMessage());
        }

        $state = ['status' => 'applying', 'from' => $from, 'to' => $to, 'backup' => basename($backup), 'started_at' => Clock::now()];
        $this->writeJson($this->dir('state.json'), $state);
        $this->writeJson($this->dir('maintenance.lock'), $state);
        // Bricht PHP mitten im Austausch ab (Timeout, Speicher), stellt diese Funktion den alten Stand wieder her.
        $guard = function () use ($backup): void {
            $s = $this->readJson($this->dir('state.json'));
            if (($s['status'] ?? '') === 'applying') {
                try {
                    $this->restore($backup);
                } catch (\Throwable) {
                }
                $this->finishState('rolled_back', (string) ($s['from'] ?? ''), (string) ($s['to'] ?? ''), 'Abbruch während der Installation, Dateien zurückgestellt.');
            }
        };
        register_shutdown_function($guard);
        try {
            $this->apply($stage, $plan);
        } catch (\Throwable $e) {
            $this->restore($backup);
            $this->finishState('rolled_back', $from, $to, 'Installation fehlgeschlagen, Dateien zurückgestellt: ' . $e->getMessage());
            $this->rmtree($work);
            throw new UpdateException('Installation fehlgeschlagen, der alte Stand wurde wiederhergestellt: ' . $e->getMessage());
        }
        $state['status'] = 'applied';
        $this->writeJson($this->dir('state.json'), $state);
        $this->rmtree($work);
        $this->resetOpcache();
        return ['from' => $from, 'to' => $to];
    }

    /**
     * Schließt ein installiertes Update ab (Migrationen, Prüfung) – im NEUEN Code. Bei Fehlern: Rückstellung.
     * @return array{from:string,to:string,migrations:string[]}
     * @throws UpdateException
     */
    public function finish(): array
    {
        $s = $this->pending();
        if ($s === null || ($s['status'] ?? '') !== 'applied') {
            throw new UpdateException('Es gibt kein Update, das abgeschlossen werden müsste.');
        }
        @set_time_limit(0);
        $from = (string) $s['from'];
        $to = (string) $s['to'];
        try {
            if ($this->currentVersion() !== $to || !is_file($this->root . '/src/autoload.php') || !is_file($this->root . '/public/index.php')) {
                throw new \RuntimeException('Die installierten Dateien passen nicht zu Version ' . $to . '.');
            }
            $applied = (array) ($this->migrate)();
        } catch (\Throwable $e) {
            $msg = $this->clean($e->getMessage());
            try {
                $this->restore($this->backupPath((string) $s['backup']));
            } catch (\Throwable $r) {
                $this->finishState('failed', $from, $to, 'Abschluss fehlgeschlagen und Rückstellung nicht möglich: ' . $this->clean($r->getMessage()));
                throw new UpdateException('Abschluss fehlgeschlagen (' . $msg . ') und die Rückstellung ebenfalls (' . $this->clean($r->getMessage()) . '). Bitte manuell zurücksetzen: php bin/update.php rollback');
            }
            $this->finishState('rolled_back', $from, $to, 'Abschluss fehlgeschlagen, Dateien zurückgestellt: ' . $msg);
            $this->resetOpcache();
            throw new UpdateException('Das Update wurde zurückgestellt (' . $msg . '). Die Datenbank bleibt auf dem Stand der bereits angewendeten Migrationen; diese sind additiv.');
        }
        $this->finishState('done', $from, $to, '', $applied);
        $this->resetOpcache();
        return ['from' => $from, 'to' => $to, 'migrations' => $applied];
    }

    // ------------------------------------------------------------------ Rückstellen

    /** @return list<array{file:string,size:int,time:int}> */
    public function backups(): array
    {
        $out = [];
        foreach (glob($this->root . '/var/backups/update-*.zip') ?: [] as $f) {
            $out[] = ['file' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['file'], $a['file']));
        return $out;
    }

    private function backupPath(string $name): string
    {
        if (!preg_match('/^update-[0-9A-Za-z._-]+\.zip$/', $name) || !is_file($this->root . '/var/backups/' . $name)) {
            throw new UpdateException('Sicherung nicht gefunden.');
        }
        return $this->root . '/var/backups/' . $name;
    }

    /** Stellt Dateien aus einer Sicherung wieder her (neueste, wenn keine angegeben). Datenbank bleibt unberührt. */
    public function rollback(?string $name = null): string
    {
        if ($name === null) {
            $b = $this->backups();
            if (!$b) {
                throw new UpdateException('Keine Sicherung vorhanden.');
            }
            $name = $b[0]['file'];
        }
        $path = $this->backupPath($name);
        $from = $this->currentVersion();
        $this->restore($path);
        $this->finishState('rolled_back', $from, $this->currentVersion(), 'Manuell zurückgestellt (' . $name . ').');
        $this->resetOpcache();
        return $this->currentVersion();
    }

    // ------------------------------------------------------------------ Archiv

    private function isUnsafe(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
            return true;
        }
        foreach (explode('/', $name) as $seg) {
            if ($seg === '..') {
                return true;
            }
        }
        return false;
    }

    private function extract(string $zipFile, string $stage): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new UpdateException('Das Archiv ist beschädigt oder kein ZIP.');
        }
        $n = $zip->numFiles;
        if ($n < 1 || $n > self::MAX_FILES) {
            throw new UpdateException('Das Archiv enthält zu viele oder keine Dateien.');
        }
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $n; $i++) {
            $st = $zip->statIndex($i);
            $name = (string) ($st['name'] ?? '');
            if ($this->isUnsafe($name)) {
                throw new UpdateException('Das Archiv enthält unzulässige Pfade und wurde abgelehnt.');
            }
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0xF000) === 0xA000) {
                throw new UpdateException('Das Archiv enthält symbolische Verknüpfungen und wurde abgelehnt.');
            }
            $total += (int) $st['size'];
            if ($total > self::MAX_UNPACKED) {
                throw new UpdateException('Das entpackte Archiv wäre zu groß.');
            }
            $entries[$i] = $name;
        }
        // GitHub packt alles in einen Ordner "besitzer-repo-sha/": gemeinsamen ersten Pfadteil entfernen.
        $prefix = '';
        $first = null;
        foreach ($entries as $name) {
            $seg = strstr($name, '/', true);
            if ($seg === false || ($first !== null && $seg !== $first)) {
                $first = null;
                break;
            }
            $first = $seg;
        }
        if ($first !== null) {
            $prefix = $first . '/';
        }
        $free = @disk_free_space($this->root);
        if ($free !== false && $free < $total * 2 + 20 * 1048576) {
            throw new UpdateException('Zu wenig freier Speicherplatz für das Update.');
        }
        $this->ensureDir($stage);
        foreach ($entries as $i => $name) {
            $rel = $prefix !== '' ? substr($name, strlen($prefix)) : $name;
            if ($rel === '' || str_ends_with($rel, '/') || $this->skipPath($rel)) {
                continue;
            }
            $target = $stage . '/' . $rel;
            $this->ensureDir(dirname($target));
            $in = $zip->getStream($name);
            $out = $in ? fopen($target, 'wb') : false;
            if (!$in || !$out) {
                throw new UpdateException('Datei konnte nicht entpackt werden: ' . $rel);
            }
            $declared = (int) $zip->statIndex($i)['size'];
            $copied = 0;
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === false) {
                    break;
                }
                $copied += strlen($chunk);
                if ($copied > $declared) {
                    throw new UpdateException('Das Archiv ist inkonsistent (Größenangabe falsch).');
                }
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);
        }
        $zip->close();
    }

    /** Pfade, die nie aus einem Release übernommen werden. */
    private function skipPath(string $rel): bool
    {
        if (in_array($rel, self::PROTECTED, true) || $rel === 'var' || str_starts_with($rel, 'var/') || str_starts_with($rel, 'node_modules/')) {
            return true;
        }
        return (bool) preg_match('#(^|/)\.git(/|$|hub/|attributes$|ignore$)#', $rel);
    }

    private function validateStage(string $stage, string $version): void
    {
        foreach (['src/autoload.php', 'public/index.php', 'VERSION'] as $must) {
            if (!is_file($stage . '/' . $must)) {
                throw new UpdateException('Das Release ist unvollständig (' . $must . ' fehlt). Liegt der Projektinhalt im Wurzelverzeichnis des Repositorys?');
            }
        }
        if (!is_dir($stage . '/migrations')) {
            throw new UpdateException('Das Release ist unvollständig (migrations/ fehlt).');
        }
        $v = trim((string) file_get_contents($stage . '/VERSION'));
        if ($v !== $version) {
            throw new UpdateException('Die Datei VERSION im Release (' . mb_substr($v, 0, 30) . ') passt nicht zum Release-Tag (' . $version . ').');
        }
        // Syntaxprüfung aller PHP-Dateien mit dem PHP des Servers – ein kaputtes Release gelangt so nie live.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                try {
                    token_get_all((string) file_get_contents($f->getPathname()), TOKEN_PARSE);
                } catch (\ParseError $e) {
                    throw new UpdateException('Syntaxfehler im Release (' . substr($f->getPathname(), strlen($stage) + 1) . ', Zeile ' . $e->getLine() . '): ' . $e->getMessage());
                }
            }
        }
    }

    // ------------------------------------------------------------------ Plan, Sicherung, Austausch

    /** @return array{mirrored:list<string>,files:list<string>} relative Pfade aus dem Release */
    private function plan(string $stage): array
    {
        $mirrored = [];
        foreach (self::MIRRORED as $d) {
            if (is_dir($stage . '/' . $d)) {
                $mirrored[] = $d;
            }
        }
        $files = [];
        foreach ($this->listFiles($stage) as $rel) {
            $inMirror = false;
            foreach ($mirrored as $d) {
                if (str_starts_with($rel, $d . '/')) {
                    $inMirror = true;
                    break;
                }
            }
            if (!$inMirror) {
                $files[] = $rel;
            }
        }
        return ['mirrored' => $mirrored, 'files' => $files];
    }

    /** @return list<string> */
    private function listFiles(string $base): array
    {
        $out = [];
        if (!is_dir($base)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            }
        }
        sort($out);
        return $out;
    }

    private function backup(array $plan, string $from, string $to): string
    {
        $dir = $this->root . '/var/backups';
        $this->ensureDir($dir);
        $path = $dir . '/update-' . $from . '-to-' . $to . '-' . gmdate('Ymd-His', Clock::now()) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('Sicherung kann nicht angelegt werden (var/backups beschreibbar?).');
        }
        $manifest = ['from' => $from, 'to' => $to, 'mirrored' => [], 'files' => [], 'added' => []];
        foreach ($plan['mirrored'] as $d) {
            if (is_dir($this->root . '/' . $d)) {
                $manifest['mirrored'][] = $d;
                foreach ($this->listFiles($this->root . '/' . $d) as $rel) {
                    $zip->addFile($this->root . '/' . $d . '/' . $rel, 'files/' . $d . '/' . $rel);
                }
            } else {
                $manifest['added'][] = $d . '/'; // Verzeichnis gab es vorher nicht
            }
        }
        foreach ($plan['files'] as $rel) {
            if (is_file($this->root . '/' . $rel)) {
                $manifest['files'][] = $rel;
                $zip->addFile($this->root . '/' . $rel, 'files/' . $rel);
            } else {
                $manifest['added'][] = $rel;
            }
        }
        // Wurde VERSION zuvor nicht mitgesichert (steht im Plan), ist sie enthalten; sonst als "added".
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
        if (!$zip->close() || !is_file($path)) {
            throw new UpdateException('Die Sicherung konnte nicht geschrieben werden (Speicherplatz?).');
        }
        @chmod($path, 0640);
        // Alte Sicherungen aufräumen
        foreach (array_slice($this->backups(), self::KEEP_BACKUPS) as $old) {
            @unlink($dir . '/' . $old['file']);
        }
        return $path;
    }

    private function apply(string $stage, array $plan): void
    {
        foreach ($plan['mirrored'] as $d) {
            $new = $this->listFiles($stage . '/' . $d);
            foreach ($new as $rel) {
                $this->place($stage . '/' . $d . '/' . $rel, $this->root . '/' . $d . '/' . $rel);
            }
            $keep = array_flip($new);
            foreach ($this->listFiles($this->root . '/' . $d) as $rel) {
                if (!isset($keep[$rel])) {
                    @unlink($this->root . '/' . $d . '/' . $rel);
                }
            }
            $this->pruneEmpty($this->root . '/' . $d);
        }
        // VERSION zuletzt: sie markiert den vollständig installierten Stand.
        $files = array_values(array_diff($plan['files'], ['VERSION']));
        foreach ($files as $rel) {
            $this->place($stage . '/' . $rel, $this->root . '/' . $rel);
        }
        $this->place($stage . '/VERSION', $this->root . '/VERSION');
    }

    private function place(string $from, string $to): void
    {
        $this->ensureDir(dirname($to));
        $tmp = $to . '.upd' . bin2hex(random_bytes(3));
        if (!@copy($from, $tmp)) {
            throw new \RuntimeException('Datei kann nicht geschrieben werden: ' . substr($to, strlen($this->root) + 1));
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $to)) {
            @unlink($tmp);
            throw new \RuntimeException('Datei kann nicht ersetzt werden: ' . substr($to, strlen($this->root) + 1));
        }
    }

    private function pruneEmpty(string $dir): void
    {
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            $this->pruneEmpty($sub);
            @rmdir($sub);
        }
    }

    private function restore(string $backup): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($backup) !== true) {
            throw new \RuntimeException('Sicherung nicht lesbar.');
        }
        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($m)) {
            throw new \RuntimeException('Sicherung ohne Manifest.');
        }
        foreach ((array) $m['mirrored'] as $d) {
            if (!in_array($d, self::MIRRORED, true)) {
                throw new \RuntimeException('Unzulässiger Pfad in der Sicherung.');
            }
            foreach ($this->listFiles($this->root . '/' . $d) as $rel) {
                @unlink($this->root . '/' . $d . '/' . $rel);
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === 'manifest.json' || str_ends_with($name, '/')) {
                continue;
            }
            if (!str_starts_with($name, 'files/') || $this->isUnsafe($name)) {
                throw new \RuntimeException('Unzulässiger Pfad in der Sicherung.');
            }
            $rel = substr($name, 6);
            if ($this->skipPath($rel)) {
                continue;
            }
            $tmp = $this->dir('restore.tmp');
            $this->ensureDir($this->dir());
            file_put_contents($tmp, (string) $zip->getFromIndex($i));
            $this->place($tmp, $this->root . '/' . $rel);
            @unlink($tmp);
        }
        foreach ((array) $m['added'] as $rel) {
            $rel = (string) $rel;
            if ($this->isUnsafe($rel) || $this->skipPath($rel)) {
                continue;
            }
            if (str_ends_with($rel, '/')) {
                $d = rtrim($rel, '/');
                if (in_array($d, self::MIRRORED, true)) {
                    foreach ($this->listFiles($this->root . '/' . $d) as $f) {
                        @unlink($this->root . '/' . $d . '/' . $f);
                    }
                    $this->pruneEmpty($this->root . '/' . $d);
                    @rmdir($this->root . '/' . $d);
                }
            } else {
                @unlink($this->root . '/' . $rel);
            }
        }
        $zip->close();
        foreach ((array) $m['mirrored'] as $d) {
            $this->pruneEmpty($this->root . '/' . $d);
        }
    }

    // ------------------------------------------------------------------ Hilfen

    private function finishState(string $status, string $from, string $to, string $message, array $migrations = []): void
    {
        $this->writeJson($this->dir('last.json'), ['status' => $status, 'from' => $from, 'to' => $to, 'message' => $message, 'migrations' => $migrations, 'at' => Clock::now()]);
        @unlink($this->dir('state.json'));
        @unlink($this->dir('maintenance.lock'));
    }

    private function clean(string $msg): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $msg) ?? '', 0, 300);
    }

    private function rmtree(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with($dir, $this->root . '/var/')) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
