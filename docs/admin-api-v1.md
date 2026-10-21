# API del panel de administración — v1 (SPEC-003)

Superficie **separada** de la API de jugadores (`/v1`, JWT de hosts): prefijo **`/admin/api/v1`**, sesión por cookie + CSRF. Un JWT de host no sirve aquí ni la sesión del panel en `/v1`.
Esta página crece con cada bloque; hoy cubre **P1** (cuentas, 2FA, usuarios). El contrato máquina-legible llegará con P2 (`docs/openapi-admin.yaml`).

## Convenciones
- **Sesión:** cookie `HttpOnly`, `SameSite=Lax` (y `Secure` en producción). Vence por inactividad (30 min por defecto) y por antigüedad absoluta (12 h).
- **CSRF:** toda petición que cambia estado envía el token en la cabecera `X-XSRF-TOKEN` (el valor de la cookie `XSRF-TOKEN`, que entrega `GET /auth/csrf` y se **renueva al iniciar sesión**). Sin token válido: `419 csrfMismatch`.
- **Errores:** misma forma que la API de jugadores: `{"error": {"code", "message", "retryAfter"?}}`. Nunca se revela *por qué* falló un inicio de sesión.
- **Permisos:** cada ruta exige un permiso (matriz en `docs/spec-003-administracion-y-host.md` §5). Sin permiso: `403 forbidden`. Sin sesión completa: `401 unauthenticated`.
- Las respuestas llevan `Cache-Control: no-store`.

## Inicio de sesión (en pasos)
Mientras no termina, la sesión está **pendiente** (caduca a los 10 min) y no da acceso a nada. La respuesta de cada paso dice cuál sigue (`next`).

| Paso | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| 0 | `GET /auth/csrf` | — | `204` + cookie `XSRF-TOKEN` |
| 1 | `POST /auth/login` | `{email, password}` | `{next: "password" \| "enroll" \| "mfa"}` |
| 2 | `POST /auth/password` | `{current, new}` | `{next}` (en el primer ingreso se cambia la temporal). Con sesión completa: `{ok: true}` y cierra las **otras** sesiones |
| 3 | `POST /auth/mfa/enroll` | — | `{secret, uri}` (Base32 y `otpauth://`; se muestra **una vez**) |
| 3b | `POST /auth/mfa/enroll/confirm` | `{code}` | `{recoveryCodes: [10], user}` (los códigos de recuperación se muestran **una vez**) y entra |
| 4 | `POST /auth/mfa` | `{code}` (TOTP de 6 dígitos **o** código de recuperación) | `{user}` y entra |
| — | `POST /auth/logout` | — | `204` |
| — | `GET /auth/me` | — | `{id, email, name, role, permissions}` |

**Errores del inicio de sesión:** `401 invalidCredentials` (contraseña/correo/cuenta bloqueada o desactivada: **idénticos**), `401 invalidCode` (segundo factor), `422 weakPassword` (la política dice qué falta), `429 rateLimited` (con `retryAfter`), `419 csrfMismatch`.

**Reglas:** contraseña de 12 a 72 bytes, sin el correo y no común. El TOTP es el de RFC 6238 (SHA-1, 6 dígitos, 30 s, ±1 paso) y **cada código sirve una sola vez**. Los códigos de recuperación (`XXXX-XXXX-XXXX-XXXX`, 80 bits) son de un solo uso. 5 fallos (contraseña **o** 2FA) bloquean la cuenta 60 s, y se duplica en cada nivel hasta 1 h; 20 fallos desde una IP la bloquean 15 min. Un acierto de contraseña **no** reinicia el contador: solo el segundo factor.

## Usuarios (solo `superadmin`, permiso `users.manage`)
| Ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /users` | — | `{users: [{id, email, name, role, active, mfaEnrolled, mustChangePassword, lastLoginAt}]}` |
| `POST /users` | `{email, name, role}` (`role`: `superadmin`, `operator`, `auditor`) | `201 {id, tempPassword}` — la temporal se devuelve **una vez** |
| `PATCH /users/{id}` | `{role?, active?, name?}` | `204`. Cambiar rol o desactivar **cierra sus sesiones** |
| `POST /users/{id}/reset-mfa` | — | `204`. Quita el 2FA y los códigos de recuperación; debe enrolar de nuevo |
| `POST /users/{id}/reset-password` | — | `{tempPassword}` (una vez). Levanta el bloqueo y cierra sus sesiones |

Errores: `422 validationFailed`, `404 notFound`, `409 lastSuperadmin` (la base impide dejar el sistema sin un superadmin activo).

## Parámetros (solo `superadmin`, permiso `settings.manage`)
| Ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /settings` | — | `{settings: [{key, label, unit, min, max, security, default, value, overridden, updatedBy, updatedAt}]}` |
| `PUT /settings/{key}` | `{value}` (entero dentro de `min`–`max`) | `204` |
| `GET /settings/{key}/history` | — | `{history: [{id, old, new, actor, changedAt}]}` (más reciente primero) |

Cada clave es la ruta de `config/gamification.php` y se declara en `SettingsRegistry` con **mínimo y máximo duros** (nunca 0 intentos, token de ≤ 1 h, contraseña de ≥ 12). Sin fila vale el default de config; un valor guardado fuera de rango se ignora (se usa el default y se registra). Lectura con caché de 60 s: un cambio aplica en menos de 1 minuto. El historial es de solo agregar (trigger) y cada cambio va a la bitácora.
Errores: `422 validationFailed` (tipo o rango), `404 notFound`, `409 approvalRequired`: los parámetros de seguridad (`security: true`) exigen control dual (RF-43) y **no se cambian por esta ruta**: se piden con `POST /approvals` (acción `setting.change`).

## Campañas y premios (leer: todos los roles, `campaigns.view`; escribir: superadmin y operador, `campaigns.manage`)
| Ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /campaigns` | — | `{campaigns: [{id, name, gameType, usedCodeBehavior, title, prizeCount, batchCount, frozen, createdAt, updatedAt}]}` |
| `GET /campaigns/{id}` | — | campaña completa: `theme`, `gameConfig`, `frozen` y `prizes: [{prizeId, type, label, amount, meta}]` |
| `POST /campaigns` | `{name, gameType, usedCodeBehavior?, title?, theme?, gameConfig?, prizes?: [{prizeId, type, label, amount?, meta?}]}` | `201 {id}` (todo o nada) |
| `PATCH /campaigns/{id}` | cualquiera de `name, gameType, usedCodeBehavior, title, theme, gameConfig` | `204` |
| `POST /campaigns/{id}/prizes` | `{prizeId, type, label, amount?, meta?}` | `201` |
| `PATCH /campaigns/{id}/prizes/{prizeId}` | `{type?, label?, amount?, meta?}` | `204` |
| `DELETE /campaigns/{id}/prizes/{prizeId}` | — | `204` |

`gameType`: `roulette`, `scratch`, `memory`, `dice`. `type` de premio: `money`, `coupon`, `points`, `extraSpin`, `none` (dinero y puntos exigen `amount`, entero ≥ 0, COP sin decimales). `prizeId`: 1–64 caracteres `[A-Za-z0-9_-]`. Textos sin caracteres de control; `theme`/`gameConfig` son objetos de hasta 16 KB. La configuración del juego se valida a fondo al **comprometer** el lote, no aquí.
**Congelación (la impone la base):** con un lote comprometido ya no cambian `gameType`, `usedCodeBehavior` ni `gameConfig`, ni los premios que ese lote usa → `409 campaignFrozen`. El título, el tema y los premios no usados siguen editables. Una campaña no se borra.
Errores: `422 validationFailed`, `404 notFound`, `409 duplicate|campaignFrozen`, `403 forbidden`.

## Lotes (permisos `batches.view` / `batches.manage`)
Leer y buscar: todos los roles. Crear, comprometer, cerrar y revelar: superadmin y operador. **Activar no está aquí**: es la acción de control dual `batch.activate`.
| Ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /batches?campaignId=&state=` | — | `{batches: [{id, campaignId, name, state, totalCodes, generatedCodes, redeemed, redeemableUntil, …fechas}]}` (máx. 500) |
| `GET /batches/{id}` | — | detalle: `pool`, `poolHash`, `redeemedByPrize`, compromiso público (`commitment`, `codesDigest`, `chainHash`, `beaconRound`, `beaconValue`) y `seed` **solo tras revelar** |
| `POST /batches` | `{campaignId, name, pool: [{prizeId, quantity}], redeemableUntil?}` | `201 {id}` (borrador) |
| `POST /batches/{id}/commit` | — | recibo del compromiso (requiere todos los códigos generados) |
| `POST /batches/{id}/close` · `/reveal` | — | `204` |
| `GET /batches/{id}/redemptions?page=&perPage=` | — | `{items: [{id, userId, prizeId, index, redeemedAt}], total, page, perPage}` (`perPage` ≤ 200) |
| `POST /codes/lookup` | `{code}` | `{batchId, batchName, batchState, index, redeemed, redeemedAt, redeemedBy, prizeId}` |

La búsqueda de código es POST para que el código no quede en registros de acceso; acepta el formato que escribe una persona (minúsculas, guiones, O/0, I/L/1). **Un código sin canjear nunca revela su premio** (`prizeId: null`): la baraja es secreta hasta revelar. Cada búsqueda queda en la bitácora (`code.lookup`, sin el código).
Errores: `422 validationFailed|codeInvalid`, `404 notFound`, `409 wrongState|batchNotReady`, `503 beaconUnavailable`, `403 forbidden`. La generación de códigos (`codes.generate`) y la exportación de auditoría (`audit.export`) llegan en P2b-2.

## Control dual (permisos `approvals.view|request|approve`)
Flujo: **solicitar → otro usuario aprueba con un código TOTP nuevo → el solicitante ejecuta (una sola vez)**. Se ejecutan siempre los `params` guardados al solicitar (inmutables); caducan (`admin.approval_ttl_hours`, 24 h por defecto) y se comprueba al aprobar **y** al ejecutar.
| Ruta | Cuerpo | Respuesta |
|---|---|---|
| `GET /approvals` (todos los roles) | — | `{approvals: [{id, action, subjectType, subjectId, params, status, requestedBy, approvedBy, requestedAt, expiresAt, approvedAt, executedAt}], fewApprovers}` — `fewApprovers: true` = menos de 2 personas pueden aprobar (RF-45) |
| `POST /approvals` (superadmin, operador) | `{action, params}` | `201 {id}` |
| `POST /approvals/{id}/approve` (superadmin, operador) | `{code}` (TOTP **nuevo**, no el del inicio de sesión) | `204` |
| `POST /approvals/{id}/execute` (solo el solicitante) | — | `204` |

Acciones hoy: `batch.activate` (`{batchId}`, lote `committed`; permiso `batches.manage`) y `setting.change` (`{key, value}` de un parámetro `security`; solo superadmin). `codes.generate` y `audit.export` llegan con P2.
Errores: `422 validationFailed|invalidCode`, `404 notFound`, `403 forbidden|selfApproval|notRequester`, `409 alreadyPending|wrongState|expired|beaconNotReady` (la activación espera la ronda de drand; la aprobación sigue vigente y se reintenta). **No existe autoaprobación en ningún entorno** (CHECK en la base): en desarrollo usa dos cuentas.

## Primer superadmin
Solo por consola (no hay usuarios por defecto ni ruta de registro):
```
php artisan admin:create-superadmin correo@ejemplo.com --name="Nombre"
```
Imprime una contraseña **temporal** una sola vez; al entrar se debe cambiarla y enrolar el segundo factor.

## Bitácora
Cada acto (alta, cambio, reinicio de 2FA o de contraseña, inicio de sesión, fallo, bloqueo, uso de un código de recuperación) queda en la bitácora encadenada con actor `admin:<id>` (o `cli`). El correo y la IP van como **referencia irreversible (HMAC)**; jamás contraseñas, secretos ni códigos.

## Claves y entorno
`GAMIF_ADMIN_KEY` (32 bytes, la genera `php artisan gamification:keys`) cifra el secreto TOTP en reposo, ligado a su fila. **Perderla invalida el 2FA de todos**: se recupera con `reset-mfa` por un superadmin que aún pueda entrar o, si no hay ninguno, por consola. En producción: `SESSION_SECURE_COOKIE` se fuerza a verdadero y `GAMIF_TRUSTED_PROXIES` debe estar configurado (ver `docs/seguridad-b8.md`).
