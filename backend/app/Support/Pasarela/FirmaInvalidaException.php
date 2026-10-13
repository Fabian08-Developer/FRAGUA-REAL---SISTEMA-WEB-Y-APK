<?php

namespace App\Support\Pasarela;

/** El evento no viene firmado por la pasarela (o se alteró en el camino): no se aplica nada. */
class FirmaInvalidaException extends \RuntimeException
{
}
