# SPEC-008 · T0 — Prueba de viabilidad con Flutter 3.50.1

2026-11-25 · Base: `a258f16` · **Resultado: viable. Ninguna decisión (D1–D4) queda invalidada.**

Se hizo en un worktree aparte (rama local `spike/spec-008-t0`, sin commit ni push) con el SDK 3.50.1 aislado y su SHA-256 verificado. El Flutter instalado sigue en 3.32.8.

## Resultados

| Prueba | 3.32.8 (línea base) | 3.50.1 sin tocar código | 3.50.1 migrado |
|---|---|---|---|
| `flutter analyze` | sin problemas | 41 avisos, todos por `material.dart` deprecado (falla) | sin problemas |
| Pruebas | **465** pasan | 465 pasan | 465 pasan |
| `dart format` | — | — | 0 archivos cambiados |
| Compilación web | bien | bien (avisa que `--pwa-strategy` está deprecado) | bien, sin esa bandera |
| `main.dart.js` | 2.579.212 B | 2.795.579 B | 2.824.198 B (+9,5 %) |
| Descarga al abrir la app | ≈ 4,33 MB | — | ≈ 4,47 MB (+3 %) |
| Carpeta `build/web` | 24 MB | 41 MB | no medida |

La descarga se midió desde el navegador; una de las cargas de la línea base dio 2,75 MB sin explicación, así que la cifra es orientativa. RNF-2 (máx. +20 %) se cumple.

## La migración

- `sdk: ^3.14.0`, `flutter: ">=3.50.0"`, `flutter_lints` 6.0.0.
- `flutter pub add material_ui` (1.11.1) en 10 proyectos y `dart fix --apply --code=migrate_design_widgets`: 41 correcciones en 41 archivos. Un segundo `dart fix --apply`: 5 correcciones de `unnecessary_underscores`.
- Total antes de Android e iOS: 64 archivos, +125 / −79 líneas. Ninguna prueba se modificó a mano.
- Riverpod se queda en 2.6.1 y funciona. `cupertino_ui` 1.1.7 entra solo como dependencia indirecta.
- La herramienta agrega `analyzer: exclude` a los 11 `analysis_options.yaml`.

## Android

- Con lo actual no compila: Flutter exige **Gradle ≥ 9.3.1**.
- Con Gradle 9.5.0, AGP 9.3.1, Kotlin 2.4.20, Java 17 y sin `kotlin-android`: APK de depuración (159 MB) y de publicación (50,5 MB) generados.
- El migrador agregó `android.builtInKotlin=false` y `android.newDsl=false`. **Pendiente en T4:** probar con `builtInKotlin=true`.
- No se instaló ni se abrió en un emulador (CA-7 pendiente).

## iOS

- `flutter build ios --simulator` compila. Flutter migró solo: mínimo 15.0 y ciclo de vida UIScene.
- **No se arrancó:** no hay ningún simulador instalado (CA-8 pendiente).
- No se probó si la versión actual se cierra al arrancar con el SDK de iOS 27.

## Web: comparación visual

Capturas de ambas compilaciones con backend simulado: ingreso y estado inicial de los 7 juegos a 390 px; ingreso y ruleta en escritorio. No se jugaron partidas ni se vieron las pantallas de resultado ni el recorrido de la muestra.

| Diferencia | Qué hacer |
|---|---|
| Póker: ♥ y ♦ se ven más grandes y de un rojo más vivo (parece otra fuente de respaldo) | Corregir en T3; causa sin confirmar |
| Campo «Código promocional»: la etiqueta tiene más margen izquierdo en móvil | Aprobar o ajustar |

En lo demás no se apreciaron diferencias.

Service worker: sin la bandera, 3.50.1 genera uno que se desregistra solo y recarga la página. Es la opción a validar en T5 (CA-9).

## No probado

- Imagen Docker propia (queda para T1).
- E2E contra el backend (`juegos.spec.ts` y la muestra).

## Cambios en esta máquina

Se descargaron: NDK 28.2.13676358 (2,8 GB), Build-Tools 36.0.0, Gradle 9.5.0 y su caché (≈ 3,8 GB). El SDK 3.50.1 (4 GB) y la rama de prueba están en la carpeta temporal de la sesión, que puede borrarse al reiniciar.

**Disco: quedan ≈ 3 GB libres.** Durante la compilación de Android llegó a 749 MB. No alcanza para instalar un simulador de iOS ni para construir la imagen Docker con holgura.
