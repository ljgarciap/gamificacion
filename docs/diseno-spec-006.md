# Diseño SPEC-006 — Muestra real desde el panel

Estado: **PROPUESTO** por el Arquitecto (2026-11-07), con threat model de Cybersecurity en la parte B. Spec: `docs/spec-006-demo-desde-el-panel.md` (aprobada con sus valores por defecto).

# Parte A — Diseño

## A1. Hallazgos que condicionan el diseño
- `Settings` solo guarda **enteros** con mín/máx; el escenario (productos, reglas, mezcla) es estructurado → **tabla propia** con validador de límites duros, no `SettingsRegistry`.
- El texto en claro de un código **no está en la base** (solo la huella). El demo actual lo guarda en `storage/app/demo-codes.json`. Para entregar un código sorteado hace falta el claro → **tabla `demo_pool` cifrada**, solo para lotes demo.
- Las rutas `/v1/demo/*` se registran al arrancar según el entorno. Para encender desde el panel sin reiniciar → rutas **siempre registradas** + middleware `demo.active` que lee la base y da 404 si está apagado.
- Sembrar espera a drand (~1 min por lote): no puede ir en la petición web → **trabajo asíncrono** que ejecuta el servicio `worker`, con estado consultable desde el panel.
- Los lotes comprometidos son inmutables y no se borran: «reiniciar» = **retirar** el pool anterior (cerrar lotes) y sembrar uno nuevo.

## A2. Modelo de datos (migración `2026_11_xx_create_demo_tables`)
- `demo_config` (fila única, `id=1`): `enabled bool`, `enabled_until timestamptz null`, `config json` (usuario, productos, regla, mezcla), `version int`, `updated_by`, `updated_at`. El historial va a la **bitácora de administración** existente (sin secretos).
- `demo_pool`: `id`, `batch_id`, `code_enc` (cifrado con `SecretBox`/clave de app), `code_hash` (para cruzar con `codes`), `game`, `description`, `state` (`available|assigned|retired`), `assigned_to`, `assigned_at`, `generation int`. Índice parcial en `state='available'`.
- `demo_wallet_ledger` (solo insertar): `user_id`, `kind` (`purchase_credit`), `amount`, `order_id`, `created_at`. `StubBalanceProvider` suma saldo inicial + premios en dinero + este libro.
- `demo_orders`: `id` (UUID), `user_id`, `items json`, `total`, `credited`, `codes_granted`, `idempotency_key` (único por usuario). Hace la compra idempotente.
- `demo_runs`: `id`, `kind` (`seed|reset`), `state` (`pending|running|done|failed`), `error`, `requested_by`, tiempos. Una sola corrida activa (índice único parcial).
- `campaigns.is_demo bool default false` (la siembra demo marca la campaña; nada más puede marcarla).

## A3. Configuración (`DemoConfig`, JSON validado)
```
user:    { displayName, hostUserPrefix, initialBalance }
products:[ { id, name, price } ]            // 1..5
credit:  { balancePercent, minPurchase, codeStepAmount, maxCodesPerPurchase }
mix:     { <game>: { prizes:[{type, amount, count}], noPrizeCount } }
ttlHours: int|null
```
Límites duros en código (techos de seguridad: máx. 5 productos, precio ≤ tope, `maxCodesPerPurchase` ≤ 5, pool total ≤ 500 códigos). **Los valores de negocio no se hardcodean**: precios, porcentajes y mezcla vienen de la configuración; el código solo define los suelos/techos. Los `game_config` de presentación de cada juego (segmentos, casillas) siguen como plantillas en `DemoSeeder`, extraídas de `SeedDemo` — no son umbrales de negocio.

## A4. Backend
**Activación** (`DemoSwitch`): encender exige, en la misma transacción: `GAMIF_DEMO_ALLOWED=true` (infraestructura), emisor demo válido en `GAMIF_HOSTS`, pool con códigos `available`, **ninguna campaña activa con `is_demo=false`**. Apagar es inmediato. `enabled_until` se comprueba en cada petición (perezoso) y el planificador lo apaga además cada minuto.

**Panel** `/admin/api/v1/demo` (permiso nuevo `demo.manage`, solo superadmin; fila en `AdminPermissionsTest`):
`GET /demo` (config + estado + conteos del pool + última corrida), `PUT /demo/config`, `POST /demo/enable`, `POST /demo/disable`, `POST /demo/seed` (encola siembra con la mezcla vigente), `POST /demo/reset`, `GET /demo/runs/{id}`. Documentado en `openapi-admin.yaml` (lo valida `AdminOpenApiContractTest`).

**Superficie pública demo** `/v1/demo/*` (middleware `demo.active` + límite por IP; sin JWT salvo donde se indica):
- `GET /demo/status` → `{enabled}` (la app decide modo normal o muestra; 404/`false` si apagado).
- `POST /demo/session` → crea usuario simulado de sesión, devuelve token (reutiliza `DevTokenController::LIFETIME` y la emisión actual), configuración pública (catálogo, textos de reglas) y saldo.
- `POST /demo/purchase` (JWT demo + `Idempotency-Key`): calcula total con precios **del servidor** (el cliente solo manda ids y cantidades), acredita `floor(total·balancePercent/100)` al libro, calcula `codes = min(floor(total/codeStepAmount), max)` y **sortea** ese número de códigos con CSPRNG; devuelve orden, saldo nuevo y códigos.
- Se retiran `/v1/demo/token` y `/v1/demo/codes` del flujo de muestra (el panel de códigos de prueba solo se conserva si el modo muestra está apagado, en local).

**Sorteo (`DemoPoolDraw`)**: dentro de una transacción con `pg_advisory_xact_lock` (serializa; la demo no necesita más caudal): contar `available`, elegir índice con `random_int` (fuera de `app/Engine/`, que sigue sin azar propio), tomar esa fila y marcarla `assigned`. El premio de cada código ya está fijado por el motor al comprometer el lote, así que la mezcla configurada es exacta. Sin `SKIP LOCKED` para no sesgar la distribución.

**Canje**: sin cambios en `RedeemService`, con **un solo gancho**: un usuario del emisor demo solo puede canjear códigos de lotes cuya campaña tenga `is_demo=true`, y un código de campaña demo solo lo canjea un usuario demo.

**Siembra asíncrona (`DemoSeeder`)**: extrae de `SeedDemo` la creación de campaña/lote/códigos parametrizada por `mix`; marca `is_demo`; guarda cada código cifrado en `demo_pool`; usa la baliza configurada (drand en servidor, ~1 min). El `schedule:work` ejecuta cada minuto `gamification:demo:process` (toma la corrida `pending`). «Reiniciar»: marca el pool `retired`, cierra los lotes demo, siembra de nuevo y limpia libro/pedidos del usuario de sesión. `SeedDemo` pasa a usar `DemoSeeder` con la mezcla por defecto para local.

**Retirada de `GAMIF_DEMO_MODE`**: se elimina `gamification.demo.enabled`; queda `GAMIF_DEMO_ALLOWED`. `demo-up.sh`, `compose.demo.yml`, `docs/despliegue-demo.md`, `docs/ci-cd.md` y la compilación web (ya no necesita el flag: detecta el modo en tiempo de ejecución con `/demo/status`).

## A5. Panel Angular (pantalla «Muestra», ruta `/muestra`, guarda `demo.manage`)
Cuatro bloques: **Escenario** (usuario y productos), **Reglas de carga**, **Mezcla de premios** (tabla por juego con totales y validación en vivo), **Estado** (chip encendida/apagada, TTL, progreso de la siembra con sondeo, botones Sembrar/Reiniciar/Encender/Apagar con diálogo de confirmación). Sin atributos `style` estáticos (CSP). Textos es.

## A6. App (Flutter)
- `gamification_http`: `DemoApi` (status, session, purchase). Sin tocar `GamificationRepository`, el núcleo ni los juegos.
- `gamification_sdk/flow`: `DemoJourney` con pasos *Acceso → Tienda → Pago simulado → Saldo cargado → Tus códigos → Juego*, que entrega el código al `FlowController` existente (mismo `redeem`). Con varios códigos, los juega en secuencia.
- `apps/standalone`: al abrir pide `/demo/status`; si `enabled`, `DemoJourney`; si no, el flujo actual. Textos es/en en `GamificationStrings`.

## A7. Plan de pruebas y verificación
Pruebas de feature por CA-1…CA-10; **mutación** (norma del proyecto): quitar 404 con modo apagado, permiso `demo.manage`, condición `GAMIF_DEMO_ALLOWED`, condición de campañas reales, gancho `is_demo` en el canje (ambos sentidos), precios del servidor, idempotencia de la compra, bloqueo advisory (sorteo duplicado con 50 procesos reales con `Tests\Support\Parallel`), caducidad, descarte de `retired`. Estadística del sorteo (chi-cuadrado) y recorrido real con drand + verificador independiente sobre el lote demo. E2E Playwright panel → app. QA por el rol QA al cierre.

## A8. Riesgos y decisiones técnicas tomadas
- Guardar el código en claro cifrado rompe «solo la huella» pero **únicamente** en `demo_pool` y para lotes `is_demo`; nunca para campañas reales (CHECK: `batch_id` debe pertenecer a campaña demo, comprobado al insertar).
- Serializar el sorteo limita el caudal; aceptado (demo, ≈ decenas de usuarios).
- Siembra con drand tarda ~1 min por lote: la UI lo comunica; con `GAMIF_BEACON=local` es inmediata en desarrollo.

# Parte B — Threat model (Cybersecurity)

| # | Amenaza | Control exigido |
|---|---|---|
| T-D1 | El modo muestra queda encendido en un servidor con campañas reales y el emisor abierto reparte tokens | `GAMIF_DEMO_ALLOWED` solo en servidores demo; enciende solo sin campañas no-demo activas; **además** el gancho de canje impide que un token demo toque un lote real aunque la primera barrera falle |
| T-D2 | Un actor externo abusa de `/demo/session` y `/demo/purchase` (inundar el pool, agotar premios) | Límite por IP, compuerta de Caddy, tope de códigos por compra y por sesión, TTL del modo |
| T-D3 | El cliente manipula precio/cantidad para ganar más códigos o saldo | Precios, regla y tope solo en el servidor; el cliente envía ids y cantidades enteras acotadas |
| T-D4 | Doble envío o reintento duplica compra, saldo o códigos | `Idempotency-Key` único por usuario en `demo_orders`; libro solo-insertar |
| T-D5 | Dos entregas concurrentes reciben el mismo código | Bloqueo advisory + `UPDATE … WHERE state='available'`; prueba con 50 procesos reales |
| T-D6 | Filtración de códigos en claro de `demo_pool` | Cifrado con clave de app, nunca en bitácora, logs ni respuestas del panel; solo lotes demo |
| T-D7 | Un operador/auditor enciende o configura la muestra | Permiso `demo.manage` solo superadmin; 403 comprobado; CSRF y 2FA como el resto del panel |
| T-D8 | Configuración maliciosa (premios gigantes, miles de códigos) satura la siembra | Límites duros en `DemoConfig`, validación en servidor, una sola corrida activa |
| T-D9 | Un usuario demo canjea códigos de otro usuario demo | Cada código se asigna a un `user_id` y el canje de un código demo exige coincidir con `assigned_to` |
| T-D10 | La web demo oculta que la muestra no es una campaña real | Indicador visible «Muestra/Demo» en la app mientras el modo esté activo |

Revisión obligatoria en el Senior Review: T-D1, T-D5, T-D9 (los tres tocan canje o sorteo).

# Parte C — Desglose propuesto para el PM
1. **M1** Migración, modelos, `DemoConfig` + validador, `DemoSwitch`, permiso `demo.manage` y endpoints del panel (backend).
2. **M2** `DemoSeeder` (extraído de `SeedDemo`), corridas asíncronas, `gamification:demo:process`, reinicio (backend/devops).
3. **M3** Superficie pública: `status`, `session`, `purchase`, `DemoPoolDraw`, libro de saldo, gancho de canje, retiro de `GAMIF_DEMO_MODE` (backend).
4. **M4** Pantalla «Muestra» del panel (frontend Angular) + unitarias + E2E del panel.
5. **M5** `DemoApi` + `DemoJourney` + modo en `standalone` + textos (frontend Flutter).
6. **M6** Despliegue (`demo-up.sh`, compose, docs, CI), recorrido E2E completo, mutación y QA, informe `docs/qa-spec-006.md`.
Dependencias: M1 → M2 → M3; M4 tras M1; M5 tras M3; M6 al final. Senior Review al cierre de M3 y de M5.
