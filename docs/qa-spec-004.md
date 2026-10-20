# QA de SPEC-004 (Concéntrese y Dados) — 2026-10-20

## Suites
| Suite | Resultado |
|---|---|
| Backend PHP | 426 pasan, 1 omitida a propósito (regenera el fixture del verificador) — 24 nuevos |
| Vectores del motor | **idénticos** (CA-57): el motor y el verificador no cambiaron |
| Verificador Python | 35 de 35 |
| Flutter | core 35 · http 93 · sdk 60 · standalone 6 · game_roulette 27 · game_scratch 34 · **game_memory 24 · game_dice 18** |
| `flutter analyze` / `dart format` | sin avisos, formato estable |

## Verificación por mutación
- Backend (5): premio con una sola carta, sin descontar la pareja premio, combinación de otro premio, sin detectar ambigüedad, sin ordenar `[a,b]`/`[b,a]` → los tests fallan en todos.
- Flutter (6): termina con cualquier pareja, no bloquea toques al resolver, no vuelve a tapar, aterriza en caras cosméticas, resalta siempre la misma fila, no celebra el premio → los tests fallan en todos.

## E2E en navegador real (Playwright, Chromium; web :8080 + backend :8000, códigos reales)
El script lee la respuesta real de la API en el navegador y comprueba que lo mostrado coincide con lo que decidió el servidor.
- **Concéntrese, premio (250 puntos):** un fallo intencional se vuelve a tapar; una pareja de relleno queda con borde verde y atenuada; la pareja del premio (cartas 9 y 12) se resalta con "+250 puntos"; "Tu premio ya está asegurado" visible; pantalla "¡Ganaste! +250 puntos".
- **Concéntrese, sin premio:** el tablero no tiene pareja de premio; termina al completar las parejas; "¡Esta vez no hubo suerte!".
- **Dados, premio ($ 50.000):** el servidor decidió 6-6; la pantalla mostró "Dados: 6 y 6" y resaltó la fila de $ 50.000 de la tabla; saldo 140.000 → 190.000.
- **Dados, sin premio:** el servidor decidió 1-6; la pantalla mostró "Dados: 1 y 6"; saldo sin cambio.
- Sin errores de página; CORS correcto.

## Observaciones
| # | Sev. | Observación |
|---|---|---|
| Q5 | Baja | La pantalla de resultado no expone su texto en el árbol de accesibilidad de Flutter Web que lee Playwright (`innerText`); el E2E lo verifica con capturas. Ya ocurría con la ruleta. Revisar con un lector de pantalla real. |
| Q6 | Info | El E2E sigue siendo manual (scripts de Playwright fuera del repositorio). |
| Q7 | Info | La imagen web desplegada se reconstruyó antes del último ajuste visual de Dados (se oculta la pista al terminar); requiere `docker compose up -d --build web` para verlo. |
