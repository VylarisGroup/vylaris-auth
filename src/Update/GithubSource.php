<?php
declare(strict_types=1);

namespace Vylaris\Update;

/**
 * GitHub-Releases über die REST-API. Nur HTTPS, nur GitHub-Hosts (auch bei Weiterleitungen), TLS-Prüfung immer an.
 * Das Token (nur für private Repositories, schreibgeschützt: Contents → Read) wird ausschließlich an api.github.com gesendet,
 * nie an Weiterleitungsziele, und taucht in keiner Fehlermeldung auf.
 */
final class GithubSource implements UpdateSource
{
    /** Standard-Repository für Updates, solange update.repo in config/config.php nicht gesetzt ist ('' dort = Updates aus). */
    public const DEFAULT_REPO = 'VylarisGroup/vylaris-auth';

    private const HOSTS = ['api.github.com', 'codeload.github.com', 'github.com', 'objects.githubusercontent.com'];
    private const MAX_REDIRECTS = 4;

    public function __construct(private string $repo, private string $token = '')
    {
        if (!self::validRepo($repo)) {
            throw new UpdateException('Ungültiges GitHub-Repository (erwartet: besitzer/name).');
        }
    }

    public static function validRepo(string $repo): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#', $repo) && !str_contains($repo, '..');
    }

    public function latestRelease(): array
    {
        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        [$status, $body] = $this->fetch($url, null, 0);
        if ($status === 404) {
            throw new UpdateException('Kein Release gefunden. Prüfen Sie Repository-Name, ob mindestens ein Release (nicht nur ein Tag) veröffentlicht ist und – bei privaten Repositories – das Token.');
        }
        if ($status === 401) {
            throw new UpdateException('GitHub hat das Token abgelehnt (abgelaufen oder ungültig).');
        }
        if ($status === 403 || $status === 429) {
            throw new UpdateException('GitHub begrenzt die Anfragen oder verweigert den Zugriff. Bitte später erneut versuchen.');
        }
        if ($status !== 200) {
            throw new UpdateException('GitHub hat unerwartet geantwortet (HTTP ' . $status . ').');
        }
        $j = json_decode((string) $body, true);
        if (!is_array($j) || !is_string($j['tag_name'] ?? null) || !is_string($j['zipball_url'] ?? null)) {
            throw new UpdateException('Die Antwort von GitHub ist unvollständig.');
        }
        if (($j['draft'] ?? false) || ($j['prerelease'] ?? false)) {
            throw new UpdateException('Das neueste Release ist ein Entwurf oder eine Vorabversion.');
        }
        $tag = $j['tag_name'];
        return [
            'tag' => $tag,
            'version' => ltrim($tag, 'vV'),
            'name' => mb_substr((string) ($j['name'] ?? $tag), 0, 200),
            'notes' => mb_substr((string) ($j['body'] ?? ''), 0, 8000),
            'zip_url' => $j['zipball_url'],
            'published_at' => (string) ($j['published_at'] ?? ''),
            'html_url' => is_string($j['html_url'] ?? null) && str_starts_with($j['html_url'], 'https://github.com/') ? $j['html_url'] : '',
        ];
    }

    public function download(string $zipUrl, string $dest, int $maxBytes): void
    {
        [$status] = $this->fetch($zipUrl, $dest, $maxBytes);
        if ($status !== 200) {
            @unlink($dest);
            throw new UpdateException('Download fehlgeschlagen (HTTP ' . $status . ').');
        }
    }

    /**
     * @return array{0:int,1:?string} Status und Body (Body null, wenn in Datei geschrieben)
     */
    private function fetch(string $url, ?string $dest, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            throw new UpdateException('Die PHP-Erweiterung cURL fehlt.');
        }
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!str_starts_with($url, 'https://') || !in_array($host, self::HOSTS, true)) {
                throw new UpdateException('Unzulässiges Ziel beim Abruf von GitHub.');
            }
            $headers = ['User-Agent: VylarisAuth-Updater'];
            if ($host === 'api.github.com') {
                $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
                // Die zipball-Weiterleitung der API braucht kein spezielles Accept; JSON-Accept nur für Metadaten.
                $headers[] = $dest === null ? 'Accept: application/vnd.github+json' : 'Accept: */*';
            } else {
                // codeload/objects lehnen API-spezifische Accept-Header mit HTTP 415 ab.
                $headers[] = 'Accept: */*';
            }
            if ($this->token !== '' && $host === 'api.github.com') {
                $headers[] = 'Authorization: Bearer ' . $this->token;
            }
            $code = 0;
            $location = null;
            $buf = '';
            $size = 0;
            $fh = $dest !== null ? fopen($dest, 'wb') : null;
            if ($dest !== null && $fh === false) {
                throw new UpdateException('Zieldatei kann nicht geschrieben werden (var/update beschreibbar?).');
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $dest === null ? 15 : 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$code, &$location): int {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                        $code = (int) $m[1];
                        $location = null;
                    } elseif (stripos($h, 'location:') === 0) {
                        $location = trim(substr($h, 9));
                    }
                    return strlen($h);
                },
                CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$code, &$buf, &$size, $fh, $maxBytes): int {
                    if ($code !== 200) {
                        return strlen($chunk); // Weiterleitungs-/Fehlerseiten nicht speichern
                    }
                    $size += strlen($chunk);
                    if ($fh !== null) {
                        if ($size > $maxBytes) {
                            return 0; // Abbruch: zu groß
                        }
                        return (int) fwrite($fh, $chunk);
                    }
                    if ($size > 1048576) {
                        return 0;
                    }
                    $buf .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            curl_close($ch);
            if ($fh !== null) {
                fclose($fh);
            }
            if ($ok === false) {
                if ($dest !== null) {
                    @unlink($dest);
                }
                throw new UpdateException($size > $maxBytes && $maxBytes > 0 ? 'Das Update-Archiv ist zu groß.' : 'GitHub ist nicht erreichbar.');
            }
            if ($code >= 300 && $code < 400 && $location !== null) {
                $url = $location;
                continue;
            }
            return [$code, $dest === null ? $buf : null];
        }
        throw new UpdateException('Zu viele Weiterleitungen beim Abruf von GitHub.');
    }
}
