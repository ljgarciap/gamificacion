# QA de SPEC-007 (planes de premio por monto en la muestra) — 2026-11-17

Spec: `spec-007-planes-por-monto.md` (APROBADA; valores ⚑ provisionales) · Diseño: `diseno-spec-007.md` · Amplía `qa-spec-006.md` y `qa-spec-006a.md`.

## Suites
| Suite | Resultado |
|---|---|
| Backend PHP | **1150 pasan**, 8 omitidas (mediciones y fixtures que exigen una variable), 0 fallan |
| Planes, unitarias | `DemoPlansTest` 26 (validación de rangos/topes/disparadores, cada frontera de monto, probabilidad exacta) |
| Planes, compra y siembra | `DemoPlansShopTest` 18 (CA-70, 71, 74, 76, 77, 79; T-D21, T-D22, T-D23) |
| Turnos de bienvenida y reactivación | `DemoGrantsTest` 19 (CA-72, 73; pool agotado, garantías de la base) · `DemoPlansAdminTest` 7 (permisos, simulación, existencias por plan) |
| Concurrencia real (procesos de PostgreSQL) | 30 ingresos simultáneos de una cuenta nueva → UN turno de bienvenida; 30 de una cuenta inactiva → UNA reactivación; ingresos y compras a la vez → nunca duplica |
| Flutter | core 38 · http 138 · sdk 114 · standalone 12 · `dart analyze` limpio |
| Panel Angular (unitarias) | 38 de 38 (16 nuevas del modo por planes) |
| E2E del panel | `muestra-planes.spec.ts` 6 de 6 (incluye accesibilidad WCAG AA) y `muestra.spec.ts` 9 de 9 sin cambios |
| **E2E del recorrido por planes** (`e2e/muestra-planes-recorrido.spec.ts`) | **1 de 1** (≈3 min) con drand REAL, Mailpit y el verificador independiente sobre los **4 lotes** (uno por plan) |

## Recorrido CA-80 en navegador real
1. El panel guarda los planes, siembra (16 códigos: 4 por plan) y muestra la probabilidad de cada uno (100 %, 25 %, 25 %, 50 %) y las existencias por plan.
2. Una persona entra con su correo (código leído de Mailpit): la app anuncia el **turno de bienvenida** y el código dice «Plan Bienvenida»; lo juega (siempre gana uno de los premios de ese plan).
3. Compra en el rango **Plata**: la vista previa promete una ruleta sin contar códigos, el saldo es el 10 % y el código llega como «Plan Plata».
4. **Pasa Plata** (no participa): la vista previa y la entrega dicen que no da ruleta; igual se acredita el 10 %.
5. Por la API con el token de la cuenta: **Bronce** y **Oro** entregan código de SU plan; el saldo de la API coincide con el cálculo; el panel muestra una existencia menos en cada plan.
6. **Simular una semana sin comprar** desde el panel; la persona sale, espera el enfriamiento del correo (62 s) y vuelve a entrar: llega el **turno de reactivación** (dos códigos de Bienvenida en la cuenta, uno jugado).
7. Cero errores de consola de la app. Reinicia borrando cuentas (pool nuevo de 16, 0 cuentas) y el verificador valida los cuatro lotes del primer sembrado.

## Verificación por mutación
- **M11 (backend, 11):** `>=`/`<=` de las fronteras, ignorar «participa», sorteo sin filtro de plan (fila y conteo), plan por el total y no por lo que participa, exigir compra mínima en planes, encender sin revisar planes, rangos sin contigüidad, premio sobre el tope, último plan con tope → **todas detectadas**.
- **M12 (turnos, 12):** sin `FOR UPDATE` en el ingreso (la atrapa la prueba de procesos reales), reactivación que no mueve la marca, compra que no reinicia la espera, simulación hacia adelante, bienvenida sin respetar el interruptor, reactivación para cuentas nuevas, umbral de días ignorado, ingreso que se rompe con el pool agotado, turno de otro plan, simulación sin bitácora y **renovar el token otorga turno** (escapó al primer intento: la prueba usaba una cuenta no inactiva; se reforzó) → **todas detectadas**.
- **M13 (panel, 11):** fronteras, probabilidad, encadenado de rangos, último plan sin tope, tope en dinero, contigüidad, modo anterior sin «participa», productos excluidos, pool de planes → **todas detectadas**.
- **M14 (app, 12 + 4):** vista previa por total en vez de lo elegible, mínimo en planes, «participa» ignorado, lectura del turno (reactivación como bienvenida, grant ilegible, plan de la compra), aviso que no se limpia al jugar o comprar y mensaje de bienvenida para ambos → **todas detectadas** tras reforzar dos pruebas; una limpieza al salir resultó código muerto (el ingreso siguiente la reasigna) y se quitó.

## Hallazgos del QA y cómo se resolvieron
- **`now()` de PostgreSQL es la hora de INICIO de la transacción** (las pruebas viven en una): la reactivación comparaba contra `now()` y nunca se cumplía en las pruebas. Se usa `clock_timestamp()` (también más exacto en producción).
- **El panel devolvía 500** al simular en el modo anterior: la excepción de estado no se convertía en 409. Corregido y probado.
- **Flutter web expone la fila con botón como un grupo accesible** (no como texto): el E2E busca el grupo; no era un defecto de la app.
- **La compra sin ruleta deja la app en «Saldo cargado»**: el E2E vuelve a «Tus códigos» antes de salir.

## Decisiones de diseño que conviene que Luis conozca
- **El modo por planes NO es el predeterminado de una muestra nueva** (la spec lo proponía): el panel arranca en «Por pasos» y se cambia con un clic. Razón: dejar intactos los 9 E2E y los 1150 tests del modo anterior (CA-79). Si prefiere que arranque por planes, es un cambio pequeño.
- La **estructura de los planes es fija** (Bienvenida + los planes por monto que ya existan): el panel edita nombres, rangos, topes y mezclas, pero no agrega ni quita planes (el servidor sí admite hasta 5 por monto).
- **Cualquier compra (también de un producto que no participa) reinicia la espera de reactivación**; el charter dice «ninguna compra en la última semana».
- La carga de saldo simulada se mantiene sobre **toda** la compra (D31 propuesta).

## Límites conocidos (no bloqueantes)
- Valores ⚑ provisionales: probabilidades de Bronce y Plata, premios, cantidades, 7 días, 1 turno por compra, Kupi como «cupón» (D29–D34, de Luis).
- Fuera de alcance (spec §4): correos a ganadores, Kupi real y productos del portafolio, push y campanita, pop-up del host, control por fechas de campaña.
- Riesgos aceptados de una demo: farmear bienvenidas con correos desechables (lo acotan el tope de cuentas y el stock) y vaciar un plan alto con compras simuladas (el panel muestra el stock por plan).
- Sin desplegar: los commits siguen locales; un push a `master` despliega por CI y aplica las migraciones `demo_plans` y `demo_grants`.
