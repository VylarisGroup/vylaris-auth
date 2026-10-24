<?php
declare(strict_types=1);

namespace Vylaris\Core;

/** Minimal-Templates: PHP-Dateien mit erzwungenem Escaping über e(). */
final class View
{
    public function __construct(private string $dir, private array $shared = [])
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        return Watermark::ensure($this->capture($layout, ['content' => $content] + $data));
    }

    private function capture(string $template, array $data): string
    {
        if (!preg_match('/^[a-z0-9_\/-]+$/i', $template)) {
            throw new \InvalidArgumentException('Ungültiger Template-Name.');
        }
        $file = $this->dir . '/' . $template . '.php';
        $e = [self::class, 'e'];
        extract($this->shared, EXTR_SKIP);
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string) ob_get_clean();
    }
}
