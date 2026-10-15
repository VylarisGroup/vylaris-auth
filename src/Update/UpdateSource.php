<?php
declare(strict_types=1);

namespace Vylaris\Update;

/** Quelle für Releases (produktiv: GitHub; Tests: Fake). */
interface UpdateSource
{
    /**
     * Neuestes veröffentlichtes Release.
     * @return array{tag:string,version:string,name:string,notes:string,zip_url:string,published_at:string,html_url:string}
     * @throws UpdateException
     */
    public function latestRelease(): array;

    /**
     * Lädt das Archiv in eine Datei.
     * @throws UpdateException
     */
    public function download(string $zipUrl, string $dest, int $maxBytes): void;
}
