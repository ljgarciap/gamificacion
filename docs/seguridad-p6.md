# SPEC-003 · P6 — Revisión de seguridad del giro extra (antes de implementar)

Alcance: `docs/diseno-spec-003.md` §11, RF-55…RF-59, amenazas T25–T29. Es el único bloque que toca el motor ya auditado. Esta revisión se hizo leyendo `app/Engine/*`, `BatchLifecycle`, `RedeemService`, `PrizeVault` y el esquema de `redemptions`. **Es del mismo hilo que escribió el diseño: sesgo declarado.** Lo conveniente es que el Senior Review posterior a la implementación sea ciego a esto.

## Veredicto
El diseño de §11 es viable pero **no debe implementarse tal cual**: tiene 3 defectos de diseño (H1–H3) y 6 huecos de especificación (H4–H9). Todos se resuelven antes de escribir código; ninguno cambia el objetivo.

## Hallazgos

### H1 · ALTO — `FOR UPDATE SKIP LOCKED` contradice el "orden determinista" (T26/T28)
Dos giros simultáneos: A toma la casilla 0 (bloqueada), B salta a la 1 y confirma. Si A revierte, la 0 queda libre y la 1 consumida: **hay un hueco y el orden ya no es "la k-ésima redención recibe la casilla k"**. Un tercero no puede verificar el orden y el resultado depende de la carrera.
**Decisión:** contador por lote con fila bloqueada (`batch_reserve(batch_id, next_idx)` `FOR UPDATE`), no `SKIP LOCKED`. Consumo estrictamente secuencial y sin huecos. El costo es serializar solo los giros hijos *del mismo lote* (la bitácora ya serializa todo con su candado global, así que no empeora). Un trigger exige `idx = consumidas + 1`: el orden pasa a ser invariante de la base, no de PHP.
Orden de bloqueos fijo (evita interbloqueos): lote `FOR SHARE` → redención padre `FOR UPDATE` → contador `FOR UPDATE` → bitácora (al final, como hoy).

### H2 · ALTO — Contexto de cifrado compartido entre código y reserva
`prizeAad(batchId, idx)`: si la reserva reusa `idx` 0…R−1, la casilla de reserva *i* y el código *i* se cifran con el mismo contexto. Quien pueda escribir en la base (el modelo de amenaza de `codes_guard`) **intercambia sus textos cifrados y AEAD los acepta**.
**Decisión:** la reserva usa el índice de baraja global `N + j` y el AAD lleva un tipo (`code|reserve`). Prueba: intercambiar `prize_enc` entre un código y una casilla debe fallar al descifrar.

### H3 · ALTO — Un `commit` v2 ambiguo permite dos compromisos válidos para el mismo lote
Si `R = 0` pudiera comprometerse también con `gamif/v2/commit`, un lote tendría dos compromisos válidos (maleabilidad) y un verificador podría aceptar el "equivocado".
**Decisión:** `version` se guarda en el lote y en la exportación; **`R = 0 ⇒ v1 obligatorio; R > 0 ⇒ v2 obligatorio`**, y el verificador rechaza cualquier otra combinación. El compromiso v2 liga además `R` explícito (`be32`) y el hash del pool lleva `N + R` posiciones; `N` ya va en el `codes_digest`. Se usan etiquetas v2 también para el mezclado (`gamif/v2/mix`) cuando `R > 0`, así un valor de un contexto no vale en otro. Con `R = 0` no se toca una sola ruta v1 (prueba: SHA-256 de la sección v1 de `vectors.json` fijado).

### H4 · MEDIO — El derecho a girar no puede salir de la campaña mutable (T27)
`extraSpins` hoy sale de `meta.extra_spins` de la definición del premio. Si el giro hijo lo leyera de ahí, editar la campaña después de canjear cambiaría cuántos giros tiene el jugador.
**Decisión:** al redimir el padre se guarda `spins_granted` en la fila (write-once). Cada hijo lleva `parent_redemption_id` y `spin_no` con `UNIQUE (parent, spin_no)` y CHECK `spin_no <= spins_granted` (trigger, porque cruza filas). El padre se bloquea `FOR UPDATE` al girar.

### H5 · MEDIO — Reserva insuficiente: advertir no basta (T29)
Stock exacto es la promesa central del sistema. **Decisión:** al comprometer, error duro si `Σ (cantidad × extra_spins)` de los premios giro extra de todo el mazo `> R` (condición suficiente: nunca se agota). Panel: mostrar también `R` sobrante (casillas que quizá nunca se entreguen) para que el operador lo decida.

### H6 · MEDIO — ¿Qué pasa con giros pendientes al cerrar el lote? *(decisión de negocio)*
Si se pudiera girar después del cierre/revelación, el jugador conocería su premio de antemano y la exportación no sería definitiva. **Propuesta técnica:** los giros solo se permiten con el lote `active` y dentro de `redeemable_until`, igual que canjear; los pendientes **caducan al cerrar**. El panel debe mostrar los giros pendientes antes de cerrar. *Luis decide si hay gracia; por defecto: caducan.*

### H7 · MEDIO — Idempotencia y respuesta perdida
Si se pierde la respuesta y el cliente reintenta con otra `Idempotency-Key`, consume otra casilla. **Decisión:** el contrato indica que la clave es estable por giro (`parentId + spin_no`); misma clave ⇒ misma respuesta guardada (`json`, como hoy). Un giro con clave nueva es un giro nuevo y se documenta.

### H8 · MEDIO — `redemptions.code_id` es `NOT NULL UNIQUE`
La redención hija no tiene código. Cambio de esquema en una tabla con triggers: `code_id` nullable + `reserve_idx` + CHECK "exactamente uno de los dos" + `redemptions_guard` revisado (hoy valida contra `codes`). Es la parte más delicada de la migración: se verifica por mutación.

### H9 · BAJO — Propiedad del padre sin oráculo
Un padre de otro usuario, uno inexistente y uno sin giros pendientes deben responder **igual** donde corresponda (404 para ajeno/inexistente), con el mismo limitador que canjear y fallos anotados fuera de la transacción.

## Verificar en la implementación (no son hallazgos, son comprobaciones)
- El aviso de acreditación al host: un premio `extraSpin` hoy entra a `prize_outbox` (solo se excluye `none`). Confirmar con el contrato del host si un giro extra se acredita o no; si no, excluirlo.
- Bitácora: evento `spin.redeemed` con padre, `spin_no`, `reserve_idx` y premio, para que el verificador compruebe el orden y `hijos(padre) ≤ spins_granted`.
- Exportación y verificador: incluir las `R` casillas, verificar la asignación sobre `N + R`, que cada hijo recibió exactamente el premio de su casilla y que el orden de casillas coincide con el de la cadena.

## Pruebas y mutaciones obligatorias
| Control | Mutación que debe hacer fallar una prueba |
|---|---|
| `R = 0` sin cambios | tocar cualquier ruta v1 → falla la huella de la sección v1 de los vectores |
| v2 liga `R` | quitar `R` del compromiso v2 |
| Baraja sobre `N + R` | barajar solo `N` |
| Orden secuencial (H1) | quitar el trigger de secuencia; usar `SKIP LOCKED` |
| AAD con tipo (H2) | volver a `idx` sin tipo |
| Versión canónica (H3) | aceptar v2 con `R = 0` en el verificador |
| Derecho del padre (H4) | ignorar `spins_granted` |
| Reserva suficiente (H5) | desactivar la validación al comprometer |
| Propiedad del padre | omitir la comprobación de usuario |
| Concurrencia (CA-39) | `K` procesos reales: exactamente `K` casillas distintas y consecutivas, nunca más de `R` |
| Verificador | alterar un registro de casilla → detectado; vectores cruzados PHP↔Python para `R > 0` |

## Decisiones de Luis (2026-10-21)
1. **H6:** los giros pendientes **caducan al cerrar** el lote; solo se gira con el lote `active` y dentro de `redeemable_until`.
2. **H5:** **error duro** al comprometer si la reserva no cubre el peor caso (modifica el RF-57, que decía "advertir").

Todo lo demás es técnico dentro del diseño y lo resuelvo yo. Orden de implementación propuesto: P6a motor + compromiso v2 + vectores + verificador (sin tocar la base) → P6b esquema y triggers → P6c servicio y endpoint `/v1/spins` con concurrencia real → P6d exportación/panel/cliente Flutter → Senior Review independiente.
