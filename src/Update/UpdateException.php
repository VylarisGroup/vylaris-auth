<?php
declare(strict_types=1);

namespace Vylaris\Update;

/** Fehler beim Prüfen/Installieren eines Updates (Meldung ist für Administratoren lesbar, enthält nie Token). */
final class UpdateException extends \RuntimeException
{
}
