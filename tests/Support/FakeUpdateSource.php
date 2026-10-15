<?php
declare(strict_types=1);

namespace Vylaris\Tests\Support;

use Vylaris\Update\{UpdateException, UpdateSource};

/** Simuliertes GitHub: liefert ein Release und ein ZIP, das der Test frei zusammenstellt. */
final class FakeUpdateSource implements UpdateSource
{
    public string $tag = 'v1.1.0';
    public string $notes = 'Neue Funktionen';
    public bool $fail = false;
    public int $downloads = 0;
    /** @var array<string,string> Pfad => Inhalt (ohne Top-Ordner) */
    public array $files = [];
    /** @var array<string,string> rohe ZIP-Einträge (Name => Inhalt), z. B. bösartige Pfade */
    public array $rawEntries = [];
    public array $symlinks = [];
    public string $top = 'owner-repo-abc1234';

    public function latestRelease(): array
    {
        if ($this->fail) {
            throw new UpdateException('GitHub ist nicht erreichbar.');
        }
        return ['tag' => $this->tag, 'version' => ltrim($this->tag, 'vV'), 'name' => 'Release ' . $this->tag, 'notes' => $this->notes,
            'zip_url' => 'https://api.github.com/repos/owner/repo/zipball/' . $this->tag, 'published_at' => '2026-10-15T10:00:00Z', 'html_url' => 'https://github.com/owner/repo/releases/tag/' . $this->tag];
    }

    public function download(string $zipUrl, string $dest, int $maxBytes): void
    {
        $this->downloads++;
        $zip = new \ZipArchive();
        $zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($this->files as $path => $content) {
            $zip->addFromString($this->top . '/' . $path, $content);
        }
        foreach ($this->rawEntries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        foreach ($this->symlinks as $name => $target) {
            $zip->addFromString($this->top . '/' . $name, $target);
            $zip->setExternalAttributesName($this->top . '/' . $name, \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
        $zip->close();
    }
}
