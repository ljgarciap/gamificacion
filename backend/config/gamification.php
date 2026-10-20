<?php

return [

    /*
    | Secretos del motor de premios (SPEC-002). Ambos son 32 bytes en base64. NUNCA se versionan:
    | en desarrollo se generan con `php artisan gamification:keys --write`; después irán a un gestor de secretos.
    |
    | prize_map_key: cifra el mapa código→premio y la semilla en reposo (R3: ni quien lea la base sabe qué código gana).
    | code_pepper:   clave de la huella HMAC de los códigos (R2: una fuga de la base no revela códigos sin redimir).
    */
    'prize_map_key' => env('GAMIF_PRIZE_MAP_KEY'),
    'code_pepper' => env('GAMIF_CODE_PEPPER'),

    /*
    | Apps host autorizadas a llamar a la API (SPEC-002 R9). JSON en GAMIF_HOSTS: {"emisor": {"alg": "HS256", "key": "<base64 de 32+ bytes>"}}
    | o {"emisor": {"alg": "RS256"|"ES256", "key": "<PEM de la clave PÚBLICA>"}}. El algoritmo y la clave los fija ESTA configuración por
    | emisor: nunca se confía en lo que declare el token (evita `alg: none` y confusión de algoritmos).
    */
    'hosts' => json_decode((string) env('GAMIF_HOSTS', '{}'), true) ?: [],

    'token' => [
        'audience' => env('GAMIF_TOKEN_AUDIENCE', 'gamification'),
        // Tokens cortos: un token robado sirve poco tiempo.
        'max_lifetime_seconds' => (int) env('GAMIF_TOKEN_MAX_LIFETIME', 600),
        'leeway_seconds' => (int) env('GAMIF_TOKEN_LEEWAY', 30),
    ],

    /*
    | Límites de intentos fallidos al redimir (R1) y de peticiones. Bloqueo progresivo: cada vez que se supera el máximo de fallos
    | dentro de la ventana, el bloqueo dura el doble que el anterior (hasta el tope). Por ahora son configuración; su administración
    | desde un panel (regla de parámetros) llega con SPEC-003.
    */
    'redeem' => [
        'user' => [
            'max_failures' => (int) env('GAMIF_USER_MAX_FAILURES', 5),
            'window_seconds' => (int) env('GAMIF_USER_WINDOW', 600),
            'lockout_base_seconds' => (int) env('GAMIF_USER_LOCKOUT_BASE', 60),
            'lockout_max_seconds' => (int) env('GAMIF_USER_LOCKOUT_MAX', 3600),
        ],
        // Por IP el máximo es mayor: varias personas pueden compartir una (oficina, red móvil).
        'ip' => [
            'max_failures' => (int) env('GAMIF_IP_MAX_FAILURES', 30),
            'window_seconds' => (int) env('GAMIF_IP_WINDOW', 600),
            'lockout_base_seconds' => (int) env('GAMIF_IP_LOCKOUT_BASE', 60),
            'lockout_max_seconds' => (int) env('GAMIF_IP_LOCKOUT_MAX', 3600),
        ],
        'requests_per_minute' => (int) env('GAMIF_REQUESTS_PER_MINUTE', 60),
    ],

    /* Entrega de avisos de acreditación al host (outbox transaccional): al menos una vez, con reintentos y espera creciente. */
    'outbox' => [
        'max_attempts' => (int) env('GAMIF_OUTBOX_MAX_ATTEMPTS', 8),
        'backoff_base_seconds' => (int) env('GAMIF_OUTBOX_BACKOFF_BASE', 30),
        'backoff_max_seconds' => (int) env('GAMIF_OUTBOX_BACKOFF_MAX', 3600),
    ],

    /* Solo para el modo independiente/demostración: saldo inicial de cada usuario. Una app host real tiene su propia billetera. */
    'demo' => [
        'initial_balance' => (int) env('GAMIF_DEMO_INITIAL_BALANCE', 0),
    ],

    /* Tope de códigos por lote (también lo valida el motor y la base). */
    'max_codes_per_batch' => 1_000_000,

    /*
    | Baliza pública (SPEC-002 §3). Por defecto drand "quicknet" (una ronda cada 3 s). La respuesta se valida contra
    | `chain_hash`; el activador NUNCA cae a una semilla sin baliza: si no hay valor todavía, espera y reintenta.
    */
    'beacon' => [
        'base_url' => env('DRAND_URL', 'https://api.drand.sh'),
        'chain_hash' => env('DRAND_CHAIN', '52db9ba70e0cc0f6eaf7803dd07447a1f5477735fd3f661792ba94600c84e971'),
        // La ronda comprometida es la primera posterior a (ahora + margen): da tiempo a publicar el compromiso antes.
        'margin_seconds' => (int) env('DRAND_MARGIN_SECONDS', 60),
        'timeout_seconds' => (int) env('DRAND_TIMEOUT_SECONDS', 5),
    ],
];
