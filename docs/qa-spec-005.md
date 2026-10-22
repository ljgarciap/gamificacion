# QA de SPEC-005 (Bomba 8, Bingo en vivo y Póker del crupier) — 2026-10-22

## Suites
| Suite | Resultado |
|---|---|
| Backend PHP | **874 pasan**, 4 omitidas (2 son las mediciones `GAMIF_BENCH`), 0 fallan — con las pruebas nuevas de presentación, contrato y demo de los tres juegos |
| Vectores del motor | **idénticos** (CA-67): el motor, el verificador y los vectores no cambiaron |
| Verificador Python | 62 de 62 |
| Flutter | core 38 · http 113 · sdk 73 · standalone 6 · roulette 27 · scratch 34 · memory 32 · dice 21 · **bomb 13 · bingo 15 · poker 13** |
| `flutter analyze` / `dart format` | sin avisos, formato estable |
| E2E del panel | las pruebas de campañas crean y filtran campañas de los tres juegos nuevos |
| **E2E de los juegos en navegador** (`admin-panel/e2e/juegos.spec.ts`, nuevo, dentro del repositorio) | **15 de 15** |

## E2E en navegador real (Playwright, Chromium; web :8080 de Docker + backend :8000, códigos reales)
Antes los E2E de los juegos eran scripts manuales fuera del repositorio. Ahora `juegos.spec.ts` los cubre a todos (`npx playwright test e2e/juegos.spec.ts`, con `docker compose up -d db backend web` y `gamification:demo:seed`). Para cada uno de los **7 juegos × {con premio, sin premio}**:
- lee la respuesta **real** del servidor al canjear (interceptada en el navegador) y comprueba que es coherente con el premio: ruleta (segmento válido), raspa (3 símbolos del premio o ninguno), Concéntrese (pareja premio o ninguna), dados (solo los dobles pagan), **Bomba** (tres 8 con premio, el tercero entre los últimos 4; menos de tres sin él), **Bingo** (tantas bolas únicas como `draws`, patrón y `winAt` solo con premio, el patrón corresponde al premio de la tabla) y **Póker** (7 cartas distintas, la mano que paga es la categoría final y corresponde al premio);
- juega la partida de punta a punta con el botón del juego («Revelar todo», «GIRAR» o «TIRAR») y llega a la pantalla de resultado;
- comprueba que el **saldo, leído de la API**, cambió exactamente el premio (o nada sin premio);
- exige cero errores de página y de consola;
- guarda capturas del juego y del resultado en `test-results/`.
Más una prueba de **reutilizar un código** (`showPrevious`): muestra la misma presentación y no vuelve a acreditar.

Verificación de que la prueba detecta fallos: con el servidor mutado para que la Bomba con premio saque dos 8, la prueba **falla** (el servidor responde 500 al armar el tablero). Una primera mutación que probé (`$eights = 2`) era equivalente (las posiciones de los 8 salen de otra constante) y no cambiaba nada: no es un hueco de la prueba.

Verificado además a mano con capturas, uno a uno: Bomba (mecha que se acorta, contador de 8, explosión), Bingo (bola actual y últimas, cartón con marcas, resaltado del patrón y de su fila en la tabla), Póker (mesa de fieltro, «Tienes: Pareja» en el flop, tabla de premios).

## Verificación por mutación (resumen de H1–H3)
- **Bomba:** tercer 8 fuera de la ventana final (3 pruebas fallan); caras por posición en vez de por orden de volteo (1 falla).
- **Bingo:** quitar una guarda del constructor *y* la verificación final (las pruebas con evaluador independiente detectan «patrón mayor completado»); `endAt` siempre igual a `draws` (2 fallan); eventos desfasados en el cliente (2 fallan). Una mutación solo de la guarda no se detecta porque la verificación final reintenta: está pensado así (defensa en profundidad).
- **Póker:** evaluador sin la «rueda» (5 fallan), forma del full equivocada (6 fallan), mejor mano visible por etapa equivocada (3 fallan).

## Rendimiento
Construir lo que se ve (cartón y bolas, o las 7 cartas) cuesta **< 0,3 ms** por canje (Bomba 0,01 ms, Póker ≈ 0,1 ms, Bingo ≈ 0,2 ms): no cambia la capacidad medida en `docs/hardening-p9.md` (≈ 950 canjes/s).

## Criterios de aceptación
| CA | Estado |
|---|---|
| CA-60 Consistencia por propiedades (miles de semillas, evaluador independiente) | ✔ `PresentationBombTest`, `PresentationBingoTest`, `PresentationPokerTest` |
| CA-61 Evaluador de póker y simulador de bingo contra casos conocidos y por mutación | ✔ (manos conocidas, 15 000 manos cruzadas, mutaciones arriba) |
| CA-62 Reintento con la misma clave devuelve lo mismo, byte a byte | ✔ (la presentación se guarda con la redención; el E2E lo comprueba con la Bomba) |
| CA-63 Un lote que no puede representar un premio no se compromete | ✔ (validaciones al comprometer con mensaje claro, probadas por juego) |
| CA-64 Cada juego jugable de punta a punta en el navegador, con y sin premio | ✔ `juegos.spec.ts` (7 juegos) |
| CA-65 Accesibilidad: etiquetas por carta/bola/casilla, `liveRegion`, «reducir movimiento» | ✔ en pruebas de widget (con y sin movimiento, pantalla chica); **no** con un lector de pantalla real |
| CA-66 Sin constantes de negocio en el código | ✔ premios, patrones que pagan, categorías, tiempos y tamaños salen de la campaña; los catálogos (patrones, categorías, símbolos) son del juego |
| CA-67 Nada existente cambia | ✔ ruleta, raspa, Concéntrese, dados, motor y vectores intactos |

## Observaciones
| # | Sev. | Observación |
|---|---|---|
| Q8 | Baja | Hallazgo de proceso: la comprobación de «orden de prioridad» del Bingo reveló un defecto de diseño de la propia spec (con línea y cartón lleno pagando, el cartón lleno nunca podía ser el primer patrón completado). Se rediseñó («gana el patrón de mayor prioridad completado»; el cartón lleno, si se usa, va primero) y la spec quedó actualizada. |
| Q9 | Baja | El primer evaluador de póker comparaba puntajes con `<=>` entre arreglos de distinto tamaño (compara el tamaño antes que el contenido). Lo detectó el evaluador independiente de las pruebas; corregido. |
| Q10 | Info | La pantalla de resultado de Flutter Web no expone su texto al árbol de accesibilidad que lee Playwright (ya ocurría con ruleta y raspa): el E2E verifica por saldo (API) y capturas. Revisar con un lector de pantalla real. |
| Q11 | Info | «Tu premio ya está asegurado» aparece también en partidas sin premio (comportamiento heredado de los demás juegos). |
| Q12 | Info | Una partida de Bingo dura ~36 s a 1,2 s por bola; hay «Pausar», «Siguiente» y «Revelar todo». |
| Q13 | Info | No se probó con un lector de pantalla real ni con `axe` sobre Flutter Web (el panel Angular sí tiene `axe`). El reglamento de los juegos conviene que lo revise legal antes de un lanzamiento (a pedido de Luis, para la demo se ajusta si aprueban). |
| Q14 | Info | Cada corrida de `juegos.spec.ts` consume 2 códigos por juego; hay 30 por lote (`gamification:demo:seed` crea más). |

## Veredicto
**Sin bloqueantes.** Los tres juegos nuevos cumplen sus criterios de aceptación, el resto del sistema no cambió y quedó una prueba E2E repetible de los siete juegos.
