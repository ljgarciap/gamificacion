# SPEC-006 — Muestra real (modo demo) activada y configurada desde el panel

Estado: **BORRADOR** (pendiente de aprobación de Luis). Rol: Analista. Fecha: 2026-11-07.

## 1. Objetivo
Poder hacer una **muestra realista** del flujo completo sin host real: un usuario simulado entra, hace una compra en línea, se le carga saldo según reglas configurables y la app le entrega **un código sorteado al azar** de un lote cuyo resultado ya está predefinido. Todo se configura y se activa **solo desde el panel de administración**.

## 2. Decisiones de Luis (2026-11-07)
- D21 Activación: **interruptor simple** (superadmin + 2FA, sin control dual). Queda en bitácora con quién y cuándo.
- D22 Escenario: **completo, con pasos visibles** en la app (acceso → compra → saldo cargado → juego).
- D23 Azar: **pool del lote + mezcla configurable**; sorteo con CSPRNG entre códigos sin canjear; resultado predefinido desde la siembra (stock exacto, SPEC-002).
- D24 Entorno: **demo aislada, sin campañas reales** en el mismo servidor.

## 3. Alcance
### 3.1 Activación (reemplaza `GAMIF_DEMO_MODE`)
- Parámetro `demo.enabled` en `Settings`/`SettingsRegistry` (permiso `demo.manage`, solo superadmin). Por defecto **apagado**.
- Las rutas `/v1/demo/*` existen siempre pero responden 404 con el modo apagado (hoy no existen según la variable).
- Salvaguarda de entorno: el interruptor solo puede encenderse si el servidor declara `GAMIF_DEMO_ALLOWED=true` (variable de infraestructura, no de negocio) y no hay campañas no-demo activas. Así una producción real no puede convertirse en emisor de tokens abierto por un clic.
- Caducidad opcional configurable (horas) y apagado inmediato desde el panel.

### 3.2 Escenario configurable (pantalla «Muestra» del panel)
- **Usuario simulado:** nombre, id de host demo, saldo inicial.
- **Catálogo y compra:** productos con nombre y precio (COP); la app permite «comprar» uno o más.
- **Regla de carga de saldo:** por ejemplo, 1 giro/ficha por cada $X de compra, con tope y mínimo (todo paramétrico, nada hardcodeado).
- **Campaña/juego:** a qué campaña y lote demo se asocia.
- Vista previa del recorrido y botón «Reiniciar muestra» (nuevo usuario, saldo y pool).

### 3.3 Pool y reparto de resultados
- El panel define la **mezcla**: cuántos códigos con premio por monto y cuántos sin premio. «Sembrar» genera un lote demo con el flujo ya existente (compromiso, baliza, stock exacto).
- Al terminar la compra, el servidor **sortea con el CSPRNG** un código sin canjear del pool y lo entrega al usuario simulado; el cliente no elige.
- El servidor sigue decidiendo el resultado; el cliente solo anima. Idempotencia por `Idempotency-Key`.
- La lista de códigos de prueba de la app deja de mostrarse en modo muestra.

### 3.4 App
- Nuevo flujo de pasos visibles en la app independiente (sin tocar el núcleo ni los juegos): acceso simulado, tienda con compra, confirmación de saldo, entrega del código y juego.
- Textos es/en en `GamificationStrings`; moneda COP.

## 4. Fuera de alcance
- Host real, pagos reales, cambios al motor de sorteo o a los vectores.
- Modo con oponente IA (pendiente de D3).

## 5. Seguridad (revisar con Cybersecurity antes de implementar)
- El emisor de tokens demo es abierto: mantener límite por IP, compuerta de Caddy y `GAMIF_DEMO_ALLOWED`.
- Tokens demo con usuario sintético y alcance solo sobre lotes marcados `is_demo`; nunca válidos para lotes reales.
- Cambios del interruptor y de la configuración quedan en la bitácora de administración (sin secretos).

## 6. Criterios de aceptación
- CA-1 Con el modo apagado `/v1/demo/*` da 404 y la app no ofrece el flujo.
- CA-2 Solo un superadmin con 2FA puede encender/apagar; un operador o auditor recibe 403.
- CA-3 No se puede encender sin `GAMIF_DEMO_ALLOWED=true` ni con campañas reales activas.
- CA-4 Cambiar precio, regla de carga o mezcla en el panel cambia el recorrido sin desplegar.
- CA-5 El saldo cargado coincide con la regla configurada para cada compra.
- CA-6 El código entregado se sortea con CSPRNG entre los no canjeados; una prueba estadística y una de unicidad lo comprueban; nunca se entrega uno ya canjeado.
- CA-7 El resultado del código coincide con el premio predefinido en la siembra y la exportación de auditoría del lote demo verifica con el verificador independiente.
- CA-8 Con 50 entregas concurrentes no se duplica ningún código.
- CA-9 Recorrido E2E con Playwright: panel (configura y enciende) → app (acceso, compra, saldo, juego) → resultado.
- CA-10 «Reiniciar muestra» deja el estado limpio y repetible.

## 7. Decisiones abiertas
- ¿La caducidad automática del modo demo es obligatoria o solo opcional? (propuesta: opcional, 24 h por defecto).
- ¿Un usuario simulado por navegador (anónimo) o uno único compartido? (propuesta: uno por sesión de navegador).
- ¿Cuántos productos hacen falta para la muestra? (propuesta: hasta 5, editables).

## 8. Orden propuesto
Arquitecto (diseño + migración de `demo.enabled` a Settings) → Cybersecurity (§5) → PM desglosa: backend (Settings, rutas, sorteo, siembra), panel (pantalla «Muestra»), app (flujo de pasos), QA E2E.
