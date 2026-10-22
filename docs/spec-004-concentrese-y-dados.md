# SPEC-004 — Concéntrese (memoria de parejas) y Dados

Estado: **APROBADA por Luis (2026-10-20)** — D11 dobles pagan · D12 4×3 (propuesta) · D13 aviso de transparencia: sí
Origen: petición de Luis 2026-10-20: *"me interesa que al menos haya otros dos juegos en la demo, el Concéntrese (memoria de parejas) y dados"*. Ambos están en el roadmap de SPEC-001.
Prioridad: **antes de SPEC-003** (el panel y el host no dependen de esto, ni esto de ellos).
Depende de: SPEC-001 (contrato de juego, `GameModule`) y SPEC-002 (motor de premios, `Presentation`).

## 1. Objetivo
Agregar dos juegos jugables de punta a punta (backend, contrato, app Flutter y demo), con el mismo contrato que la ruleta y el raspa:
- **Concéntrese**: memoria de parejas. El jugador voltea cartas y busca parejas.
- **Dados**: el jugador tira dados y la combinación decide el resultado.

## 2. Principio que no cambia
**El servidor decide el resultado; el cliente solo lo representa.** El premio sale del sorteo verificable de SPEC-002 (pre-asignado, stock exacto, compromiso + baliza). Estos juegos solo deciden **cómo se muestra** un premio ya decidido, exactamente como la ruleta (segmento) y el raspa (cuadrícula).
Consecuencias:
- **El motor, la base de datos y el verificador no cambian.** Un juego nuevo es: una presentación en el backend, un paquete Flutter que implementa `GameModule`, su registro en la app y el enum del contrato (`CLAUDE.md`: "Nuevo juego = módulo, sin tocar el núcleo").
- La habilidad del jugador **no influye en el premio**. En Concéntrese solo cambia qué tan rápido termina. Se dice con claridad en la interfaz y la documentación; no se simula una dificultad que no existe.

## 3. Concéntrese

### 3.1 Mecánica
Una cuadrícula de cartas boca abajo (por defecto 4×3 = 12 cartas, 6 pares). El jugador voltea dos cartas por turno; si coinciden se quedan descubiertas, si no, se voltean de nuevo. Cada símbolo aparece exactamente dos veces.
- **Con premio:** una de las parejas es la **pareja premio** (dos cartas con el símbolo del premio: su ícono y su etiqueta, p. ej. "$ 10.000"). El resto son parejas de relleno.
- **Sin premio:** todas las parejas son de relleno; **no existe** pareja premio.
- El juego termina cuando se encuentra la pareja premio, o cuando se descubre todo el tablero (sin premio). El botón **"Revelar todo"** y el aviso "Tu premio ya está asegurado" funcionan como en el raspa: el premio ya está acreditado antes de empezar.
- Las parejas de relleno nunca muestran un símbolo igual al de un premio de la campaña (no puede parecer ganadora).

### 3.1b Rival simulado (opcional, `gameConfig.rival`)
Decisión de Luis (2026-10-22): una máquina alterna turnos con el jugador. **Es solo animación**: el premio ya está decidido y la habilidad no lo cambia; la interfaz lo dice («Juegas contra una máquina simulada…»).
- Se activa por campaña con `game_config.rival: true` (booleano; por defecto apagado, y apagado el servidor no manda el campo).
- La máquina juega después de cada turno del jugador y **nunca voltea la pareja premio** (así la halla el jugador); a veces acierta una pareja de relleno y a veces falla.
- Final: con premio, «¡Le ganaste a la máquina!»; sin premio, «Esta vez ganó la máquina». No hay marcador de parejas (no se afirma una victoria que el tablero pueda contradecir).
- No cambia el servidor más que aceptar y reenviar el campo; los resultados y las probabilidades son los mismos.

### 3.2 Lo que decide el servidor y lo que no
El servidor decide **la disposición** de las cartas (qué símbolo hay en cada posición) y la guarda con la redención (un reintento devuelve el mismo tablero). Se elige con el CSPRNG del sistema, nunca `rand`, igual que el resto de presentaciones. El cliente no baraja nada.

### 3.3 Contrato
`gameResult`: `{ "cards": ["star","moon","p10k", ...] }` — un id de símbolo por posición (longitud = 2 × pares). La pareja premio son las dos posiciones cuyo id es el id del premio.
`gameConfig`: `{ "pairs": 6, "columns": 4, "flipBackMs": 900 }`. La carta del premio se dibuja con el premio de la redención (como el raspa); los símbolos de relleno son los del cliente.

### 3.4 Configuración y validación (al comprometer el lote)
`game_config` de la campaña: `pairs` (3–8, def. 6), `columns` (def. 4), `symbols` (lista de relleno, def. la del raspa), `flipBackMs` (def. 900).
Se rechaza al comprometer (igual que la ruleta y el raspa) si: los símbolos de relleno no alcanzan para `pairs` parejas, repiten, o coinciden con el id de un premio; o `pairs` está fuera de rango.

## 4. Dados
**Aspecto (2026-10-22, a pedido de Luis: «que se vean más reales»; sin rival: el rival simulado es solo de Concéntrese):** los dados son cubos en 3D dibujados con `CustomPainter`: seis caras con su número (opuestas suman 7), esquinas redondeadas, luz y sombra, puntos hundidos. Al tirar giran en 3D frenando y rebotan con su sombra, y se detienen con la cara del servidor de frente; las vueltas son cosméticas y no tienen relación con el resultado.

### 4.1 Mecánica
El jugador pulsa "TIRAR" y se animan **2 dados** de 6 caras que se detienen en la combinación decidida por el servidor. La campaña define qué combinaciones corresponden a cada premio (como los segmentos de la ruleta): un premio puede tener varias combinaciones, y el servidor elige una al azar entre las suyas.
Ejemplo de campaña de demostración: los **dobles** pagan (1-1 … 6-6 → un premio cada uno o agrupados) y el resto de combinaciones es "Sin premio".

### 4.2 Contrato
`gameResult`: `{ "dice": [4, 4] }`.
`gameConfig`: `{ "diceCount": 2, "rollDurationMs": 1800, "legend": [ { "prize": {definición}, "rolls": [[a,b], ...] } ] }` (la leyenda permite mostrar la tabla de premios en pantalla; las combinaciones van ordenadas `a ≤ b`).

### 4.3 Configuración y validación (al comprometer el lote)
`game_config.rolls`: mapa premio → lista de combinaciones `[a,b]`, con `a,b` entre 1 y 6.
Se rechaza al comprometer si: algún premio del pool **no tiene ninguna combinación**; una misma combinación está asignada a **dos premios** (el resultado sería ambiguo); una combinación referencia un premio que la campaña no define. (`[a,b]` y `[b,a]` se tratan como la misma combinación.)

## 5. Cambios por capa

| Capa | Cambio |
|---|---|
| Base de datos y motor | **Ninguno.** `game_type` ya admite cualquier identificador en minúsculas. |
| Backend | `Presentation`: `assertRepresentable` y `build` para `memory` y `dice` (CSPRNG, validación al comprometer, guardado con la redención). Pruebas de propiedades: el resultado construido **siempre** es consistente con el premio decidido. |
| Contrato | `docs/openapi.yaml`: `gameType` pasa a `[roulette, scratch, memory, dice]`; esquemas de `gameResult`/`gameConfig` de cada uno; ejemplos nuevos en `docs/contract/examples/` verificados por `ContractExamplesTest`. |
| Flutter | Paquetes `packages/game_memory` y `packages/game_dice` (mismo patrón que `game_roulette`/`game_scratch`): `GameModule`, animación con `CustomPainter`, textos en `GamificationStrings` (es/en). Registro en `apps/standalone` y en el SDK. |
| Demo | `gamification:demo:seed` crea una campaña y un lote de cada juego con códigos reales (`CONCENTRESE-…`, `DADOS-…`); aparecen en el panel "Códigos de prueba". También códigos en el `MockRepository` para el modo sin backend (RF-08). |

## 6. Requerimientos funcionales
| ID | Requerimiento |
|---|---|
| RF-60 | Concéntrese jugable: voltear cartas, comparar parejas, volver a taparlas, terminar al hallar la pareja premio o al completar el tablero. |
| RF-61 | Dados jugables: botón TIRAR, animación de 2 dados que termina en la combinación del servidor, tabla de premios visible. |
| RF-62 | Ambos juegos usan el flujo común: código → canje atómico **antes** de animar → resultado → saldo (premio de dinero actualiza el saldo). |
| RF-63 | Un reintento del mismo código devuelve exactamente el mismo tablero / los mismos dados (la presentación se guarda). |
| RF-64 | Tema, textos y premios solo por configuración de campaña; nada hardcodeado en el código de juego (regla global). |
| RF-65 | Configuración inválida (pares fuera de rango, combinación ambigua, premio sin combinación): el lote **no se compromete**; si llega al cliente igual, el juego muestra un aviso y deja continuar al resultado (el premio ya está acreditado), como en ruleta y raspa. |
| RF-66 | Los dos juegos aparecen en la demo con códigos de prueba que cubren: premio de dinero, otro tipo de premio y sin premio. |

## 7. Requerimientos no funcionales
- **Accesibilidad (como RNF-05 de SPEC-001):** todo se puede jugar sin gestos precisos: Concéntrese con teclado y lector de pantalla (cada carta con etiqueta y estado), "Revelar todo"; Dados con un botón TIRAR. Respeta "reducir movimiento" (sin giro, el resultado se muestra directo).
- **Rendimiento:** 60 fps en gama media; sin imágenes pesadas (dibujo por `CustomPainter`).
- **Animaciones:** los controladores se crean en `initState` (lección del proyecto: nunca como `late final` con inicializador).
- **Sonido/vibración:** mismo mecanismo (`SystemFeedback`) que los demás juegos; el sonido real en web sigue pendiente (ver `CLAUDE.md`).
- **Pruebas:** cada juego con tests de widget (mecánica, accesibilidad, config inválida), tests de la presentación en el backend, y E2E en navegador real.

## 8. Criterios de aceptación
- CA-50 **Concéntrese, premio:** con un código ganador de $ 10.000 el tablero tiene exactamente una pareja con el símbolo del premio; al voltearla el juego termina y muestra "¡Ganaste! $ 10.000".
- CA-51 **Concéntrese, sin premio:** el tablero no contiene ninguna pareja de un premio; al descubrir todo muestra "¡Esta vez no hubo suerte!".
- CA-52 **Concéntrese, integridad:** en todo tablero cada símbolo aparece exactamente 2 veces y ningún relleno coincide con un id de premio (propiedad probada sobre muchas ejecuciones y todos los premios).
- CA-53 **Dados:** los dados se detienen exactamente en `dice` del servidor y la combinación pertenece a las del premio decidido (probado para todos los premios del pool).
- CA-54 Una campaña con una combinación asignada a dos premios, o con un premio sin combinaciones, no se puede comprometer.
- CA-55 Repetir el mismo código (idempotencia) devuelve el mismo tablero / los mismos dados byte a byte.
- CA-56 Con "reducir movimiento" ambos juegos muestran el resultado sin animación y siguen siendo jugables con teclado.
- CA-57 `ContractExamplesTest` y `OpenApiContractTest` incluyen los dos juegos y pasan; los vectores del motor no cambian.
- CA-58 **E2E en navegador real:** desde la demo, un código de `CONCENTRESE` y uno de `DADOS` se juegan completos, el saldo se actualiza y el reintento se comporta según la regla de la campaña.

## 9. Desglose propuesto
| Id | Tarea | Rol | Depende de |
|---|---|---|---|
| G1 | Esta spec | Analista | — |
| G2 | Diseño visual: cartas y su volteo, dados y su tirada, tabla de premios, estados de error (`docs/ux-spec-002.md`) | UX/UI | G1 |
| G3 | Diseño técnico breve: forma de `gameResult`/`gameConfig`, reglas de validación | Arquitecto | G1 |
| G4 | Backend: presentación de ambos juegos + validación + pruebas de propiedades | Backend | G3 |
| G5 | Contrato OpenAPI y ejemplos | Backend | G4 |
| G6 | `game_memory` (Flutter) + tests de widget | Frontend | G2, G5 |
| G7 | `game_dice` (Flutter) + tests de widget | Frontend | G2, G5 |
| G8 | Registro en SDK y app, textos es/en, `MockRepository`, campañas de la demo | Frontend/Backend | G4, G6, G7 |
| G9 | Senior Reviewer · QA · E2E en navegador (CA-58) | Senior/QA | G8 |

G6 y G7 corren en paralelo. Estimación de alcance: pequeño-mediano; no toca el motor ni la base.

## 10. Decisiones pendientes (Luis)
- **D11 — Reglas de la demo de Dados.** Propuesta: 2 dados; los **dobles** pagan (1-1 y 6-6 → $ 50.000; 2-2 al 5-5 → $ 10.000) y el resto es "Sin premio". Cualquier otra tabla se cambia solo por configuración.
- **D12 — Tamaño de Concéntrese en la demo.** Propuesta: 4×3 (6 parejas). Configurable de 3 a 8 parejas.
- **D13 — Transparencia del juego.** Propuesta: mostrar un texto corto ("tu premio ya está decidido; el juego es la forma de descubrirlo"), igual que "Tu premio ya está asegurado" del raspa. Es una decisión de comunicación y posiblemente legal (D3).

## 11. Evolución futura (idea de Luis, 2026-10-20) — fuera de esta etapa
En la demo la habilidad no cambia el premio y está bien. Más adelante se podría **medir la habilidad contra un oponente** (p. ej. una IA) y basarse en probabilidad para ofrecer opciones: quién resuelve más rápido, o cuántas parejas halla.
Implicaciones que hay que decidir **antes** de especificarlo, porque cambian el principio de esta plataforma:
- **El premio deja de estar pre-asignado por código.** Hoy el sorteo (SPEC-002) fija el premio de cada código con stock exacto y es verificable por terceros. Si el desempeño del jugador influye, hay que decidir qué parte sigue siendo sorteo (p. ej. el *techo* de premio posible, o un premio base más una bonificación) para conservar stock exacto y auditoría.
- **Estatus legal (D3):** un juego donde influye la habilidad se trata distinto de uno de azar en la regulación de promociones; conviene la asesoría legal antes de diseñarlo.
- **Un oponente IA** debe ser configurable por campaña (dificultad como parámetro, nunca fija) y su ventaja o desventaja debe quedar registrada para auditar que no se manipula el resultado.
- El cliente seguiría sin decidir nada: el servidor valida el tiempo y los movimientos recibidos.
Cuando se retome: spec nueva (SPEC-00X) con Game Expert para el balance, y decisión de negocio sobre cómo se combinan sorteo y desempeño.

