<?php

namespace App\Domain\Admin;

/** Cualquier fallo de inicio de sesión. Es deliberadamente UNO solo: el cliente no debe poder distinguir la causa. */
final class LoginFailed extends \RuntimeException {}
