<?php

namespace App\Domain\Hosts;

/** La URL de un host no es segura de llamar (SSRF) o está mal formada. El mensaje es para el superadmin que la escribió. */
final class HostUrlRejected extends \InvalidArgumentException {}
