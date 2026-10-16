<?php
declare(strict_types=1);

namespace Vylaris\Update;

use Vylaris\Core\{Clock, Database};

/**
 * Änderungsprotokoll aus CHANGELOG.md im Projektstamm (kommt mit jedem Release).
 *
 * Format:  "## 1.6.0 – 2026-10-16" als Überschrift, darunter Zeilen "- Text".
 * Angezeigt wird jedem Administrator einmal pro Version beim ersten Aufruf des Admin-Bereichs.
 */
final class Changelog
{
    public function __construct(private string $root, private Database $db)
    {
    }

    /** @return list<array{version:string,date:string,items:list<string>}> neueste zuerst */
    public function entries(): array
    {
        $raw = @file_get_contents($this->root . '/CHANGELOG.md');
        if ($raw === false) {
            return [];
        }
        $out = [];
        $cur = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^##\s+v?(\d{1,4}\.\d{1,4}\.\d{1,4}(?:-[0-9A-Za-z.-]{1,30})?)\s*(?:[–—-]\s*(\S.*))?$/u', $line, $m)) {
                if ($cur !== null) {
                    $out[] = $cur;
                }
                $cur = ['version' => $m[1], 'date' => trim($m[2] ?? ''), 'items' => []];
            } elseif (preg_match('/^#{1,2}\s/', $line)) {
                if ($cur !== null) {
                    $out[] = $cur;
                    $cur = null; // unbekannte Überschrift beendet den Eintrag
                }
            } elseif ($cur !== null && preg_match('/^\s*[-*]\s+(.+)$/u', $line, $m)) {
                $cur['items'][] = trim($m[1]);
            }
        }
        if ($cur !== null) {
            $out[] = $cur;
        }
        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        return $out;
    }

    /**
     * Einträge, die dieser Administrator noch nicht gesehen hat (bis einschließlich der installierten Version).
     * Ohne bisherigen Stand wird nur der Eintrag der installierten Version gezeigt.
     *
     * @return list<array{version:string,date:string,items:list<string>}>
     */
    public function unseen(int $userId, string $current): array
    {
        $all = array_values(array_filter($this->entries(), static fn (array $e): bool => version_compare($e['version'], $current, '<=')));
        if ($all === []) {
            return [];
        }
        try {
            $seen = $this->db->scalar('SELECT version FROM admin_changelog_seen WHERE user_id = ?', [$userId]);
        } catch (\Throwable) {
            return []; // Migration 005 noch nicht eingespielt
        }
        if (!is_string($seen) || $seen === '') {
            return [$all[0]];
        }
        return array_values(array_filter($all, static fn (array $e): bool => version_compare($e['version'], $seen, '>')));
    }

    public function markSeen(int $userId, string $current): void
    {
        try {
            $exists = $this->db->scalar('SELECT COUNT(*) FROM admin_changelog_seen WHERE user_id = ?', [$userId]);
            if ((int) $exists > 0) {
                $this->db->run('UPDATE admin_changelog_seen SET version = ?, seen_at = ? WHERE user_id = ?', [$current, Clock::db(), $userId]);
            } else {
                $this->db->insert('admin_changelog_seen', ['user_id' => $userId, 'version' => $current, 'seen_at' => Clock::db()]);
            }
        } catch (\Throwable) {
            // Tabelle fehlt noch: nichts zu merken
        }
    }
}
