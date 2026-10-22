# Contrato con la app host (SPEC-003 P5)

Lo que debe implementar la app host para (1) **recibir el aviso de acreditación** de un premio y (2) **responder el saldo** del usuario. Todo va firmado en los dos sentidos. El simulador (`host-sim/`, `docker compose up -d host-sim`) implementa este contrato y sirve de referencia ejecutable.

## 0. Antes de empezar
1. La app host ya autentica usuarios con un JWT (ver `docs/api-v1.md`); su **emisor** (`iss`) debe estar en `GAMIF_HOSTS`.
2. Un superadmin registra el host en el panel (`POST /admin/api/v1/hosts` → `issuer`, `callbackUrl`, `balanceUrl`). **El secreto de firma se muestra una sola vez**: guárdalo en el gestor de secretos del host. Si se pierde, se rota.
3. Las URL deben ser `https`, puerto 443 (u otro acordado), de un **dominio público** (no IP, no `localhost`, nada que resuelva a una red privada o de metadatos). **No se siguen redirecciones**: la URL registrada debe responder directamente.

## 1. Firma
- Secreto: el del host (32 bytes en base64url). Algoritmo: **HMAC-SHA256**, en hexadecimal minúsculas.
- Cabeceras en ambos sentidos: `X-Gamif-Timestamp` (segundos Unix) y `X-Gamif-Signature` = `HMAC(secreto, timestamp + "." + contenido)`.
- `contenido`:
  | Mensaje | Contenido firmado |
  |---|---|
  | Aviso (`POST`) | el **cuerpo exacto** recibido (bytes, sin reformatear) |
  | Consulta de saldo (`GET`) | `ruta?consulta` exactas de la petición, p. ej. `/gamif/balance?user=u-42` |
  | Respuesta del saldo | el **cuerpo exacto** devuelto |
- **Verificar siempre**: marca de tiempo dentro de **±5 min** (`host.signature_tolerance_seconds`), comparación en tiempo constante, y rechazar con `401` si algo falla. Durante una **rotación** el host debe aceptar el secreto nuevo y el anterior (nosotros firmamos con el nuevo; el anterior se retira cuando confirmes).

## 2. Aviso de acreditación — `POST callbackUrl`
Cabeceras: `Content-Type: application/json`, `X-Gamif-Event-Id` (= `redemption_id`), `X-Gamif-Timestamp`, `X-Gamif-Signature`.

```json
{
  "redemption_id": "6f1c…-uuid",
  "user_id": "superapp:u-42",
  "campaign_id": "…", "batch_id": "…",
  "prize": { "id": "p10k", "type": "money", "label": "$ 10.000", "amount": 10000 },
  "redeemed_at": "2026-10-21T12:00:00.000000Z"
}
```
- `user_id` = `<emisor>:<sub del token>`. El `sub` es lo que va tras el primer `:` del emisor registrado.
- `prize.type`: `money` y `points` llevan `amount` (entero; COP sin decimales, o puntos); `coupon` y `extraSpin` no acreditan dinero. (Un «sin premio» no genera aviso.)
- **Idempotencia obligatoria por `redemption_id` del cuerpo** (que va firmado): la entrega es *al menos una vez* (un reintento puede llegar aunque ya acreditaste). Si ya procesaste ese id, responde `200` **sin acreditar de nuevo**. `X-Gamif-Event-Id` no va firmado: **no lo uses como clave**; rechaza (`400`) el aviso si la cabecera difiere de `redemption_id`, o un aviso capturado podría reenviarse con otro id y acreditar varias veces.

| Respuesta del host | Qué hacemos |
|---|---|
| `2xx` | Entregado. |
| `408`, `425`, `429`, `5xx`, tiempo agotado, error de red | Se **reintenta** con espera creciente (`outbox.*`); al agotar intentos queda `failed` y un operador puede reencolarlo desde el panel. |
| Cualquier otro `4xx` o `3xx` | **Rechazo permanente**: no se reintenta; queda `failed`. (`401` = firma/secreto mal; `404` = URL mal.) |

El cuerpo de tu respuesta de error **no se guarda ni se muestra** (solo el estado).

## 3. Saldo — `GET balanceUrl?user=<sub>`
Cabeceras de la petición: `X-Gamif-Event-Id` (uuid de la consulta), `X-Gamif-Timestamp`, `X-Gamif-Signature` (sobre `ruta?consulta`).

Respuesta `200` con cabeceras `X-Gamif-Timestamp` y `X-Gamif-Signature` (sobre el cuerpo) y:
```json
{ "balance": 120000 }
```
- `balance`: **entero ≥ 0** (COP). Cualquier otra cosa (decimal, texto, negativo, ausente, cuerpo > 4 KB, sin firma, firma o marca inválida, estado ≠ 200) se trata como **host no disponible**: el usuario ve «no pudimos consultar tu saldo» (`503 hostUnavailable`). **Nunca se muestra un saldo inventado.**
- Tiempo máximo por llamada: `host.timeout_seconds` (5 s por defecto). Tras `host.breaker_failures` fallos seguidos (5) se corta al host `host.breaker_open_seconds` (30 s) para no encolar usuarios contra un host caído.

## 4. Seguridad: qué esperar de nosotros
Verificamos la URL contra SSRF **en cada llamada** y conectamos a la IP validada (si tu DNS cambia a una IP privada, no se llama), sin redirecciones, con tope de tiempo y de tamaño. TLS siempre verificado (certificado válido y vigente). User-Agent `gamification-callback/1`.

## 5. Probar con el simulador
```
docker compose up -d host-sim                       # http://localhost:8095
docker compose run --rm -e APP_ENV=local -e GAMIF_HOST_ALLOW_LOCAL=true -v "$PWD/backend/scripts:/scripts" backend php /scripts/e2e_host_sim.php
```
`POST /__sim/mode {"mode": "slow|http500|http403|http404|badsig|unsigned|oldts|bigbody", "count": N}` hace fallar las próximas N respuestas; `GET /__sim/state` muestra billetera, eventos (con duplicados) y rechazos de firma. El simulador usa `http` y una red privada: **solo funciona con `APP_ENV=local` y `GAMIF_HOST_ALLOW_LOCAL=true`** (las dos a la vez: un `APP_ENV=local` olvidado en producción no relaja la defensa SSRF).
