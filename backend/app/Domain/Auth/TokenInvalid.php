<?php

namespace App\Domain\Auth;

/** El token del host no es válido. El detalle es para el registro interno; al cliente solo se le dice "no autenticado". */
class TokenInvalid extends \RuntimeException {}
