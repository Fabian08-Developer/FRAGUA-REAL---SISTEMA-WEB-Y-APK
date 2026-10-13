<?php

namespace App\Support\Pasarela;

/** La pasarela no respondió o respondió con un error. Llega al navegador como 422 con el mensaje. */
class PasarelaNoDisponibleException extends \RuntimeException
{
}
