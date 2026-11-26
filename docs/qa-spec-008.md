# QA — SPEC-008 (Flutter 3.50.1)

2026-11-26 · Rama `feat/spec-008-flutter-350` (`b087b32`) · PR #5 (borrador)

**Resultado: web aprobada en local (capturas aprobadas por Luis). Android e iOS NO están aprobados** (nunca se arrancaron). Por decisión de Luis (2026-11-26), el PR no se fusiona hasta probar los móviles. No se encontraron fallos en lo probado.

QA hecha en la misma sesión que implementó: no es independiente.

## Criterios de aceptación

| # | Criterio | Estado | Evidencia |
|---|---|---|---|
| CA-1 | 3.50.1 en local, CI y `tools` | Cumple | `fvm flutter --version`, `ci-cd.yml` y contenedor `tools`: 3.50.1 / Dart 3.14.0 |
| CA-2 | CI en verde en el PR | Cumple | Los cinco trabajos en `b087b32`; despliegue omitido |
| CA-3 | Pruebas ≥ línea base (465) | Cumple | 538 en local (73 nuevas: palos del póker y anfitriones sin `material_ui`) |
| CA-4 | SHA-256 alterado detiene la imagen | Cumple | Con la 3.50.1 y un carácter cambiado: `computed checksum did NOT match`, sin imagen |
| CA-5 | E2E de juegos y muestra contra Docker | Cumple | `juegos.spec.ts` 15/15; `muestra-recorrido` y `muestra-planes-recorrido` 2/2, con drand real, Mailpit y verificador independiente |
| CA-6 | Capturas antes/después aprobadas | Cumple con la cobertura descrita abajo | **Aprobadas por Luis** (2026-11-26) |
| CA-7 | APK y partida en emulador | **Parcial** | APK de depuración y publicación generados (antes de la corrección T6). No se arrancó: la imagen del emulador local está incompleta |
| CA-8 | Partida en simulador iOS 27 | **Pendiente** | Compila para simulador (antes de T6); no hay simulador instalado |
| CA-9 | Navegador con la versión anterior recibe la nueva | Cumple en local | Chromium con perfil persistente sobre la imagen de T5. Falta Safari, Firefox y la demo |
| CA-10 | Verificación tras desplegar y vuelta atrás | **Pendiente** | No se ha desplegado. Vuelta atrás documentada abajo, sin ensayar |

## CA-6: comparación visual

Mismo backend y mismo recorrido de `juegos.spec.ts` contra la versión actual (3.32.8) y la nueva: 28 capturas por versión (estado inicial y resultado de los 7 juegos, con y sin premio, a 420 px).

| Diferencia | Valoración |
|---|---|
| Póker: en la versión actual los palos pueden salir como cuadros vacíos mientras baja la fuente; en la nueva se ven desde el inicio | Mejora |
| Confeti en distinta posición | Momento de la captura |
| Saldo, cartas y cartones distintos | Datos de cada partida |
| Etiqueta «Código promocional» ≈ 4 px más a la derecha en móvil | Aprobada por Luis (T3) |

No cubierto: resultados en escritorio y comparación visual de las pantallas de la muestra (sus recorridos pasan, pero no se compararon imágenes).

## Rendimiento (RNF-2)

| Medida | 3.32.8 | 3.50.1 | Cambio |
|---|---|---|---|
| Descarga al abrir la web (orientativa) | 4,33 MB | 4,47 MB | +3 % (límite: +20 %) |
| `main.dart.js` | 2.579.212 B | 2.826.050 B | +10 % |
| Imagen Docker `web` | 109 MB | 133 MB | +22 % |
| Trabajo `web` del CI | 1 min 37 s | 1 min 58 s a 2 min 26 s | dos corridas |

La imagen supera el 20 %; el límite que obliga a consultar es el de la descarga web. La web ahora revalida todos los archivos en cada visita (304 si no cambiaron).

## Vuelta atrás

La guía anterior (ejecutar el flujo sobre un commit antiguo) **no despliega**: el trabajo `deploy` exige `refs/heads/master`. El procedimiento es:

1. `git switch master && git pull`
2. `git revert <commit del merge del PR #5>` (el merge es por *squash*: un solo commit).
3. `git push`: el CI prueba y despliega el estado anterior.
4. Comprobar `/up`, abrir la demo y jugar una partida.

Esta actualización no trae migraciones. El estado anterior es `fee53a3`, que es lo desplegado hoy. El `revert` aún no se ha ensayado.

## No probado

- Arranque en Android e iOS, y una app anfitriona real que embeba el SDK.
- Safari y Firefox.
- Textos de respaldo del SDK en un anfitrión sin `material_ui`: salen en inglés (límite conocido).

## Efectos en el entorno local

Se sembraron dos veces códigos de demostración y los recorridos de la muestra la configuraron, encendieron, apagaron y reiniciaron en la base de desarrollo. Los contenedores quedaron detenidos.
