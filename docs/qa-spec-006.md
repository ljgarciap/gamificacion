# QA de SPEC-006 (muestra real desde el panel) — 2026-11-08

## Suites
| Suite | Resultado |
|---|---|
| Backend PHP | **1002 pasan**, 4 omitidas, 0 fallan (incluye sesión/compra/sorteo/barrera, interruptor, siembra, concurrencia real, contrato y adopción de campañas) |
| Concurrencia real (procesos de PostgreSQL) | 50 compras simultáneas sobre 40 códigos: exactamente 20 éxitos y 30 rechazos, ningún código repetido, las rechazadas no dejan nada; misma compra ×20 en paralelo: una orden y una carga; una persona con 20 claves distintas: exactamente el tope por sesión |
| Estadística del sorteo | chi-cuadrado sobre 3000 sorteos del CSPRNG (uniforme) |
| Flutter | core 38 · http 126 · sdk 95 · standalone 12 · juegos sin cambios; `flutter analyze` y `dart format` limpios |
| Panel Angular (unitarias) | 20 de 20 (8 nuevas de la muestra) |
| E2E del panel «Muestra» (`e2e/muestra.spec.ts`) | 5 de 5: permisos (operador sin entrada ni ruta, API 403), edición con totales y vista previa en vivo, validación cliente y servidor, interruptor y corridas con servidor simulado, accesibilidad WCAG AA |
| **E2E del recorrido completo** (`e2e/muestra-recorrido.spec.ts`) | **1 de 1** (≈1,7 min): ver abajo |

## Recorrido completo en navegador real (CA-9, CA-7, CA-10)
Backend en modo demostración con la baliza **drand real**, worker y la web de Flutter reconstruida (`docker/compose.muestra-e2e.yml`). La prueba:
1. Configura la muestra y pide un reinicio (pool nuevo de 20 códigos: 10 de ruleta, 10 de dados) por la API del panel; en el panel comprueba escenario, total del pool, «Sembrar» deshabilitado con códigos y «Encender» habilitado (CA-3/CA-4).
2. Con la muestra apagada `POST /v1/demo/session` responde 404 (CA-1). **Enciende desde el panel** con confirmación.
3. En la app (Flutter web, árbol de accesibilidad): aviso «MUESTRA · Datos simulados», acceso, tienda, 2 × $ 20.000 → vista previa «Recibirás $ 4.000 de saldo y 2 códigos», pago simulado, **saldo cargado = 4.000** (regla del panel, CA-5) y **saldo del servidor = 5.000** leído de la API, 2 códigos distintos.
4. Juega los dos códigos de punta a punta (ruleta o dados, sin teclearlos): el resultado es uno de los **predefinidos en la mezcla** (nada inventado por el cliente) y el saldo de la API coincide exactamente con el premio (CA-6/CA-7). Cero errores de página y de consola.
5. El panel muestra 2 entregados y 18 disponibles. **Apaga** desde el panel: la API vuelve a 404 y la app deja de ofrecer el recorrido.
6. **Reinicia** (CA-10): estado limpio (20 disponibles, 0 entregados, los 20 anteriores retirados).
7. Los dos lotes del primer pool (cerrados por el reinicio) se revelan, se exportan con `gamification:audit:export` y los **verifica el verificador independiente** de Python (`OK: el compromiso se reproduce, la baraja coincide con las asignaciones y los conteos son el stock`) (CA-7).

## Hallazgos del QA y cómo se resolvieron
- **Accesibilidad (corregido):** el chip «Encendida» tenía contraste 3,45:1 (blanco sobre verde); ahora fondo azul marino. Lo encontró el E2E de accesibilidad al coincidir con una muestra encendida; la prueba anterior solo lo veía apagado.
- **Campañas anteriores a `is_demo` (resuelto con comando):** las campañas de la siembra antigua no llevan la marca; con la barrera de canje los usuarios del emisor abierto no las canjean y el interruptor se niega a encender («hay campañas reales activas»). Se agregó `gamification:demo:adopt-legacy --yes` (solo con `GAMIF_DEMO_ALLOWED`, lista lo que marca, queda en la bitácora) y `deploy/demo-up.sh` lo corre una sola vez en el servidor de demostración (marca `deploy/.adopted`) y ajusta `demo.env` de entornos viejos (retira `GAMIF_DEMO_MODE`, agrega `GAMIF_DEMO_ALLOWED` y `GAMIF_BALANCE` solo si faltan). Es la salvaguarda funcionando: en una base con campañas reales el interruptor sigue negándose.
- **Pruebas frágiles (corregidas):** esperas por «última corrida» en vez de por id, estado inicial supuesto, aserciones sobre contadores acumulados. Ahora cada prueba parte de un estado conocido.
- **Datos de desarrollo:** los códigos de ruleta de la siembra antigua estaban agotados por QAs anteriores; se resembró la ruleta (`gamification:demo:seed --only=roulette`) y `juegos.spec` pasa.

## Verificación por mutación (M1–M6)
M1 18/18 · M2 25/25 (tras reforzar) · M3 28/28 (una es redundante bajo el candado: quitando ambas sí se detecta el doble reparto) · M4 3 (una era equivalente; una prueba reforzada) · M5 13 (una equivalente: descartar la clave tras compra exitosa, porque el carrito vacío ya la descarta; una prueba corregida) · **M6**: adopción sin exigir `--yes` (falla), sin exigir `GAMIF_DEMO_ALLOWED` (falla), y saldo cargado +1 → **el E2E del recorrido falla** (`Expected 4000, Received 4001`).

## Límites conocidos (no bloqueantes)
- El usuario simulado vive en memoria de la app: recargar la página abre uno nuevo (persistirlo exigiría almacenamiento web).
- La exportación de auditoría de un lote demo requiere revelarlo (el reinicio solo lo cierra): se hace desde el panel («Revelar el lote») o la API; el E2E lo hace por la API.
- El E2E del recorrido espera a drand real (≈1 min); no corre en el CI por defecto.
- Sin probar con un VPS real: el despliegue (`demo-up.sh`, compose, Caddy con compuerta) se ajustó y revisó, pero se comprobará al primer despliegue; `bash -n` del script limpio.
- Pendientes de negocio de Luis: D3 (registro legal / retención de auditoría), D5 (privacidad), D9 (primer superadmin).
