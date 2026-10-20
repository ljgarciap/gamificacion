# UX-SPEC-002 — Concéntrese y Dados

Estado: **vigente con SPEC-004** (aprobada). Extiende `ux-spec-001.md`: mismos tokens (`GamificationTheme`), misma tipografía, mismo encabezado con saldo, misma pantalla de resultado y mismo aviso "Tu premio ya está asegurado" mientras se anima (lo pone el flujo común). Aquí solo lo que es nuevo.

## Principios
1. **El premio ya está decidido** y el juego es la forma de descubrirlo. No hay contador de intentos, vidas ni tiempo límite: nada que sugiera que la habilidad cambia el premio.
2. **Nunca hay scroll dentro del juego**: el tablero y los controles se ajustan al espacio disponible (viewport embebido pequeño incluido).
3. **Todo es operable con teclado y lector de pantalla**; "reducir movimiento" muestra el resultado sin animaciones.
4. Colores solo de tokens del tema; ningún color ni texto fijo en el juego.

## Concéntrese (pantalla 2c)

**Estructura (de arriba abajo):** pista "Encuentra las parejas" (`memoryHint`, texto secundario) → tablero → progreso "Parejas: 2 de 6" (`memoryProgress`) → botón secundario "Revelar todo" (`revealAll`).

**Tablero:** cuadrícula de `columns` columnas; las cartas son cuadradas, con separación de 8 px y esquinas de 14 px. Ancho máximo 88 % del contenedor; el alto se ajusta para no desbordar.

| Estado de la carta | Aspecto |
|---|---|
| Boca abajo | Fondo `navy`, ícono de interrogación en `surface` al 70 %, borde `navy` |
| Boca arriba (sin pareja aún) | Fondo `surface`, símbolo en `navy`/`primary` alternando, borde `navy` 1 px |
| Pareja encontrada (relleno) | Fondo `surface`, símbolo al 55 % de opacidad, borde `success` 2 px |
| Pareja del premio | Fondo `accent` al 30 %, borde `accent` 3 px, ícono y valor del premio (como la casilla ganadora del raspa) |
| Foco de teclado | Anillo `primary` de 3 px por fuera de la carta |

**Símbolos:** los mismos íconos decorativos del raspa; el símbolo igual al `prize.id` se dibuja con el ícono del tipo de premio y su valor ("$ 10.000"). Símbolo desconocido = su texto.

**Interacción:**
- Tocar (o Enter/Espacio) voltea una carta. Al voltear la segunda se comparan: si coinciden se quedan; si no, se ven `flipBackMs` y se vuelven a tapar. Mientras se resuelve una pareja no se aceptan más toques.
- Voltear: giro en el eje vertical, 320 ms, curva ease-in-out. Con "reducir movimiento": cambio instantáneo.
- El juego termina al descubrir la pareja del premio, o al descubrir todo el tablero si no hay premio. Pausa de 900 ms con lo ganador resaltado y se pasa al resultado.
- "Revelar todo" destapa todo el tablero (resaltando la pareja del premio, si existe) y sigue igual: es la alternativa sin precisión y la salida si el jugador no quiere seguir.
- `onStarted` se dispara al primer volteo.

**Accesibilidad:** cada carta es un botón con etiqueta "Carta 3 de 12, boca abajo" o "Carta 3 de 12: estrella" / "…: $ 10.000". El progreso es una región viva (se anuncia al encontrar una pareja). El tablero lleva la etiqueta "Tablero de Concéntrese con 6 parejas".

**Errores:** configuración inválida (pares fuera de rango, `cards` que no cuadra) → texto "Este juego no está disponible en esta versión." y botón "Continuar" (como el raspa y la ruleta): el premio ya está acreditado.

## Dados (pantalla 2d)

**Estructura:** pista "Toca TIRAR para lanzar los dados" (`diceHint`; durante la tirada, "Tirando los dados…") → los 2 dados → botón primario "TIRAR" (`rollDice`) → tabla de premios (`diceTable`).

**Dados:** dos cubos de 96 px (se reducen a 72 px si el alto es menor a 640 px), `surface` con borde `navy` de 3 px, esquinas de 18 px, puntos en `navy`. En reposo muestran 1 y 1 hasta tirar.

**Tirada:** al pulsar TIRAR el botón se deshabilita; durante `rollDurationMs` los dados rebotan y cambian de cara cada 90 ms (las caras intermedias son puramente cosméticas) y se asientan en los valores del servidor, con un rebote final. Con "reducir movimiento": se muestran directamente las caras finales. `onStarted` al pulsar; `onFinished` tras 900 ms con la combinación resaltada.

**Tabla de premios:** lista compacta, una fila por premio que gana (los "Sin premio" no se listan): valor del premio a la izquierda y, a la derecha, sus combinaciones como fichas pequeñas ("2 y 2"). Al terminar la tirada se resalta la fila del premio obtenido con el color `accent`. Si no hay leyenda, la tabla se omite.

**Accesibilidad:** el botón TIRAR es nativo (teclado y lector). Al terminar, el resultado se anuncia ("Dados: 4 y 4"). Cada dado lleva su valor como etiqueta.

**Errores:** `dice` ausente o fuera de 1–6 → el mismo aviso y "Continuar".

## Responsive
Móvil vertical (360–430 px), tableta y web embebida de 320 px de ancho. En horizontal con poco alto, el tablero de Concéntrese pasa a ocupar el alto disponible y los controles quedan a un lado si hay ancho.

## Fuera de alcance
Sonido propio de cada juego (el mecanismo `SystemFeedback` ya da vibración y tick; el audio real en web sigue pendiente, ver `CLAUDE.md`) y animaciones 3D.
