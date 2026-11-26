# SPEC-008 — Actualización a Flutter 3.50.1

**Estado: APROBADA** por Luis (2026-11-25). T0 completa; T1 fusionada y desplegada (PR #4); T2 y T3 en la rama `feat/spec-008-flutter-350` (2026-11-26).

## 1. Resumen

Pasar de Flutter 3.32.8 / Dart 3.8.1 a **Flutter 3.50.1 / Dart 3.14.0** (estable, 2026-11-20) en desarrollo local, CI, Docker y la demostración, sin cambiar el comportamiento de los juegos.

No es subir un número: cambian la imagen Docker base, las librerías de Material, y las plataformas Android e iOS.

## 2. Por qué

- Estamos varias líneas estables atrás (16 meses).
- La imagen `ghcr.io/cirruslabs/flutter` dejó de publicarse (mayo de 2026). Su última versión es 3.44.0, así que ninguna actualización posterior es posible con el Docker actual.
- iOS: según la guía de Flutter, una app compilada con el SDK de iOS 27 sin el ciclo de vida UIScene se cierra al arrancar. La máquina local tiene Xcode 27.1 y el proyecto no declara UIScene. **No probado todavía** (ver T0).

## 3. Alcance

**Dentro:** los 10 paquetes y `apps/standalone`; Dockerfiles; CI; configuración web, Android e iOS; documentación.

**Fuera:**
- Backend Laravel, panel Angular, verificador, contrato de la API.
- Riverpod 3 (se queda en 2.6.1 salvo que T0 demuestre que no compila; en ese caso se vuelve a decidir).
- WebAssembly, publicación en tiendas, firma de Android, módulo add-to-app (no existe en el repositorio), funciones nuevas o rediseños.

## 4. Estado actual (verificado en el repositorio)

| Punto | Hoy | Destino |
|---|---|---|
| Flutter / Dart | 3.32.8 / 3.8.1 | 3.50.1 / 3.14.0 |
| Restricción `sdk` (11 `pubspec.yaml`) | `^3.8.1` | `^3.14.0` |
| Restricción `flutter` de los paquetes | `>=1.17.0` | `>=3.50.0` |
| Imagen Docker | `cirruslabs/flutter:3.32.8` | imagen propia (RF-2) |
| CI | `subosito/flutter-action`, 3.32.8 | la misma acción, 3.50.1 |
| Android | AGP 8.7.3, Gradle 8.12, Kotlin 2.1.0 con `kotlin-android`, Java 11 | RF-6 |
| iOS | `IPHONEOS_DEPLOYMENT_TARGET = 12.0`, sin UIScene | mínimo 15, con UIScene |
| `package:flutter/material.dart` | usado en los paquetes con interfaz y en sus pruebas | `package:material_ui/material_ui.dart` |
| `flutter_lints` | `^5.0.0` | última compatible |
| Service worker | `--pwa-strategy=none` | RF-5 |

## 5. Cambios de Flutter que nos afectan

| Cambio | Desde | Efecto esperado aquí |
|---|---|---|
| `material.dart` y `cupertino.dart` deprecados; paquetes `material_ui` / `cupertino_ui` | 3.50 | **Alto.** `flutter analyze` falla con avisos informativos, así que es de esperar que el CI se rompa. `material_ui` 1.11.1 pide Dart ≥ 3.13 |
| Android: Kotlin integrado (AGP 9) | 3.44 | Quitar `kotlin-android` |
| Android: mínimo API 24, Java 17 | 3.50 | Ajustar Gradle |
| iOS: UIScene; versiones soportadas 15–27 | 3.38 / 3.50 | Migrar `AppDelegate` e `Info.plist` |
| Tokens de Material 3 actualizados | 3.41 | Posibles cambios de color: revisión visual |
| Normalización de temas de componentes | 3.35 | Revisar `gamification_core/lib/src/ui/scope.dart` |
| `ListTile` avisa en debug dentro de un widget con color | 3.44 | Revisar `demo_codes_panel.dart` |
| Transición de página por defecto en Android | 3.38 | Revisión manual |
| Service worker de Flutter deprecado | — | Revisar la bandera de compilación |
| Formato y lints nuevos (Dart 3.14) | — | Cambio mecánico grande, en commit aparte |

Revisado y sin uso en el código: `Radio`, `DropdownButtonFormField`, `SnackBar`, `dart:html`, `package:js`. El `index.html` no declara `viewport-fit=cover`.

## 6. Requisitos

- **RF-1.** Una sola versión (3.50.1) en local, CI, Docker y `CLAUDE.md`.
- **RF-2.** Imagen Docker propia: base Debian/Ubuntu fijada por versión, SDK descargado del archivo oficial **con verificación SHA-256** (`4ad17fc8462fbcef2ba19ff4a78c0a16b7147fde1add8578e267ea62cdb92678`, `flutter_linux_3.50.1-stable.tar.xz`), usuario sin privilegios para compilar, `linux/amd64` (el archivo oficial de Linux listado es x64). La usan `web`, `dev` y `tools`.
- **RF-3.** El CI **compila la web** y construye la imagen Docker en cada PR. Hoy no lo hace: un fallo de compilación solo aparecería al desplegar.
- **RF-4.** `dart format`, `flutter analyze` y todas las pruebas en verde, sin `ignore` globales y sin borrar ni omitir pruebas. Toda prueba modificada se justifica en el PR.
- **RF-5.** La web desplegada no sirve versiones viejas: ni queda un service worker activo ni la caché de nginx entrega archivos anteriores. Se prueba actualizando un navegador que ya había cargado la 3.32.8.
- **RF-6.** Android compila un APK de depuración y uno de publicación con las versiones por defecto de 3.50 (AGP 9.3.1, Gradle 9.5.0, Java 17, API 36, mínimo 24), ajustadas a lo que T0 demuestre.
- **RF-7.** iOS compila y arranca en un simulador con iOS 27, con UIScene y mínimo 15.
- **RF-8.** Sin cambios de comportamiento ni diferencias visuales no aprobadas en los 7 juegos, el ingreso de código, el resultado y el recorrido de la muestra.
- **RNF-1.** Dependencias nuevas verificadas (editor, versión, SDK mínimo) antes de agregarlas.
- **RNF-2.** Registrar antes y después: tamaño de la compilación web, tiempo de compilación en frío e imagen Docker. Un aumento de más del 20 % en la descarga web se consulta con Luis.

## 7. Criterios de aceptación

| # | Criterio |
|---|---|
| CA-1 | `flutter --version` muestra 3.50.1 en local, en el CI y dentro de `docker compose run --rm tools` |
| CA-2 | CI en verde en el PR, incluida la compilación web y la imagen Docker |
| CA-3 | El número de pruebas que pasan es igual o mayor que la línea base de T0 |
| CA-4 | Si cambia un byte del SHA-256 esperado, la construcción de la imagen falla |
| CA-5 | `admin-panel/e2e/juegos.spec.ts` y los recorridos de la muestra en verde contra Docker |
| CA-6 | Capturas antes/después con Playwright de cada pantalla, a 390 px y en escritorio, revisadas y aprobadas por Luis |
| CA-7 | APK de depuración y de publicación generados; la app abre y se juega una partida en un emulador |
| CA-8 | La app abre y se juega una partida en un simulador iOS 27 |
| CA-9 | Un navegador con la versión anterior en caché recibe la nueva al recargar |
| CA-10 | Tras desplegar: `/up` responde, la muestra se juega de punta a punta y la vuelta atrás está ensayada o documentada paso a paso |

## 8. Plan

| Tarea | Responsable | Contenido |
|---|---|---|
| T0 | Frontend + DevOps | **Prueba de viabilidad** en rama aparte, con el SDK 3.50.1 separado del instalado. Línea base en 3.32.8 (pruebas, capturas, tamaños). Luego medir: avisos de `analyze`, pruebas rotas, resolución de dependencias, compilación web, Android e iOS. Entrega un informe corto; si invalida una decisión, se detiene |
| T1 | DevOps | Imagen Docker propia (RF-2) y compilación web en CI (RF-3), **todavía en 3.32.8** |
| T2 | Frontend | Subir versión y dependencias; arreglar compilación y pruebas |
| T3 | Frontend | Migración a `material_ui`; formato en commit separado |
| T4 | Frontend | Android e iOS |
| T5 | DevOps | Web: service worker, caché y despliegue |
| T6 | Senior Reviewer | Revisión; Cybersecurity revisa la imagen y la descarga del SDK |
| T7 | QA | CA-1 a CA-10, informe en `docs/qa-spec-008.md` |
| T8 | Tech Writer | `CLAUDE.md`, `docs/ci-cd.md`, `docs/despliegue-demo.md` |

T1 va en su propio PR: se puede desplegar y verificar sin tocar Flutter, y separa los fallos de imagen de los fallos de versión.

**Entrega y vuelta atrás:** un merge a `master` despliega solo a la demo, así que T2–T5 viven en una rama hasta que QA apruebe. Para volver atrás se ejecuta el flujo de CI (`workflow_dispatch`) sobre el commit anterior; antes del merge se confirma que ese commit todavía construye.

## 9. Decisiones (tomadas por Luis, 2026-11-25)

| # | Decisión | Resultado |
|---|---|---|
| D1 | Material | Migrar a `material_ui` en esta tarea (`cupertino_ui` solo si el código lo necesita; hoy no se importa Cupertino) |
| D2 | Versión mínima para los hosts | Se exige Flutter ≥ 3.50 / Dart ≥ 3.14: `sdk: ^3.14.0` y `flutter: ">=3.50.0"` en todos los paquetes |
| D3 | Plataformas mínimas | Android 7.0 (API 24) e iOS 15 |
| D4 | Alcance | Web, Android e iOS |

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| 3.50.1 es muy reciente y estrena la separación de Material | T0; revisar si hay un parche nuevo antes de T2 |
| `material_ui` 1.x cambia rápido | Fijar versión en `pubspec.lock` y no actualizar durante la tarea |
| Riverpod 2.6.1 sin soporte documentado para Dart 3.14 | T0; si falla, nueva decisión |
| La imagen propia agranda el tiempo de despliegue o llena el disco del servidor | Medir en T1; comprobar espacio antes |
| El Dart arm64 fallaba en Docker Desktop | Mantener `linux/amd64` |
| La actualización local afecta a otros proyectos Flutter (CardGame) | Instalar por versión (FVM) en vez de `flutter upgrade` global |

## 11. Resultado de T0

Viable (`docs/informe-t0-spec-008.md`). Confirmado: 465 pruebas pasan; la migración a `material_ui` es automática; Android exige Gradle ≥ 9.3.1 y compila con las versiones de RF-6; iOS compila con la migración automática; `--pwa-strategy` está deprecado.

Sin verificar: fecha de retiro de `material.dart`, arranque en emulador y simulador, imagen Docker propia y E2E contra el backend.

### T1 (imagen propia, en 3.32.8)

Probado en local: la web y la etapa `sdk` construyen; con un SHA-256 alterado la construcción falla (CA-4); `tools all` en el contenedor, con uid 10001, deja el análisis limpio y 465 pruebas en verde; nginx sirve la web con sus cabeceras. Web en frío ≈ 191 s (con emulación), imagen `web` 109 MB, imagen `tools` 1,78 GB. `dev` arranca y la app carga en :8081.

Revisión (Senior Reviewer y Cybersecurity, en la misma sesión): aprobada para PR con dos ajustes ya hechos (descarga solo por HTTPS con TLS ≥ 1.2; `.env`, `deploy/*.env`, credenciales y `node_modules` fuera del contexto de construcción, que antes entraban a la etapa intermedia). Quedan como estaban: paquetes `apt` sin versión fija y `nginx:1.27-alpine` sin digest y como root.

Verificado después: el trabajo `web` del CI pasó (1 min 37 s) y, tras desplegar, los servicios quedaron arriba con 39 GB libres en el servidor.

### T2 y T3 (3.50.1 y `material_ui`)

- Local: la versión queda fijada con FVM (`.fvmrc`), sin cambiar el Flutter global. CI y Dockerfile suben a 3.50.1 con su SHA-256.
- Migración automática: 41 cambios de importación y 5 de `unnecessary_underscores`; `material_ui` 1.11.1 (editor `flutter.dev`) en 10 proyectos; `dart format` sin cambios, así que no hace falta el commit de formato aparte.
- Póker: la web de 3.50.1 tomaba ♥ y ♦ de la fuente de emojis. Los palos pasan a dibujarse (`suit_mark.dart`), sin depender de fuentes.
- `pubspec.lock` estaba fuera de git, así que la mitigación de la sección 10 no se cumplía. Decisión de Luis (2026-11-26): se versiona, y el CI y Docker usan `pub get --enforce-lockfile`.
- Capturas de ingreso y estado inicial de los 7 juegos: única diferencia, ≈4 px más de margen en la etiqueta «Código promocional» en móvil. **Aprobada por Luis** (2026-11-26). No cubre CA-6 completo (faltan resultados y el recorrido de la muestra).
- 471 pruebas en local (465 + 6 de los palos); los 11 proyectos pasan también dentro de Docker. CI sin probar.

### T4 (Android e iOS)

- Android: Gradle 9.5.0, AGP 9.3.1, Kotlin 2.4.20, Java 17, sin `kotlin-android` y con **Kotlin integrado** (`android.builtInKotlin=true`, probado). Queda `android.newDsl=false`, puesto por el migrador.
- APK de depuración (159 MB) y de publicación (50,5 MB) generados; `minSdk` 24, `targetSdk` 36.
- iOS: compila para simulador con el SDK 27.2; mínimo 15.0 y UIScene presentes en la app compilada.
- **Sin probar: arrancar y jugar (CA-7 y CA-8).** En la máquina local la imagen del sistema Android está incompleta (sin `system.img`), no hay ningún simulador iOS instalado y quedan ≈ 6 GB libres.
- Hallazgo previo a esta tarea: el manifiesto principal de Android no declara `android.permission.INTERNET` (solo depuración y perfil), así que el APK de publicación no puede conectarse a un backend.

## 12. Fuentes

- [Cambios incompatibles](https://docs.flutter.dev/release/breaking-changes)
- [Migración a material_ui y cupertino_ui](https://docs.flutter.dev/release/breaking-changes/material-ui-and-cupertino-ui)
- [Novedades de Flutter 3.50](https://flutter.dev/blog/whats-new-in-flutter-3-50)
- [Kotlin integrado en Android](https://docs.flutter.dev/release/breaking-changes/migrate-to-built-in-kotlin)
- [UIScene](https://docs.flutter.dev/release/breaking-changes/uiscenedelegate)
- [Plataformas soportadas](https://docs.flutter.dev/reference/supported-platforms)
- [Inicialización web](https://docs.flutter.dev/platform-integration/web/initialization)
- [material_ui](https://pub.dev/packages/material_ui)
- [Imágenes de cirruslabs](https://github.com/cirruslabs/docker-images-flutter)
- [Archivo oficial de versiones (Linux)](https://storage.googleapis.com/flutter_infra_release/releases/releases_linux.json)
