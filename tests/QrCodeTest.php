<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Service\QrCode;
use Vylaris\Tests\Support\TestCase;

final class QrCodeTest extends TestCase
{
    public function testSizeGrowsWithPayloadAndHasFinderPatterns(): void
    {
        $uri = 'otpauth://totp/VylarisAuth:anna%40example.org?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=VylarisAuth&algorithm=SHA1&digits=6&period=30';
        $m = QrCode::matrix($uri);
        $n = count($m);
        $this->assertTrue($n >= 45 && $n <= 57 && ($n - 17) % 4 === 0);
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
            $this->assertTrue($m[$y][$x] && $m[$y][$x + 6] && $m[$y + 6][$x] && $m[$y + 3][$x + 3], 'Finder-Muster');
            $this->assertFalse($m[$y + 1][$x + 1], 'Finder-Innenring hell');
        }
        $this->assertSame(21, count(QrCode::matrix('a')));
        $this->assertSame($m, QrCode::matrix($uri), 'deterministisch');
    }

    public function testSvgOutputIsSelfContainedAndEscapesNothingUserControlled(): void
    {
        $svg = QrCode::svg('<script>alert(1)</script>');
        $this->assertStringNotContains('<script', $svg);
        $this->assertStringContains('<svg', $svg);
        $this->assertTrue(str_starts_with(QrCode::dataUri('x'), 'data:image/svg+xml;base64,'));
    }

    public function testTooLongPayloadIsRejected(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => QrCode::matrix(str_repeat('x', 300)));
    }
}
