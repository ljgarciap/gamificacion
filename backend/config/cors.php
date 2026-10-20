<?php

/*
| CORS de la API (SPEC-002). La web embebida (Flutter Web en un iframe o en la propia app) llama a la API desde el navegador, y el navegador
| solo lo permite para los orígenes que se listen aquí.
|
| NO hay comodín: la lista de orígenes autorizados viene de GAMIF_CORS_ORIGINS (separados por comas) y por defecto está VACÍA, es decir, ningún
| origen externo. Sin credenciales (cookies): la autenticación es por la cabecera Authorization, así que un sitio ajeno no puede aprovechar la
| sesión de nadie.
*/

return [

    'paths' => ['v1/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GAMIF_CORS_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Idempotency-Key', 'Accept'],

    // El cliente necesita leer cuánto esperar cuando lo bloquean.
    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
