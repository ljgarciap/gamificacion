<?php

namespace App\Domain\Beacon;

/** La baliza no responde o devolvió algo incoherente. El sistema no cae a una semilla sin baliza: se reintenta. */
class BeaconUnavailable extends \RuntimeException {}
