<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Protección CSRF del panel. El middleware de Laravel se desactiva a sí mismo al correr las pruebas; aquí NO: las pruebas deben demostrar que una
 * petición sin token válido se rechaza (y la prueba de mutación lo comprueba). Origen válido: las peticiones del mismo sitio (`Sec-Fetch-Site`).
 */
final class AdminForgery extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
