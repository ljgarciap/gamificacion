# API v1 — contrato (borrador, base del OpenAPI de B7)

Base: `/v1`. Todo requiere `Authorization: Bearer <JWT del host>`. Las respuestas son JSON y **nunca se cachean** (`Cache-Control: no-store`).

## Autenticación
El host emite un JWT corto y la API lo valida; el `userId` sale **solo del token**, nunca del cuerpo.

| Claim | Regla |
|---|---|
| `iss` | Emisor registrado en `GAMIF_HOSTS`. Su algoritmo y su clave los fija la configuración (HS256, RS256 o ES256); el `alg` del token se ignora. |
| `sub` | 1–128 caracteres ASCII imprimibles, sin espacios. El usuario es `"{iss}:{sub}"`. |
| `aud` | Debe incluir `gamification` (configurable). |
| `iat`, `exp` | Obligatorios y enteros. Vida máxima `exp − iat` = 600 s. Tolerancia de reloj: 30 s. |

Cualquier fallo de autenticación responde **401 idéntico** (sin decir por qué): `{"error":{"code":"unauthenticated","message":"No autenticado."}}` + `WWW-Authenticate: Bearer`.

## `POST /v1/codes/redeem`
Cabeceras: `Idempotency-Key: <8–128 caracteres [A-Za-z0-9._\-:]>` (obligatoria). Cuerpo: `{"code": "RULETA-K3J9-QW2X-7HC"}` (JSON o formulario).

El código se normaliza: minúsculas, espacios, guiones, el prefijo (decorativo) y las confusiones `O/0` e `I/L/1` se toleran.

**200** — resultado (mismo formato que lee el cliente Flutter, `Redemption.fromJson`):
```json
{
  "redemptionId": "uuid",
  "alreadyPlayed": false,
  "playedAt": "2026-10-20T12:00:00.123456Z",
  "campaign": {
    "id": "uuid", "name": "…", "gameType": "roulette|scratch|memory|dice", "usedCodeBehavior": "rejectOnly|showPrevious",
    "title": "Gira y Gana", "theme": {},
    "gameConfig": { "segments": [ {"id":"p50k","type":"money","label":"$ 50.000","amount":50000}, … ] }
  },
  "outcome": {
    "prize": {"id":"p50k","type":"money","label":"$ 50.000","amount":50000},
    "extraSpins": 0,
    "gameResult": { "segmentIndex": 3 }
  }
}
```
- Ruleta: `gameResult.segmentIndex`. Raspa: `gameResult.grid` (lista de símbolos; premio = 3 símbolos iguales al `prize.id`) y `gameConfig.rows/cols`.
- Concéntrese (`memory`): `gameResult.cards` (símbolo de cada carta, fila por fila; cada símbolo aparece exactamente dos veces; premio = una pareja cuyo símbolo es el `prize.id`, sin premio no hay ninguna) y `gameConfig.pairs/columns/flipBackMs`.
- Dados (`dice`): `gameResult.dice` (`[a, b]`, caras de 1 a 6; la combinación pertenece al premio según `gameConfig.legend`) y `gameConfig.diceCount/rollDurationMs/legend`.
- **Idempotencia:** el mismo usuario con la misma `Idempotency-Key` recibe **el mismo cuerpo, byte a byte**, sin jugar ni acreditar de nuevo. La misma clave con **otro código** → 422 `idempotencyKeyReuse`.
- Un intento fallido **no consume** la clave.
- `showPrevious`: el mismo usuario que vuelve a canjear su código (con otra clave) recibe su resultado anterior con `alreadyPlayed: true`. Nunca se muestra el resultado de **otra** persona.

**Errores** — forma única `{"error": {"code", "message", "retryAfter"?}}`:

| HTTP | `code` | Cuándo |
|---|---|---|
| 400 | `idempotencyKeyRequired` | Falta la clave o su formato es inválido |
| 401 | `unauthenticated` | Token ausente o inválido |
| 403 | `campaignInactive` | El lote está comprometido pero aún no activo |
| 409 | `alreadyUsed` | El código ya fue canjeado (por otra persona, o por ti si la campaña es `rejectOnly`) |
| 410 | `expired` | Lote cerrado/revelado o ventana de redención vencida |
| 422 | `invalidCode` | No existe, mal escrito, dígito de verificación incorrecto, o lote en borrador |
| 422 | `idempotencyKeyReuse` | La clave ya se usó con otro código |
| 429 | `rateLimited` | Bloqueo por intentos fallidos o tope de peticiones. Incluye `retryAfter` (s) y la cabecera `Retry-After` |
| 503 | `hostUnavailable` | `GET /v1/balance`: la app host no respondió o su respuesta no está firmada/es inválida (reintentar tras `Retry-After`; nunca un saldo inventado) |
| 500 | `serverError` | Error interno (nunca filtra detalles) |

**Bloqueo por intentos fallidos** (R1): al superar el máximo de fallos en la ventana (por usuario y, con un umbral mayor, por IP) el usuario queda bloqueado, y cada reincidencia **duplica** el tiempo hasta un tope. Cuentan los códigos inválidos y probar códigos ya usados por **otros**. Un canje exitoso reinicia el contador del usuario (no el de la IP). Parámetros en `config/gamification.php` (administración desde panel: SPEC-003).

## `POST /v1/spins` (giro extra)

Cuando una redención trae `outcome.extraSpins > 0`, cada giro se juega con `POST /v1/spins` `{ "parentRedemptionId": "<uuid>" }` y su propia `Idempotency-Key`.
El servidor consume la **siguiente casilla de la reserva** del lote y responde con la forma de una redención más `parentRedemptionId` y `spinNo`; un giro hijo
puede conceder más giros. El cliente nunca decide el resultado.

- **Clave estable por giro** (por ejemplo `<padre>:<n>`): reintentar con la misma clave devuelve la misma respuesta sin consumir otra casilla; una clave nueva es un giro nuevo.
- `404 spinNotFound` (padre ajeno, inexistente o mal formado: idéntico), `409 noSpinsLeft`, `409 spinReserveExhausted` (nunca se inventa un premio), `410 expired`.
- **Los giros pendientes caducan** cuando el lote se cierra o vence su ventana de redención.
- **Recuperar giros al volver a abrir la app:** si la campaña es `showPrevious` y se vuelve a enviar el código, la respuesta (`alreadyPlayed: true`) trae `pendingSpins: [{redemptionId, remaining, played}]` con los giros que aún quedan (de esa redención y de los giros que salieron de ella). El siguiente de cada una se pide con la clave `<redemptionId>:<played + 1>`. Se omite si no queda ninguno.
- Mismo límite de intentos que el canje: un padre ajeno cuenta como fallo.
- **Cambio para los hosts que implementan `GamificationRepository` (Flutter):** la interfaz ganó `spin(parentRedemptionId, {idempotencyKey})`; un adaptador propio debe implementarlo (llamar a `POST /v1/spins`) o dejará de compilar. `FailureReason` ganó `noSpinsLeft` y `spinReserveExhausted`.
- `meta.extra_spins` de un premio `extraSpin` es un entero de 1 a 10 (sin el campo vale 1).

## `GET /v1/balance`
`200 {"amount": 120000, "currency": "COP"}`. En el modo independiente es un saldo de demostración (inicial + premios en dinero ganados); en una app host, la billetera es del host (SPEC-002 D2).

## Acreditación al host (outbox)
Cada premio que no es "sin premio" deja, **en la misma transacción** que la redención, un aviso en `prize_outbox` (a lo sumo uno por redención). `gamification:outbox:dispatch` lo entrega **al menos una vez** con reintentos y espera creciente; el receptor debe ser idempotente por `payload.redemption_id`. Hoy el adaptador es un *stub* (SPEC-003 implementa el callback firmado).
