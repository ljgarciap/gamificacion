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
