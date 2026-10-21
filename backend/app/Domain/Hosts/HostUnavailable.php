<?php

namespace App\Domain\Hosts;

/** La app host no respondió (o respondió algo inválido). Nunca se sustituye por un valor inventado. */
class HostUnavailable extends \RuntimeException {}
