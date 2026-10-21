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
    | admin_key: cifra en reposo los secretos del panel (secreto TOTP y, después, secretos de host). Perderla invalida el 2FA de todos los
    | administradores (se recupera por consola, ver docs/diseno-spec-003.md §5).
    */
    'admin_key' => env('GAMIF_ADMIN_KEY'),

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

    /*
    | Utilidades SOLO de desarrollo local: un emisor de tokens (para que la app independiente pueda autenticarse sin una app host) y la lista de
    | códigos de demostración. Se activan con GAMIF_DEV_ENDPOINTS=true Y únicamente si APP_ENV es local o testing; en cualquier otro entorno las
    | rutas NO existen aunque la variable esté puesta.
    */
    'dev' => [
        'enabled' => filter_var(env('GAMIF_DEV_ENDPOINTS', false), FILTER_VALIDATE_BOOLEAN),
        // Emisor (debe existir en GAMIF_HOSTS) con el que se firman los tokens de desarrollo.
        'issuer' => env('GAMIF_DEV_ISSUER', 'standalone-dev'),
    ],

    /* Tope de códigos por lote (también lo valida el motor y la base). */
    'max_codes_per_batch' => 1_000_000,

    /*
    | Baliza pública (SPEC-002 §3). Por defecto drand "quicknet" (una ronda cada 3 s). La respuesta se valida contra
    | `chain_hash`; el activador NUNCA cae a una semilla sin baliza: si no hay valor todavía, espera y reintenta.
    */
    'beacon' => [
        // 'drand' (real) o 'local'. 'local' NO es aleatoria (devuelve un valor determinista) y solo se acepta en local/testing: sirve para
        // ver el flujo completo en desarrollo sin esperar a drand. Fuera de esos entornos, usarla es un error que se detecta al resolverla.
        'driver' => env('GAMIF_BEACON', 'drand'),
        'base_url' => env('DRAND_URL', 'https://api.drand.sh'),
        'chain_hash' => env('DRAND_CHAIN', '52db9ba70e0cc0f6eaf7803dd07447a1f5477735fd3f661792ba94600c84e971'),
        // La ronda comprometida es la primera posterior a (ahora + margen): da tiempo a publicar el compromiso antes.
        'margin_seconds' => (int) env('DRAND_MARGIN_SECONDS', 60),
        'timeout_seconds' => (int) env('DRAND_TIMEOUT_SECONDS', 5),
    ],

    /*
    | Panel de administración (SPEC-003). Valores iniciales: en P4 pasan a la tabla de parámetros (con rangos duros y control dual para los de
    | seguridad). El inicio de sesión se bloquea por CUENTA y por IP, con espera creciente.
    */
    'admin' => [
        'password_min_length' => (int) env('GAMIF_ADMIN_PASSWORD_MIN', 12),
        'login' => [
            'max_failures' => (int) env('GAMIF_ADMIN_MAX_FAILURES', 5),
            'lockout_base_seconds' => (int) env('GAMIF_ADMIN_LOCKOUT_BASE', 60),
            'lockout_max_seconds' => (int) env('GAMIF_ADMIN_LOCKOUT_MAX', 3600),
            'ip_max_failures' => (int) env('GAMIF_ADMIN_IP_MAX_FAILURES', 20),
            'ip_window_seconds' => (int) env('GAMIF_ADMIN_IP_WINDOW', 900),
            'ip_lockout_seconds' => (int) env('GAMIF_ADMIN_IP_LOCKOUT', 900),
        ],
        // Entre la contraseña correcta y el segundo factor (y el cambio de contraseña inicial) no puede pasar más que esto.
        'pending_ttl_seconds' => (int) env('GAMIF_ADMIN_PENDING_TTL', 600),
        'session_idle_minutes' => (int) env('GAMIF_ADMIN_SESSION_IDLE', 30),
        'session_absolute_hours' => (int) env('GAMIF_ADMIN_SESSION_MAX_HOURS', 12),
        'totp_window' => (int) env('GAMIF_ADMIN_TOTP_WINDOW', 1),
        'recovery_codes' => 10,
        'issuer' => env('GAMIF_ADMIN_ISSUER', 'Gamificacion'),
    ],

];
