# Revisión de seguridad B8 (SPEC-002) — 2026-10-20

Alcance: backend Laravel (auth del host, canje, límites de intentos, outbox, endpoints de desarrollo, CORS, exportación de auditoría, configuración y Docker). Método: lectura del código contra los riesgos R1–R10 de la spec, `composer audit`, y pruebas.

## Hallazgos

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| F1 | **Alta** (solo producción) | Sin proxies de confianza configurables, detrás de un balanceador todas las peticiones tienen la IP del proxy: el bloqueo por IP (30 fallos / 10 min) lo compartirían todos los usuarios, y una sola persona podría dejar sin canje a toda la plataforma. | **Corregido**: `GAMIF_TRUSTED_PROXIES` (vacío por defecto). Pruebas `WithTrustedProxiesTest` / `WithoutTrustedProxiesTest`. |
| F2 | Media | Falta decidir el control dual para exportar la auditoría (R3) y la firma de la exportación. | **Abierta, decisión de Luis.** La integridad hoy descansa en el ancla publicada. |
| F3 | Media (producción) | `.env.example` trae `APP_DEBUG=true`. Con debug activo se filtran trazas aunque el manejador oculte el detalle. | Requisito de despliegue: `APP_DEBUG=false`, `APP_ENV=production`. |
| F4 | Media (producción) | Los secretos (`GAMIF_PRIZE_MAP_KEY`, `GAMIF_CODE_PEPPER`, `GAMIF_HOSTS`) viven en `.env`. Perder la clave del mapa deja ilegibles los lotes comprometidos; filtrarla, junto con la base, revela qué código gana. | Requisito de despliegue: gestor de secretos y copia de respaldo de la clave del mapa. |
| F5 | Baja | La imagen del backend corre como root con el servidor integrado de PHP. | Solo desarrollo. **Cerrado en P9**: imagen de producción php-fpm + nginx sin root (`docs/hardening-p9.md`). |
| F6 | Baja | `GET /v1/balance` no tiene límite propio (el canje sí). Exige JWT válido. | Aceptado; el limitador general del servidor/proxy lo cubre. |
| F7 | Baja | `prize_outbox.last_error` guarda hasta 500 caracteres del mensaje de error del host; podría incluir una URL o un dato del host. | Revisar al implementar el adaptador real (SPEC-003). |
| F8 | Info | Sin cabeceras `X-Content-Type-Options` en la API. | **Cerrado en P9** (nginx de la API). |
| F9 | Info | No hay rotación de claves de host con `kid`: cambiar la clave de un emisor invalida sus tokens de golpe. | Previsto con un segundo emisor temporal. |

## Verificado, sin hallazgo

- **Auth del host (R9):** algoritmo y clave los fija la configuración por emisor, el `alg` del token se ignora (`none` y confusión HS256/RS256 probados); `exp`, `iat`, `sub`, `aud` obligatorios; vida máxima de 10 min; clave HS256 corta rechazada por la biblioteca (hay prueba); el usuario sale solo del token; el 401 es idéntico para toda causa.
- **Fuerza bruta de códigos (R1):** 50 bits de entropía + bloqueo progresivo por usuario e IP + tope de peticiones por minuto; el código se limita a 128 caracteres antes de hashear; las claves de caché usan hash del id y de la IP.
- **Fuga de la base (R2, R3):** solo huellas HMAC con pepper fuera de la base; mapa y semilla cifrados en reposo y ligados a su fila; el texto plano de los códigos existe solo en el CSV exportado una vez.
- **Manipulación tras el compromiso (R4):** triggers de PostgreSQL + bitácora encadenada + ancla externa (B6), todo verificado por mutación.
- **Idempotencia y concurrencia:** probadas con procesos reales; la respuesta se guarda byte a byte y la clave de idempotencia está acotada al usuario.
- **Endpoints y baliza de desarrollo:** rutas `/v1/dev/*` solo con la variable activa Y `APP_ENV` local/testing; la baliza local no se construye fuera de eso; `SeedDemo` se niega a correr en producción; los códigos de demostración quedan en `storage/app` (ignorado por git).
- **CORS:** sin comodín, sin credenciales, lista vacía por defecto.
- **Errores:** en `/v1/*` un error interno devuelve un 500 genérico sin trazas (con `APP_DEBUG=false`).
- **Dependencias:** `composer audit` sin avisos de seguridad.
- **Exportación de auditoría:** solo por línea de comandos (no hay ruta HTTP); no contiene datos personales (seudónimo `user_ref`).

## Antes de producción (lista corta)

1. `APP_ENV=production`, `APP_DEBUG=false`, `GAMIF_DEV_ENDPOINTS` ausente, `GAMIF_BEACON=drand`.
2. `GAMIF_TRUSTED_PROXIES` con la red del balanceador (no `*` salvo que la app solo sea alcanzable por el proxy).
3. Secretos en un gestor; respaldo cifrado de `GAMIF_PRIZE_MAP_KEY`.
4. Publicar un ancla de la bitácora al comprometer cada lote (`gamification:audit:anchor`) en un sitio que el operador no controle solo.
5. Decidir F2 (control dual y firma).
