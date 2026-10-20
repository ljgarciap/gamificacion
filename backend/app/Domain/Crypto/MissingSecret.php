<?php

namespace App\Domain\Crypto;

/** Falta un secreto del motor de premios o no tiene el formato esperado (32 bytes en base64). */
class MissingSecret extends \RuntimeException {}
