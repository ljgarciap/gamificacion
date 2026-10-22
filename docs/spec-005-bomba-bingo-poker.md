# SPEC-005 — Tres juegos nuevos: Bomba 8, Bingo en vivo y Póker del crupier

Estado: **APROBADA por Luis (2026-10-22). H1 (Bomba 8) implementado.** Incluye el diseño de UX (sección 8). Origen: petición de Luis 2026-10-22, tras ver las raspas de susuerte.com: «la idea no es copiar el concepto de raspar sino armarlo un poco diferente». Se toman tres ideas: Bingo (bolas una a una), Joker Loco (cartas contra el crupier) y 8S (con una bomba que se consume). **Nombres y arte propios**: no se usan «Joker Loco» ni «8S» ni nada del arte de esa página.

## 1. Objetivo y límites
- Tres `gameType` nuevos: `bomb`, `bingo`, `poker`. Mismo patrón que Concéntrese y Dados (SPEC-004): **el premio ya está decidido por el motor en el canje**; el servidor además decide la **presentación** (qué se ve) y la guarda con la redención; el cliente solo la anima. No cambia el motor de sorteo, los vectores ni el esquema de la base.
- **La habilidad del jugador no cambia el premio** (como en SPEC-004). Se dice en pantalla y en el reglamento. No hay rival simulado en estos tres (a petición de Luis solo Concéntrese lo tiene).
- Nada de premios, probabilidades, textos ni colores fijos en el código: todo viene de la campaña/tema. Moneda COP.
- Fuera de alcance: comodín (joker) en el póker, jugar varias manos o partidas por código, multijugador.

## 2. Contrato común
- `gameConfig` (por campaña) y `gameResult` (por redención, lo construye el servidor con el CSPRNG, nunca `rand`) se describen en `docs/openapi.yaml` con esquema y un ejemplo verificado por `ContractExamplesTest`, como en SPEC-004.
- `Presentation::assertRepresentable` valida **al comprometer el lote** (si la configuración no puede representar cada premio del pool, se rechaza con un mensaje claro) y `Presentation::build` construye el resultado. Un reintento (idempotencia) devuelve exactamente lo mismo.
- «Sin premio» (`type: none`) es implícito: no necesita configuración propia; el juego se construye para que **no** complete nada que pague.
- Un premio gana si su `type` no es `none`; todos los premios que ganan deben tener su correspondencia en la configuración del juego.

## 3. Bomba 8 (`bomb`)
**Mecánica.** Una cuadrícula de cartas boca abajo (por defecto 3×4 = 12). El jugador voltea una carta cada vez; con **cada carta descubierta se consume la mecha** de una bomba. Si aparecen **tres 8** antes de que se acabe la mecha, la bomba se desactiva y gana el premio; si se voltean todas las cartas sin tres 8, **la bomba estalla** (sin premio). La mecha dura hasta voltear todas las cartas.

**Qué decide el servidor.** La cara de cada carta depende del **orden en que se voltea, no de su posición**: el servidor manda la lista de caras en orden de volteo y la n-ésima carta que el jugador toque muestra `faces[n]`, sea cual sea. Así los 8 «salen al final» de verdad y no se puede esquivar el resultado eligiendo otra carta. La pantalla lo dice («Las cartas se destapan en orden»).
- **Con premio:** exactamente tres `"8"` en `faces`; el tercero queda entre las últimas `lateWindow` volteadas (por defecto 4). Los otros dos aparecen antes, mezclados con símbolos de relleno.
- **Sin premio:** 0, 1 o 2 `"8"` (cosmético: el «casi» ya está en el juego; se elige con el CSPRNG, uniforme). Nunca tres.
- Los símbolos de relleno no se repiten más de 2 veces (para que nada parezca una combinación ganadora) y nunca igualan a un id de premio.
- Si gana, el juego termina al aparecer el tercer 8 (el resto de `faces` solo se ve con «Revelar todo»).

**Contrato.**
`gameConfig`: `{ "cards": 12, "columns": 4, "lateWindow": 4, "symbols": ["star","moon",…] }` (todos opcionales).
`gameResult`: `{ "faces": ["moon","8","star", … ] }` (longitud = `cards`; el 8 es la cadena `"8"`).
**Validación al comprometer:** `cards` entero de 9 a 16; `columns` de 2 a 5; `lateWindow` de 1 a `cards − 3`; `symbols` sin repetidos, sin `"8"` ni ids de premio y suficientes para rellenar con a lo sumo 2 de cada uno en el peor caso, sin ningún 8 (`ceil(cards / 2)`).

## 4. Bingo en vivo (`bingo`)
**Mecánica.** Un cartón de 5×5 con columnas B-I-N-G-O (rangos 1–15, 16–30, 31–45, 46–60, 61–75) y casilla central libre. Salen **bolas una a una** (automático cada `drawIntervalMs`, o con «Siguiente bola»), y el número que sale se marca solo en el cartón. Se gana al completar un **patrón** que la campaña asocia a un premio. Al ganar se detiene y se resalta el patrón.

**Patrones** (catálogo fijo en el código, la campaña elige cuáles usa y con qué premio): `line` (cualquier fila, columna o diagonal), `corners` (las 4 esquinas), `full` (cartón lleno). La **prioridad** es el orden en que la campaña los lista (el primero es el mayor): si al final se completa más de un patrón que paga, cuenta el de mayor prioridad.

**Qué decide el servidor.** Dado el premio, elige el patrón, arma un cartón y la secuencia de `draws` bolas (por defecto 30, sin repetir) tal que:
- el patrón del premio se completa en la bola `winAt` (entre `minWinDraw` y `draws`, por defecto desde la 10);
- **ningún patrón de mayor prioridad se completa** en ninguna bola (si no, el premio sería otro);
- sin premio, **no se completa ningún patrón que pague** en ninguna de las `draws` bolas.
Se construye con muestreo con rechazo (CSPRNG) y se **verifica simulando** la partida antes de guardarla; si no logra una secuencia válida tras un número acotado de intentos es un error de programación, no un resultado inventado.

**Contrato.**
`gameConfig`: `{ "draws": 30, "drawIntervalMs": 1200, "minWinDraw": 10, "patterns": [ {"pattern":"full","prize":"p100k"}, {"pattern":"corners","prize":"p50k"}, {"pattern":"line","prize":"p10k"} ] }` (`patterns` obligatorio; el resto opcional).
`gameResult`: `{ "card": [[…5 números…]×5] (el centro es null), "balls": [ … draws números … ], "winAt": 17 | null, "pattern": "line" | null, "cells": [[fila,col],…] | null }` — el servidor manda también `winAt` y `cells` para resaltar sin que el cliente tenga que detectar nada.
**Validación al comprometer:** `draws` de 24 a 60; `drawIntervalMs` de 400 a 3000; `minWinDraw` de 5 a `draws`; cada premio ganador del pool aparece en exactamente un patrón, y ningún patrón se repite; `draws` ≥ celdas que exige el patrón (`line`=4, `corners`=4, `full`=24 sin contar el centro libre) y `minWinDraw` ≥ ese número.

## 5. Póker del crupier (`poker`)
**Mecánica.** El jugador recibe **2 cartas**; el crupier abre **5 cartas comunes** una a una (3, 1 y 1, con una pausa entre cada una). Cuenta la **mejor mano de 5** entre las 7. Cada categoría que paga se asocia a un premio. Al terminar se resaltan las 5 cartas de la mano. El crupier **solo abre cartas**: no tiene mano propia ni es un rival.

**Categorías** (de menor a mayor): `high_card`, `pair`, `two_pair`, `three_of_a_kind`, `straight`, `flush`, `full_house`, `four_of_a_kind`, `straight_flush` (la escalera real cuenta como `straight_flush`). La campaña dice cuáles pagan y con qué premio; las demás no pagan.

**Qué decide el servidor.** Dado el premio, elige la categoría y construye 7 cartas de una baraja de 52 (CSPRNG) cuya **mejor mano es exactamente esa categoría**; sin premio, una mano **por debajo de la categoría mínima que paga** (se favorece pareja y doble pareja, que dan el «casi»). Cada construcción se **verifica con el evaluador** antes de guardarla (el evaluador está probado contra manos conocidas y por propiedades). Qué cartas quedan en la mano del jugador y cuáles en la mesa es aleatorio.

**Contrato.**
`gameConfig`: `{ "revealIntervalMs": 900, "hands": { "p50k": "four_of_a_kind", "p10k": "full_house" } }` (`hands` obligatorio).
`gameResult`: `{ "hole": ["AS","KD"], "board": ["2H","7C","TD","JS","QS"], "hand": "full_house" | null, "best": ["…" ×5] | null }`. Cartas: rango `2-9 T J Q K A` + palo `S H D C`.
**Validación al comprometer:** `revealIntervalMs` de 400 a 3000; `hands` con categorías de `three_of_a_kind` en adelante, sin repetir categoría, un premio por categoría, y todo premio ganador del pool presente.

## 6. Cliente, backend y panel (qué se toca)
| Capa | Trabajo |
|---|---|
| Backend | `Presentation`: `assertRepresentable` + `build` para `bomb`, `bingo`, `poker` (clases propias en `app/Domain/Redemption/`); `gameType` en validaciones; demo (`gamification:demo:seed`) con una campaña por juego |
| Contrato | `docs/openapi.yaml` (enum de `gameType`, `BombConfig/BingoConfig/PokerConfig`, ejemplos nuevos en `docs/contract/examples/`) y `docs/openapi-admin.yaml` si lista los tipos |
| Flutter | `packages/game_bomb`, `game_bingo`, `game_poker` (patrón de `game_dice`): `GameModule`, animación con `CustomPainter`, textos es/en en `GamificationStrings`, registro en `apps/standalone` y en el SDK; `MockRepository` con códigos para el modo sin backend |
| Panel | Tipos de juego nuevos en el selector y en `GAME_LABEL`, ejemplo de JSON por juego; un E2E por juego que crea la campaña, comprueba que se acepta y que un JSON inválido se rechaza al comprometer |
| Docs | `docs/spec-005…` (este), actualizar `CLAUDE.md` y `docs/api-v1.md` |

## 7. Requisitos de aceptación
- CA-60 **Consistencia (por propiedades, miles de semillas, cada juego):** el resultado construido **siempre** corresponde al premio decidido: Bomba (3 ochos exactos con premio, ≤2 sin él, tercer 8 en la ventana final), Bingo (el patrón se completa en `winAt`, ningún patrón de mayor prioridad, sin premio no se completa nada que pague), Póker (la mejor mano es exactamente la categoría; sin premio, por debajo de la mínima).
- CA-61 El evaluador de póker y el simulador de bingo se prueban contra casos conocidos (manos de referencia, cartones y secuencias a mano) y por mutación.
- CA-62 Un reintento con la misma clave devuelve exactamente la misma presentación, byte a byte.
- CA-63 Un lote con una configuración que no puede representar un premio del pool **no se compromete** (mensaje claro).
- CA-64 Cada juego es jugable de principio a fin en el navegador (Playwright contra la pila Docker): con premio y sin premio; «Revelar todo» funciona; el saldo se actualiza.
- CA-65 Accesibilidad: todo se puede hacer con teclado y lector de pantalla (cada carta/bola/casilla con etiqueta y estado, anuncios con `liveRegion`); respeta «reducir movimiento» (sin animaciones largas: se muestra el resultado directo).
- CA-66 Ninguna constante de negocio (premios, patrones que pagan, categorías, tiempos) está en el código: todo sale de la campaña. Los catálogos (patrones, categorías, símbolos por defecto) son parte del juego, no del negocio.
- CA-67 Ninguna prueba existente cambia de resultado (ruleta, raspa, Concéntrese, Dados, motor, vectores).

## 8. Diseño de UX
Principios comunes: **móvil primero** (360 px de ancho mínimo; se ve bien hasta 1280), un solo botón principal por pantalla, texto de ayuda de una línea que dice qué hacer, y la frase fija «Tu resultado ya está decidido: jugar solo lo muestra» en pequeño. Colores y tipografía del tema de la campaña; sin imágenes (todo con `CustomPainter`/íconos). Estados: inicial → jugando → terminado (con premio / sin premio) → pasa a la pantalla de resultado como los demás juegos.

**Bomba 8.** Arriba la bomba (esfera oscura, mecha curva con una chispa que avanza; **la mecha se acorta un paso con cada carta**, y en el último tercio cambia de color a rojo y la chispa late). Debajo la cuadrícula; cada carta se voltea con la animación de Concéntrese y muestra su símbolo; los 8 salen grandes y dorados, con un contador «8 · 8 · 8» que se va llenando. Al tercer 8: la mecha se apaga, la bomba se abre y aparece la etiqueta del premio. Si se acaba la mecha: la bomba tiembla, estalla con una animación corta de humo (sin destellos rápidos) y dice «¡Se acabó la mecha!». Botón «Revelar todo» (voltea en orden). Sonido/vibración de cada carta y del final con los mismos `GameFeedback` de los demás juegos.

**Bingo en vivo.** Arriba la **bola actual** grande (animada entrando desde un lado) y debajo las últimas 5 bolas; en el centro el cartón 5×5 con las letras B-I-N-G-O, la casilla central libre marcada; el número que sale se marca con una ficha. Control: «Siguiente bola» (y «Automático» activado por defecto con el intervalo de la campaña; un toque lo pausa). Al completar el patrón: se resaltan las casillas, destello suave y el premio. Una tabla compacta de patrones y premios queda visible (como la tabla de Dados). «Revelar todo» avanza hasta el final.

**Póker del crupier.** Mesa verde oscura (tema) con el crupier arriba (solo un ícono y la frase «Abre las cartas»), las 5 cartas comunes en fila (boca abajo al inicio) y las 2 cartas del jugador abajo. Cada carta se voltea con una animación corta; mientras se abren se muestra la mano que ya se tiene («Pareja de reyes»), y al terminar se resaltan las 5 de la mejor mano y se nombra («¡Full!»). Tabla de premios por categoría visible. Botón «Abrir siguiente» (y automático como en Bingo). «Revelar todo» abre todo.

## 9. Descomposición
| # | Bloque | Rol | Depende de |
|---|---|---|---|
| H1 | Bomba 8: backend (`Presentation`, validación, ejemplos de contrato, pruebas de propiedades), paquete `game_bomb`, panel, demo, E2E | Backend + Frontend | — |
| H2 | Bingo en vivo: simulador y constructor con verificación, contrato, `game_bingo`, panel, demo, E2E | Backend + Frontend | H1 (patrón) |
| H3 | Póker del crupier: evaluador, constructor con verificación, contrato, `game_poker`, panel, demo, E2E | Backend + Frontend | H1 |
| H4 | QA conjunta: suite completa, E2E de los tres en el navegador, accesibilidad, informe `docs/qa-spec-005.md` | QA | H1–H3 |

## 10. Decisiones (con mi supuesto; confirma o cambia)
| # | Decisión | Supuesto |
|---|---|---|
| D12 | Bomba: tamaño, número de 8 y duración de la mecha | 3×4 (configurable 9–16), exactamente 3 ocho, la mecha dura todas las cartas |
| D13 | Bomba: ¿las caras dependen del orden de volteo (no de la posición)? | **Sí**, y se avisa en pantalla |
| D14 | Bingo: formato | 5×5 con 75 bolas, 30 bolas por partida (configurable 24–60), patrones `line`, `corners`, `full` |
| D15 | Bingo: ¿sale solo o a toque? | Automático por defecto, con pausa y «Siguiente bola» |
| D16 | Póker: variante y categorías que pagan | 2 cartas + 5 comunes (mejor mano de 5); pagan de `three_of_a_kind` en adelante (la campaña elige cuáles) |
| D17 | Póker: ¿el crupier tiene mano propia o rival? | **No**: solo abre cartas (el rival simulado queda solo en Concéntrese) |
| D18 | Póker: ¿comodín (joker)? | No en esta versión |
| D19 | Nombres comerciales | «Bomba 8», «Bingo en vivo», «Póker del crupier» (propios, sin marcas de terceros) |
| D20 | Orden de construcción | Bomba → Bingo → Póker |

## 11. Riesgos
- **Bingo y póker son los más delicados:** construir resultados que cumplan restricciones (ningún patrón de mayor prioridad, mano exacta) exige muestreo con rechazo y verificación. Por eso cada resultado se **simula/evalúa antes de guardarlo** y se prueba con miles de semillas y mutación.
- **Percepción de «trampa»:** que las caras de la Bomba dependan del orden es una decisión de diseño, no un engaño en el sorteo (el premio lo decide el motor verificable), y se informa en pantalla y en el reglamento. Se sugiere que legal revise el texto del reglamento.
- **Tiempo de partida:** una partida de Bingo con 30 bolas a 1,2 s dura ~36 s; hay «Revelar todo» y pausa para quien no quiera esperar.
