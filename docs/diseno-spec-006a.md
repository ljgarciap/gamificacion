# Diseño de SPEC-006A — Cuentas persistentes de la muestra

Estado: **BORRADOR** (pendiente de aprobación). Rol: Arquitecto + Cybersecurity. Fecha: 2026-11-08.

## 1. Idea central
La cuenta es solo **la llave para llegar a un `subject` que ya existe**. Todo lo demás (saldo, pedidos, códigos del pool, barrera `DemoAccess`) ya está ligado a `user_id = emisor:subject` y no cambia. Ingresar con código = demostrar que eres dueño del correo → el servidor te entrega la sesión de ese `subject`. Cero cambios al libro de saldo, al sorteo ni a la barrera de canje.

## 2. Diseño
### A1. Tablas (migración nueva, SQL explícito)
- `demo_accounts(id uuid PK, email_hash bytea UNIQUE NOT NULL, email_enc bytea NOT NULL, subject varchar(80) UNIQUE NOT NULL REFERENCES demo_sessions(subject), created_at, last_login_at)`. `email_hash` = HMAC-SHA256 del correo normalizado con un pepper propio (`GAMIF_CODE_PEPPER`, ya existe; contexto distinto); `email_enc` con `SecretBox` (contexto `demo_account|{hash}|email`) para poder enviar el correo y mostrarlo enmascarado.
- `demo_login_codes(id bigserial PK, email_hash bytea NOT NULL, code_hash bytea NOT NULL, attempts smallint NOT NULL DEFAULT 0, created_at, expires_at, consumed_at)`. Un código activo por correo (índice parcial único donde `consumed_at IS NULL`); al pedir otro, se anula el anterior. Se guarda `HMAC(pepper, código)`, nunca el código.
- `demo_mail_log(id, email_hash, ip, created_at)` para los límites de envío (ventanas por correo, por IP y global); se purga en `demo:process`.
- **Sin tocar `demo_wallet_ledger`** (sigue de solo agregar). Borrar una cuenta = borrar `demo_accounts` (se destruyen correo y huella): el `subject` y su saldo quedan **huérfanos e inalcanzables** (crypto-shredding del vínculo personal). Esa es la respuesta técnica a D5 para este dato.
- `DemoConfig` agrega `accounts: {retentionDays (1–365, def. 30), codeTtlMinutes (5–30, def. 10), maxAccounts (techo duro 5000, def. 500)}`; una configuración guardada antes de esta spec se completa con los valores por defecto al leerla.

### A2. API pública (siempre registradas; apagada → 404, como el resto)
- `POST /v1/demo/auth/request {email}` → **200 `{sent: true, retryAfter}`** siempre (exista o no la cuenta, haya enviado o no por límite: ante un límite el cuerpo es el mismo y solo `retryAfter` cambia). Valida formato (RFC simplificado, ≤254, sin CR/LF, un solo `@`), normaliza (minúsculas, recorte) y envía.
- `POST /v1/demo/auth/verify {email, code}` → sesión (misma forma que hoy: token, expiresIn, subject, balance, products, rules, `resumed`, más `isNew`). Error único `invalidCode` (código errado, vencido, consumido o correo sin pedido) con el mismo cuerpo y estado.
- `GET /v1/demo/account` (token de sesión) → `{balance, codes: [{code, game, played}]}`: los códigos del usuario se descifran solo para su dueño (`demo_pool.code_enc`) y `played` sale de `codes.redeemed_at`.
- `POST /v1/demo/session`: **ya no abre sesiones nuevas**; solo renueva el token con `resume` (el `subject`, secreto de 96 bits que solo recibe quien pasó la verificación). Sin `resume` válido → 401.
- Admin (`demo.manage`): `GET /demo/accounts?page=` (enmascarado, saldo, último ingreso), `DELETE /demo/accounts/{id}`, `POST /demo/mail/test {to}`; `POST /demo/reset` recibe `{accounts: "keep"|"delete"}` (obligatorio).

### A3. Correo
- `Mail` de Laravel con un mailable de texto plano (sin enlaces, sin HTML): asunto fijo y cuerpo con el código y su vigencia. Remitente fijo de configuración.
- **Modo por entorno** (D26): variables estándar `MAIL_*`. `docker/compose.mailpit.yml` agrega el servicio `mailpit` (SMTP 1025, bandeja web 8025); en demostración real se apunta a un SMTP de verdad. El panel muestra el modo (host y puerto, **sin credenciales**).
- Envío **síncrono con plazo corto** (5 s). Si el SMTP falla, `auth/request` responde 503 `mailUnavailable` (no revela nada sobre la cuenta) y no consume el cupo de envíos. No se usa la cola: el worker solo corre cada minuto y el código vence en minutos.

### A4. Retención y reinicio
- `gamification:demo:process` (ya cada minuto) borra cuentas con `last_login_at < now() - retentionDays` y purga `demo_login_codes`/`demo_mail_log` vencidos; deja una entrada de bitácora con la cantidad (sin correos).
- `reset` con `accounts: "delete"` borra todas las cuentas dentro de la misma corrida; con `"keep"` no las toca. La elección y la cantidad quedan en la bitácora.

### A5. App (Flutter)
`DemoBackend` gana `requestCode(email)`, `verifyCode(email, code)` y `account()`. `DemoJourney` reemplaza el paso «Acceso» por: aviso de privacidad + correo → código → tienda. «Tus códigos» se llena con `account()` al ingresar. Botón «Salir». Textos es/en. `DemoApi` deja de abrir sesiones anónimas.

### A6. Panel (Angular)
Bloque «Cuentas» en la pantalla «Muestra»: totales, lista paginada enmascarada, borrar; parámetros de retención/cupo/vigencia del código; modo del correo + «Enviar correo de prueba»; diálogo de «Reiniciar» con la elección (conservar/borrar).

## 3. Amenazas (T-D11…T-D20)
| # | Amenaza | Mitigación |
|---|---|---|
| T-D11 | **Enumeración de cuentas** (¿este correo está registrado?) | `request` y `verify` dan siempre la misma respuesta; mismo trabajo en ambos caminos (también se envía para correos nuevos); sin diferencias de forma ni de estado; prueba que compara las respuestas byte a byte. |
| T-D12 | **Fuerza bruta del código** (6 dígitos = 1 M) | 5 intentos por código y se quema; límite de `verify` por IP y por correo; código con vigencia corta; comparación en tiempo constante; `HMAC` con pepper. |
| T-D13 | **Bombardeo de correos a terceros** (usar la muestra para spamear) | Espera entre envíos por correo (60 s), tope por hora por correo y por IP, tope global por hora (techos duros en código); cuerpo fijo sin contenido controlable; sin enlaces. |
| T-D14 | **Reutilización y carreras del código** | Consumo atómico (`UPDATE … WHERE consumed_at IS NULL AND expires_at > now() RETURNING`); un código activo por correo; probado con procesos reales. |
| T-D15 | **Toma de cuenta por el `subject`** | El `subject` solo se entrega tras verificar el código; es un secreto de 96 bits; `session` solo lo acepta como `resume`; el cliente lo guarda en memoria. |
| T-D16 | **Datos personales en registros/bitácora/panel** | Correo cifrado en base; solo huella en límites y códigos; bitácora y logs con el correo enmascarado o sin correo; el panel muestra solo la máscara. |
| T-D17 | **Inyección de cabeceras de correo** | Validación estricta del correo (sin CR/LF, un `@`, longitud) antes de tocar el mailer; el cuerpo no incluye datos del usuario. |
| T-D18 | **Agotamiento (miles de cuentas)** | Tope duro de cuentas (`maxAccounts`); registro nuevo se rechaza al llegar; retención automática. |
| T-D19 | **Normalización**: `A@x.com` vs `a@x.com` (cuentas duplicadas o choque) | Se normaliza a minúsculas y recortado; el `+alias` y los puntos **no** se colapsan (se documenta); la huella es del normalizado. |
| T-D20 | **Credenciales SMTP expuestas** | Solo en variables de entorno del servidor (nunca en el repositorio ni en el panel); el panel muestra host/puerto sin usuario ni clave; Mailpit solo en desarrollo, sin puertos públicos. |

## 4. Desglose propuesto
1. **M7 Backend:** migración, `DemoAccounts` (normalizar, HMAC, cifrado), `DemoLoginCodes` (emitir/verificar atómico, intentos, límites), mailable, endpoints públicos y de panel, `account`, `session` solo-resume, `DemoConfig.accounts`, retención en `demo:process`, `reset` con elección. Pruebas, concurrencia real, mutación, OpenAPI.
2. **M8 Panel:** bloque «Cuentas», parámetros, modo del correo + prueba, diálogo de reinicio. Unitarias y E2E.
3. **M9 App:** paso de correo + código, aviso de privacidad, «Tus códigos» desde el servidor, «Salir», textos es/en, `DemoApi`. Pruebas y contrato.
4. **M10 Despliegue y QA:** `compose.mailpit.yml`, variables SMTP en `demo.env.example` y `demo-up.sh`, docs, E2E completo con Mailpit (CA-20), `docs/qa-spec-006a.md`.
