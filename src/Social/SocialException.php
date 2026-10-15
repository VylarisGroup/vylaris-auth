<?php
declare(strict_types=1);

namespace Vylaris\Social;

/** Fehler im Social-Login-Ablauf. Die Meldung ist nur für Audit-Log/Debugging, nie für die Anzeige gedacht. */
final class SocialException extends \RuntimeException
{
}
