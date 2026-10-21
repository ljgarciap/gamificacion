# Diseño técnico de SPEC-003 (Arquitecto, C3)

Estado: **vigente**. Decisiones dentro del diseño aprobado en `spec-003-administracion-y-host.md`. Lo que cambia el diseño se escala a Luis.
Principio rector: **reutilizar lo que ya existe** (bitácora encadenada, cifrado de secretos, ciclo de vida del lote, outbox, `RedeemService`) y **no tocar el motor de sorteo** salvo P6, que tiene su propia revisión.

## 1. Orden de construcción (cada bloque se puede revisar y subir por separado)
1. **P1** Cuentas, roles, contraseña, TOTP, sesiones.
2. **P4** Parámetros (tabla, rangos, historial, caché) — P1 y P3 los necesitan.
3. **P2/P3** API `/admin` de campañas y lotes + control dual.
4. **P5** Hosts, callback firmado, saldo del host, simulador.
5. **P6** Giro extra (cambio del motor; revisión de seguridad aparte *antes* de integrar).
6. **P7/P8** Panel Angular. **P9** hardening. **P10** revisión final.

## 2. Superficie `/admin`: guardia propio
- Prefijo **`/admin/api/v1`**, guardia de sesión propio (`admin`) con su provider; **sin relación** con el JWT de los hosts (`/v1`). Ningún middleware se comparte salvo el de errores JSON.
- **Autenticación por sesión de Laravel (cookie `HttpOnly`, `Secure`, `SameSite=Lax`, driver `database`) + CSRF** (middleware `AdminForgery`, que a diferencia del de Laravel **no se desactiva en pruebas**; iniciar sesión renueva el token), sin Sanctum: el panel Angular y la API cuelgan del mismo sitio (subdominios del mismo dominio), así que no hace falta un token en el navegador (nada que robar por XSS). CORS solo para el origen del panel, con credenciales.
- La sesión guarda `admin_id` y `mfa_verified_at`; **una sesión sin `mfa_verified_at` no pasa de `/auth/*`**. Vencimiento por inactividad parametrizable; cambio de contraseña o de 2FA invalida las demás sesiones (se borran sus filas).
- Errores de login **idénticos** para correo inexistente, contraseña errónea, cuenta bloqueada o desactivada (sin oráculo), con el mismo tiempo de respuesta (se hace `Hash::check` contra un hash señuelo si no existe el usuario).

## 3. Modelo de datos (migraciones con SQL explícito donde hay invariantes)
| Tabla | Contenido y restricciones |
|---|---|
| `admin_users` | `id bigserial` (numérico: la tabla de sesiones de Laravel guarda `user_id` y así se pueden cerrar las sesiones de un usuario), `email` único (minúsculas), `name`, `role` CHECK en (`superadmin`,`operator`,`auditor`), `password_hash`, `totp_secret_enc` (cifrado con `SecretBox`), `totp_confirmed_at`, `totp_last_step` (anti-repetición), `recovery_codes` (solo huellas HMAC), `failed_attempts`, `locked_until`, `active`, `must_change_password`, `last_login_at` |
| `approvals` | `id`, `action` CHECK (`batch.activate`, `codes.generate`, `audit.export`, `setting.change`), `subject_type/id`, `params jsonb` (inmutable tras crear), `requested_by`, `expires_at`, `approved_by`, `approved_at`, `executed_at`, `status`. **CHECK `approved_by <> requested_by`** y trigger que impide cambiar `params`/`requested_by` y volver atrás un estado |
| `settings` / `settings_history` | clave, valor `jsonb`, tipo; el historial es **solo de agregar** (mismo trigger que la bitácora) |
| `hosts` | `issuer` único (el mismo de `GAMIF_HOSTS`), `callback_url`, `balance_url`, `secret_current_enc`, `secret_previous_enc`, `active` |

Invariantes en la base (P1): rol en los tres valores, correo en minúsculas y único, TOTP confirmado implica secreto, y un **trigger impide dejar el sistema sin un superadmin activo** (desactivar, cambiar de rol o borrar al último). El bloqueo de cuenta usa el **reloj de la aplicación** para escribir y comparar (no el de la base): mezclarlos lo haría frágil.

La **bitácora** (`audit_log`) se reutiliza tal cual: actor `admin:<uuid>`, acciones nuevas con prefijo `admin.`. El volumen del panel es bajo; el candado global no es un problema aquí (D7 solo afecta al canje).

## 4. Permisos
Matriz de `spec-003` §5 como **una constante de código** (`AdminPermissions`) consultada por un único middleware `admin.can:<permiso>`. Una prueba recorre **cada celda** de la matriz (CA-31) y una prueba de arquitectura comprueba que **toda ruta `/admin/*` declara un permiso** (una ruta sin permiso hace fallar la suite).

## 5. Cifrado de secretos: `SecretBox`
Generaliza lo que hace `PrizeVault` (XChaCha20-Poly1305 con datos asociados que ligan el texto cifrado a su fila: `tabla|id|columna`). Clave nueva `GAMIF_ADMIN_KEY` (32 bytes; `gamification:keys` la genera sin pisar la existente). Cifra el secreto TOTP y los secretos de host. Perder la clave invalida 2FA y callbacks (se documenta como `GAMIF_PRIZE_MAP_KEY`).

## 6. TOTP
Implementación propia de **RFC 6238** (HMAC-SHA1, 6 dígitos, 30 s) con comparación en tiempo constante, ventana ±1 paso y **rechazo de un paso ya usado** (`totp_last_step`). Se prueba con los **vectores oficiales del RFC**. Razón de no añadir una librería: cuarenta líneas verificables con vectores, y un riesgo de cadena de suministro menos en el componente más sensible. Base32 (RFC 4648) propio, también con vectores.

## 7. Contraseñas
`Hash::make` (bcrypt/argon2 según la configuración de Laravel). Reglas: mínimo parametrizable (por defecto 12), no igual al correo ni a su parte local, y no está en una lista embebida de contraseñas comunes. Sin llamadas a servicios externos.

## 8. Control dual (`ApprovalService`)
1. **Solicitar**: crea `approvals(status=pending)` con los parámetros exactos (p. ej. el id de lote); caduca según el parámetro.
2. **Aprobar**: otro usuario con permiso de aprobar; la base impide `approved_by = requested_by`. Exige haber pasado el 2FA en esta sesión **y reconfirmar un código TOTP nuevo** (no el último usado).
3. **Ejecutar**: **una sola vez**, dentro de una transacción con `SELECT … FOR UPDATE` sobre la fila de aprobación (dos ejecuciones simultáneas: una gana, la otra recibe un error); ejecuta **exactamente los `params` aprobados**, nunca los que mande el cliente en ese momento.
4. `codes.generate`: el CSV se genera al ejecutar y se entrega **una única vez** al solicitante (descarga con confirmación del SHA-256); si se pierde se recrea el lote, igual que hoy.
5. Con **un solo usuario con permiso de aprobar** el panel lo advierte; la autoaprobación solo existe en `local`/`testing`.

## 9. Parámetros (`Settings`)
Servicio que lee la tabla con **caché de 60 s** y cae al valor de `config/gamification.php` si la clave no existe. Cada clave se declara en un **registro de código** con tipo, **mínimo y máximo duros** (suelos de seguridad: p. ej. nunca 0 intentos, nunca un token mayor a 1 h); los valores de negocio los fija el superadmin dentro de esos límites. Cambiar un parámetro de seguridad pasa por control dual. Lo que hoy es `config('gamification.…')` se lee por `Settings`, sin cambiar los defaults.

## 10. Integración con el host (P5)
- `HttpPrizeFulfillment` reemplaza al stub: elige el host por el prefijo de `payload.user_id`, firma `timestamp + "." + cuerpo` con HMAC-SHA256 (secreto actual), envía `X-Gamif-Event-Id/Timestamp/Signature`. **Respuesta**: `2xx` entregado; `408/429/5xx` y fallos de red → excepción (la outbox reintenta con su espera); otros `4xx` → fallo permanente (nueva excepción `PermanentDeliveryFailure` que marca `failed` sin reintentar).
- `HttpBalanceProvider`: `GET` firmado; sin respuesta → `503 hostUnavailable` (código nuevo en OpenAPI y en el cliente Flutter), nunca un saldo inventado.
- **`HostUrlGuard`** (SSRF, ver amenazas): `https` obligatorio (`http` solo en `local`), sin credenciales en la URL, puerto 443 salvo lista parametrizada, resolución DNS propia, **rechazo de IP privadas/de bucle/enlace local/metadatos (IPv4 e IPv6)**, conexión **fijada a la IP validada** (evita *DNS rebinding*), **sin seguir redirecciones**, tiempo máximo y tope de tamaño de respuesta.
- **Host simulador**: servicio pequeño en `docker compose` (PHP, mismo contrato), con billetera de prueba y modos de fallo (lento, 500, 4xx, firma inválida) activables por cabecera para probar reintentos.

## 11. Giro extra (P6) — diseño del cambio del motor
Es el único bloque que toca lo ya auditado; **requiere revisión de Senior Reviewer y Cybersecurity antes de integrar**.
- **Reserva `R ≥ 0` por lote.** El mazo tiene `N + R` posiciones: las `N` primeras son de códigos (índices 0…N−1, como hoy) y las `R` siguientes son la **reserva** para giros hijos. Se baraja el mazo completo con el mismo algoritmo y la misma semilla.
- **Compromiso**: con `R = 0` se calcula **exactamente igual que hoy** (los vectores existentes no cambian ni un byte, requisito CA-38). Con `R > 0` se usa un dominio distinto (`gamif/v2/commit`) que incluye `R`, de modo que un verificador antiguo no pueda confundir ambos.
- **Almacenamiento**: tabla `reserve_slots(batch_id, idx, prize_enc, redemption_id NULL, consumed_at)` con el premio cifrado igual que en `codes` y con triggers de inmutabilidad análogos (premio *write-once*, consumo *write-once*).
- **Consumo**: `POST /v1/spins` `{parentRedemptionId}` con `Idempotency-Key`; el servidor comprueba que la redención padre es del mismo usuario y que le quedan giros por jugar, y consume **la siguiente casilla libre** (`FOR UPDATE SKIP LOCKED` ordenada por `idx`) en la misma transacción que crea la redención hija. Si no hay casilla: error explícito `spinReserveExhausted` (nunca un premio inventado).
- **Verificador y exportación**: incluyen las `R` casillas y la comprobación de stock sobre `N + R`.
- **Contrato y cliente**: endpoint nuevo en OpenAPI; el cliente Flutter juega los giros hijos con lo que devuelva el servidor.

## 12. Cliente Angular (P7/P8)
Proyecto `admin-panel/` (Angular, standalone components, sin librería de UI pesada; tokens del tema existente), servicio HTTP con credenciales y cabecera CSRF, guardas de ruta por rol (**solo comodidad: la autoridad es el servidor**), CSP estricta y **sin `innerHTML`** con datos de campañas (textos de la campaña se muestran escapados). Se diseña contra `docs/ux-spec-003.md` (UX/UI, C2) y se prueba con Playwright.

## 13. Pruebas obligatorias por bloque
Cada bloque: pruebas de la base (rechazo por trigger/CHECK con `assertDbRejects`), de permisos por rol, de concurrencia real cuando hay carrera (ejecución dual, consumo de reserva), **verificación por mutación** de las comprobaciones de seguridad (autoaprobación, caducidad, doble ejecución, rango de parámetros, SSRF, firma), y contrato OpenAPI actualizado.
