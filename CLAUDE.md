# Gamificación — minijuegos promocionales (Flutter multiplataforma)

Stack: Flutter 3.32.8 / Dart 3.8.1, pub workspaces + Melos, Riverpod. Backend propio (FastAPI, SPEC-002) y/o adaptador del host.
Juegos: MVP = Ruleta + Raspa y Gana. Roadmap = Concéntrese, Dados.

## Docs vigentes
- `docs/spec-001-nucleo-ruleta-raspa.md` — spec APROBADA (decisiones de negocio en sección 9)
- `docs/ux-spec-001.md` — spec visual APROBADA
- `docs/arquitectura-y-plan.md` — estructura y desglose T1–T12

## Reglas del proyecto
- **El servidor decide el resultado**; el cliente solo anima. Código redimido antes de animar. Idempotencia por `Idempotency-Key`.
- Nada de premios, probabilidades, montos, textos ni colores hardcodeados: todo viene de la campaña/tema.
- Moneda fija COP. Branding por defecto SuperGIROS (reemplazable por tema).
- Dependencias: `game_*` → `gamification_core`; `sdk` → core + juegos; `apps` → sdk. Los juegos no se conocen entre sí.
- Nuevo juego = módulo que implementa `GameModule`, sin tocar el núcleo.
- Navegación interna con `Navigator` simple (no go_router) para no chocar con el host al embeber.
- Los paquetes llevan `publish_to: none` mientras usen dependencias `path`; quitarlo al publicar.
- Validación visual en navegador real con Playwright CLI (no Claude in Chrome).

## Comandos
- `flutter pub get` (raíz) · `melos run analyze` · `melos run test` · `dart format .`

## Docker (local)
- `docker compose up -d --build web` → build de producción en http://localhost:8080
- `docker compose up dev` → hot reload en http://localhost:8081 · `docker compose run --rm tools all` → analyze + tests
- **Imágenes Flutter forzadas a `linux/amd64`**: el Dart arm64 de `cirruslabs/flutter` aborta con `Bus error` en Docker Desktop (Mac Apple Silicon, verificado en 29.8 y 29.9). Es más lento por emulación; la 1ª construcción tarda varios minutos.
- Volúmenes nombrados para `.dart_tool` y pub-cache: evitan que el contenedor pise el `.dart_tool` del host.
- nginx: un `add_header` en un `location` anula los del `server`; las cabeceras de seguridad (`frame-ancestors` vía `ALLOWED_FRAME_ANCESTORS`) se repiten en cada `location`.
- Para flujo diario rápido usar `flutter run -d chrome` en el host; Docker es para reproducibilidad y despliegue.

## Estado
T1 (monorepo), Docker local, T2 (core), T4 (tema + i18n), T5 (flujo común) y T6 (ruleta) completadas: 85 tests, ruleta validada en Chromium real con Playwright. Siguiente: T7 raspa; T3 (OpenAPI) en paralelo.

## Notas de juegos
- **Sonido:** `SystemFeedback` usa `HapticFeedback`/`SystemSound`. En web `SystemSound` NO suena (solo vibra en móvil). Sonido real en web = audio con assets (pendiente; Luis pidió sonido activado por defecto).
- **Validación visual con Playwright (Flutter web):** el árbol de accesibilidad se activa con `focus()` + `Enter` sobre `flt-semantics-placeholder` (el clic de ratón falla: está fuera de pantalla); `getByRole('textbox').fill()` NO llega a Flutter, usar `click()` + `keyboard.type()`. Si no hay navegador de la revisión pedida, pasar `executablePath` al Chromium ya cacheado en `~/Library/Caches/ms-playwright`.
- Ruleta: segmentos 4–12; `gameResult.segmentIndex` fuera de rango o config inválida → el juego muestra aviso y deja continuar al resultado (el premio ya está acreditado).
