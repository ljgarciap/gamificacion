# Diseño de SPEC-007 — Planes de premio por monto

Estado: **PROPUESTO** para aprobación de Luis. Rol: Arquitecto (con la lente de Cybersecurity en §6). Fecha: 2026-11-17. Spec: `spec-007-planes-por-monto.md` (APROBADA). Parte de `diseno-spec-006.md` y `diseno-spec-006a.md`.

## 1. Idea central (una frase)
Un **plan** es solo una etiqueta sobre un conjunto de códigos del pool: se agrega una columna `plan` a `demo_pool`, el sorteo filtra por ella y la compra (o el ingreso) decide **qué plan** toca. El motor de sorteo, el compromiso, la baliza y el verificador **no se tocan**: cada plan sale como lotes comprometidos normales, con stock exacto.

## 2. Decisiones técnicas (dentro del diseño aprobado; ninguna es de negocio)
| # | Decisión | Por qué |
|---|---|---|
| A1 | Una **campaña + lote por (plan, juego)**, igual que hoy por juego. La mezcla de cada plan tiene la misma forma que `mix` (juego → premios + sin premio) | reutiliza `DemoSeeder::build`, `DemoGameTemplates` y las validaciones sin código nuevo de sorteo |
| A2 | El sorteo elige **al azar entre los códigos disponibles del plan** (CSPRNG, mismo bloqueo consultivo) | misma garantía que hoy; planes independientes |
| A3 | El **plan se calcula solo en el servidor** con los precios del catálogo guardado; la petición sigue siendo solo ids y cantidades | T-D21 |
| A4 | Modo en la configuración: `mode: "steps" \| "plans"`. Sin `mode` = `steps` (CA-79). En `plans`, `mix` y `credit.{minPurchase, codeStepAmount, maxCodesPerPurchase}` no se usan | compatibilidad total con muestras ya configuradas |
| A5 | Turnos fuera de una compra (bienvenida, reactivación) se registran en una tabla nueva `demo_grants`; un código asignado apunta a una orden **o** a un grant | trazabilidad sin inventar órdenes falsas |
| A6 | La «marca de actividad» de la cuenta (`activity_at`) = último momento de compra, reactivación otorgada o creación. Reactivación si `ahora − activity_at ≥ días` | una sola columna, sin ventanas separadas que desincronizar |
| A7 | Si el pool de Bienvenida está agotado al ingresar, **el ingreso funciona igual** y no hay turno (se anota en la bitácora); en una **compra** sin códigos del plan sigue el 409 actual, todo revertido | un ingreso nunca debe fallar por falta de premios; una compra no debe cobrar sin entregar |

## 3. Forma de la configuración (jsonb, validada por `DemoConfig::validate`)
```
mode: "plans"
user, credit (solo balancePercent y maxCodesPerSession se usan), ttlHours, accounts — sin cambios
products: [{ id, name, price, participates: bool }]        // participates omitido = true
triggers: { reactivationDays: int, turnsPerPurchase: int, welcomeOnFirstLogin: bool }
plans: [
  { id: "welcome", kind: "event",  name, maxMoneyPrize, mix },
  { id: "bronce",  kind: "amount", name, minAmount, maxAmount|null, maxMoneyPrize, mix },
  ...
]
```
Validaciones nuevas (todas con mensaje claro y por campo, como las actuales):
- `plans`: exactamente **un** plan `event` con id `welcome`; de 1 a `MAX_AMOUNT_PLANS` (5) planes `amount`; ids únicos con el patrón de ids existente; nombre de 1 a 40 caracteres.
- Rangos de los planes `amount`, ordenados por `minAmount`: el primero ≥ 1, cada siguiente `minAmount = anterior.maxAmount + 1`, el último `maxAmount = null`. Eso es **cobertura sin huecos ni solapes** (CA-75) y deja por debajo del primer mínimo «sin turno» (D32).
- Cada premio en dinero ≤ `maxMoneyPrize` de su plan; `maxMoneyPrize` ≤ `MAX_AMOUNT`. Cada plan con al menos un código; el pool total de todos los planes ≤ `MAX_POOL` (500, techo vigente; con los valores provisionales son 400).
- `reactivationDays` 1…`MAX_REACTIVATION_DAYS` (60); `turnsPerPurchase` 1…`MAX_CODES_PER_PURCHASE` (5, techo vigente).
- `participates` booleano estricto (no «truthy»).
- Techos nuevos como constantes de `DemoConfig` junto a los demás: `MAX_AMOUNT_PLANS`, `MAX_REACTIVATION_DAYS`, `MAX_PLAN_NAME`.
- **Probabilidad = premios / (premios + sin premio)** se calcula en un solo lugar (`DemoPlans::probability`) y la usa el panel; **no se guarda ni se envía a la app**.

## 4. Base de datos (una migración reversible, SQL explícito como el resto)
```
ALTER TABLE demo_pool ADD COLUMN plan varchar(32);                       -- NULL = modo «steps»
ALTER TABLE demo_pool ADD COLUMN grant_id bigint REFERENCES demo_grants(id);
CREATE INDEX demo_pool_available_plan_idx ON demo_pool (plan, id) WHERE state = 'available';
-- el trigger de inmutabilidad existente se amplía: plan y batch_id no cambian nunca

CREATE TABLE demo_grants (
  id bigserial PRIMARY KEY,
  user_id varchar(160) NOT NULL,
  kind varchar(12) NOT NULL CHECK (kind IN ('welcome', 'reactivation')),
  created_at timestamptz(6) NOT NULL DEFAULT clock_timestamp()
);
CREATE UNIQUE INDEX demo_grants_one_welcome_idx ON demo_grants (user_id) WHERE kind = 'welcome';
-- append-only (mismo trigger que el libro de saldo)

ALTER TABLE demo_accounts ADD COLUMN activity_at timestamptz(6) NOT NULL DEFAULT now();
CHECK sobre demo_pool: un código 'assigned' tiene order_id o grant_id (no ambos)
```
- La unicidad de «un solo turno de bienvenida por cuenta» la impone **la base** (índice único parcial), no solo el PHP.
- La reactivación no necesita índice único: se protege con el bloqueo de la fila de la cuenta + la marca `activity_at` (A6, T-D25).
- `demo_pool.plan` de filas existentes queda `NULL` → siguen funcionando en modo `steps`.

## 5. Flujo en el servidor
**Clases nuevas** en `app/Domain/Demo/`: `DemoPlans` (elige plan por monto, probabilidad, validación de rangos) y `DemoGrants` (turnos de bienvenida/reactivación y simulación). Cambios acotados en `DemoConfig`, `DemoSeeder`, `DemoPoolDraw`, `DemoShop`, `DemoAccounts`.

1. **Sembrar** (`DemoSeeder::seed`): en modo `plans` recorre plan × juego, crea campaña `is_demo` y lote con la mezcla del plan (nombre «Muestra <plan> <juego>»), y `fillPool` guarda `plan`. Las 3 fases (crear, activar esperando la baliza, llenar) no cambian; los lotes de todos los planes comparten la espera de baliza.
2. **Compra** (`DemoShop::purchaseLocked`), en modo `plans`:
   - total = suma de **todas** las líneas (saldo simulado según D31); `eligible` = suma solo de líneas con `participates`.
   - `plan = DemoPlans::forAmount(config, eligible)`; si no hay plan (monto bajo el primer mínimo o `eligible = 0`) → 0 turnos.
   - turnos = `min(turnsPerPurchase, room)` donde `room` sigue siendo `maxCodesPerSession − ya entregados` (mismo bloqueo por usuario, sin carreras).
   - `draw(userId, orderId, n, plan)`; `DemoPoolExhausted` → 409 `demoPoolExhausted` con la transacción revertida (CA-77).
   - actualiza `activity_at` de la cuenta (la compra reinicia la espera de reactivación) **dentro de la misma transacción**.
   - se quita el 422 por `minPurchase` en este modo: cualquier compra con total ≥ 1 se procesa (puede dar 0 turnos).
3. **Ingreso** (`DemoShop::login` → `DemoGrants::onLogin`), en una transacción corta con la fila de la cuenta `FOR UPDATE`:
   - cuenta nueva y `welcomeOnFirstLogin` → grant `welcome`.
   - cuenta existente con `now − activity_at ≥ reactivationDays` → grant `reactivation` y `activity_at = now`.
   - cada grant: sortea 1 código del plan `welcome`; si no hay (A7), anota `demo.grant_skipped` y sigue.
   - la respuesta de `auth/verify` trae `grant: { plan: "<nombre>", kind }` **solo cuando se otorgó un turno** (campo aditivo, ausente si no; renovar el token con `session` nunca otorga).
4. **Simulación** (`DemoGrants::simulateInactivity`): `activity_at = LEAST(activity_at, now − reactivationDays)` — solo mueve **hacia atrás**; bitácora `demo.inactivity_simulated` con quién la pidió y de qué cuenta.
5. **Cuenta** (`DemoShop::account`) y respuesta de compra: cada código trae `plan` (el **nombre visible** del plan, tomado de la configuración; la app no conoce ids ni umbrales de planes). Aditivo.

## 6. Superficie de seguridad (revisión con la lente de Cybersecurity)
| Id | Amenaza | Mitigación en el diseño |
|---|---|---|
| T-D21 | El cliente elige su plan (manda plan, monto o un producto inexistente) | El plan sale solo del catálogo guardado y de `quantity` validada (≤ 10); campos extra del cuerpo se ignoran y hay prueba que los manda |
| T-D22 | Errores en las fronteras o desbordes aritméticos | Enteros estrictos; rangos contiguos validados al guardar; pruebas en cada frontera (999, 1.000, 10.000, 10.001, 50.000, 50.001); total máximo acotado (5 productos × 10 × `MAX_AMOUNT`) |
| T-D23 | Subir de plan mezclando productos excluidos | Solo lo que participa suma para elegir el plan; prueba con compra mixta |
| T-D24 | Farmear bienvenida creando cuentas | Un turno por cuenta lo impone la base; cuentas exigen correo verificado y están limitadas por IP/correo/tope global (SPEC-006A); el pool es finito. **Riesgo residual aceptado en una demo** (correos desechables): lo acota `maxAccounts` y el stock de Bienvenida |
| T-D25 | Carrera ingreso/ingreso o ingreso/compra para duplicar la reactivación | Fila de cuenta `FOR UPDATE` en el ingreso; la compra actualiza `activity_at` bajo el mismo bloqueo por usuario; prueba con procesos reales (`Parallel`) |
| T-D26 | Vaciar un plan alto con compras simuladas (por ejemplo Oro) | Tope por cuenta (`maxCodesPerSession`) y por cantidad de cuentas; el panel muestra el stock por plan para reponer; el 409 no afecta a otros planes. **Riesgo residual aceptado** (es una muestra) |
| T-D27 | Mal uso del botón «Simular una semana sin comprar» | Permiso `demo.manage` (ya existente), CSRF, solo hacia atrás, solo cuentas existentes, bitácora; la ruta cae bajo la prueba de arquitectura de permisos |
| T-D28 | Fuga de información (mezcla, stock, probabilidad) a la app | La app solo recibe nombre del plan; probabilidades, mezcla y stock son del panel. Los umbrales de monto son públicos por naturaleza del charter |

**Senior Review obligatorio:** T-D21, T-D22 y T-D25 (tocan sorteo, compra y concurrencia). **Verificación por mutación** de cada bloque con lógica o seguridad (regla vigente).

## 7. API
- **App (`docs/openapi.yaml`, cambios aditivos):** `grant` en la respuesta de `auth/verify` y `session`; `plan` en cada código de `account` y de `purchase`. Se agregan ejemplos y el `contract_test.dart` los exige.
- **Panel (`docs/openapi-admin.yaml`):** `GET demo/status` suma `poolByPlan: [{ plan, name, available, assigned, probability }]`; la configuración acepta `mode`, `plans`, `triggers`, `products[].participates`; ruta nueva `POST demo/accounts/{id}/simulate-inactivity`. `AdminOpenApiContractTest` la compara con las rutas reales.

## 8. Panel y app (reutilizan patrones existentes; no hay spec de UX aparte)
- **Panel «Muestra»:** selector de modo; bloque **Disparadores** (3 campos); bloque **Planes** como las tarjetas por juego de «Mezcla de premios», una por plan, con rango (los planes `amount` encadenan solos el mínimo del siguiente al editar el máximo) y la **probabilidad calculada** en lectura; columna «Participa» en el catálogo; en «Cuentas» el botón **Simular una semana sin comprar** (pide confirmación, como «Borrar cuenta»). Estado con el stock por plan.
- **App:** cada código de «Tus códigos» dice el nombre del plan (texto, no solo color); si el ingreso trae `grant`, un aviso breve («Tienes una ruleta de bienvenida»); textos es/en en `GamificationStrings`. Nada hardcodeado: el nombre del plan viene del servidor.

## 9. Pruebas
- **Backend:** unidad de `DemoPlans` (rangos, fronteras, probabilidad, validación); feature de compra por plan (CA-70, 71, 74, 76, 77, 79); de ingreso (CA-72, 73); concurrencia real (CA-78 y T-D25); arquitectura/contrato (rutas y OpenAPI). Meta: sin romper los 1073 existentes.
- **Mutaciones previstas** (cada una debe hacer fallar una prueba): frontera `<=` por `<`; usar el total en vez del que participa; quitar el filtro `plan` del sorteo; quitar el índice único de bienvenida; quitar el `FOR UPDATE` del ingreso; permitir mover `activity_at` hacia adelante; no revertir en 409.
- **Cliente:** `demo_api_test`, `contract_test`, journey del SDK y widget test con los campos nuevos.
- **E2E (CA-80):** `admin-panel/e2e/muestra-planes.spec.ts` con el patrón de `muestra-recorrido.spec.ts` (Mailpit, drand real, verificador independiente). Duración esperada > 3 min por la espera del correo y de la baliza.

## 10. Hitos (a confirmar por el PM)
- **M11** — migración, `DemoConfig` (modo/planes/validación), `DemoPlans`, sembrado por plan, sorteo por plan, compra por plan, productos que no participan. *Mutación.*
- **M12** — `DemoGrants`: bienvenida, reactivación, simulación, `activity_at`, campos aditivos de API y OpenAPI. *Mutación y concurrencia real.*
- **M13** — panel: modo, Disparadores, Planes, «Participa», stock por plan, botón de simulación.
- **M14** — app/SDK: `plan` en los códigos, aviso de `grant`, textos es/en, pruebas de contrato.
- **M15** — despliegue (`demo-up.sh` no cambia; la migración corre sola), E2E CA-80, `qa-spec-007.md` y documentación (`CLAUDE.md`, `despliegue-demo.md`).

## 11. Riesgos y notas
- **Pool máximo de 500:** con los valores provisionales (4 planes × 100) alcanza; si el negocio quiere más de 500 se sube el techo `MAX_POOL`, con revisión de tiempos de siembra (cada lote espera la baliza).
- **Cambiar la configuración con la muestra ya sembrada** no cambia el pool existente: se aplica en la siguiente siembra (comportamiento actual). Al **encender** en modo `plans`, el sistema exige que cada plan tenga al menos un código disponible.
- **No se toca** el verificador, el motor, ni las rutas de canje; `/v1/demo/purchase` mantiene su contrato de entrada.
- **Fuera de este diseño** (spec §4): correos a ganadores, Kupi real, push/campanita, pop-up del host y control por fechas de campaña.
