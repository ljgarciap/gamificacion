# Gamificación — minijuegos promocionales (Flutter multiplataforma)

Stack: Flutter 3.32.8 / Dart 3.8.1, pub workspaces + Melos, Riverpod. Backend de premios: **Laravel 13 (PHP 8.3) + PostgreSQL 16** (SPEC-002) y/o adaptador del host.
Juegos: MVP = Ruleta + Raspa y Gana. Roadmap = Concéntrese, Dados.

## Docs vigentes
- `docs/spec-001-nucleo-ruleta-raspa.md` — spec APROBADA (decisiones de negocio en sección 9)
- `docs/ux-spec-001.md` — spec visual APROBADA
- `docs/arquitectura-y-plan.md` — estructura y desglose T1–T12
- `docs/spec-002-motor-de-premios.md` — motor de premios y backend, APROBADA (pre-asignado, stock exacto, compromiso + baliza drand, verificable por terceros)

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

## Backend de premios (SPEC-002)
- `docker compose up -d db backend` → API en :8000 (`GET /up`). `docker compose run --rm backend php artisan test` · `... artisan migrate`
- **Laravel 13, no 11**: Composer bloquea TODAS las 11.x por avisos de seguridad (EOL). No ignorar avisos (`policy.advisories`) en un sistema que reparte premios; correr `composer audit`. Plataforma fijada en PHP 8.3 (`composer config platform.php`).
- **Los tests corren contra PostgreSQL real y la base `gamification_test`**, fijada en `tests/TestCase.php::createApplication()`. Las variables de entorno del contenedor ganan a `phpunit.xml` aunque tenga `force="true"`: con solo eso los tests habrían usado la base de desarrollo (lo detectó `HealthTest`). No quitar esa guarda.
- `app/Engine/` es el motor **puro** (sin Laravel, BD, red ni reloj; sin `rand/mt_rand/random_int/random_bytes/shuffle`, lo verifica `NoGeneralPurposeRandomTest` con el tokenizador). El azar entra solo por la semilla, generada fuera con el CSPRNG del SO.
- `verifier/` (Python, solo stdlib, fuera del backend a propósito) es la implementación de referencia independiente. `verifier/vectors.json` es la fuente de verdad: PHP debe reproducirlos byte a byte (`VectorsTest`, que FALLA si no encuentra los vectores en vez de omitirse). Si cambia el algoritmo: `python3 -I verifier/gamif_verify.py vectors > verifier/vectors.json` y `python3 -m unittest discover verifier`.
- El control estadístico (chi-cuadrado) NO detecta el sesgo de módulo de 32 bits; lo detectan el test exacto de `UniformTest` y los vectores. Se verificó por mutación: ambos fallan si se quita el descarte.
- Las notas de `CLAUDE.md`/`AGENTS.md` que genera el esqueleto de Laravel (instalan PHP con `curl | bash`) se eliminaron a propósito.

## Estado
T1–T2, T4–T7 completadas (app Flutter: ruleta + raspa, 129 tests). SPEC-002 APROBADA; B1 (backend Laravel 13 + PostgreSQL en Docker) y B2 (motor de sorteo + verificador Python con vectores cruzados, 49 tests PHP + 22 Python) completadas. Siguiente: B3 (modelo de datos y ciclo de vida del lote), B4 (códigos), B5 (redención atómica), B6 (verificador/export), B7 (contrato OpenAPI + HttpRepository).

## Notas de juegos
- **Sonido:** `SystemFeedback` usa `HapticFeedback`/`SystemSound`. En web `SystemSound` NO suena (solo vibra en móvil). Sonido real en web = audio con assets (pendiente; Luis pidió sonido activado por defecto).
- **Validación visual con Playwright (Flutter web):** el árbol de accesibilidad se activa con `focus()` + `Enter` sobre `flt-semantics-placeholder` (el clic de ratón falla: está fuera de pantalla); `getByRole('textbox').fill()` NO llega a Flutter, usar `click()` + `keyboard.type()`. Si no hay navegador de la revisión pedida, pasar `executablePath` al Chromium ya cacheado en `~/Library/Caches/ms-playwright`.
- Ruleta: segmentos 4–12; `gameResult.segmentIndex` fuera de rango o config inválida → el juego muestra aviso y deja continuar al resultado (el premio ya está acreditado).
- **Controladores de animación:** crearlos en `initState`, nunca como `late final X = AnimationController(...)` con inicializador: con config inválida nadie los toca y Flutter los crearía por primera vez dentro de `dispose()` (error). Pasaba en ruleta y raspa.
- **Tests con temporizadores en cadena** (fundido -> pulso -> pausa): un único `pump(3 s)` no basta, los timers se crean recién cuando termina la animación anterior. Avanzar en pasos cortos (`pump(100 ms)` en bucle) o `pumpAndSettle` + `pump`.
- Raspa: trazos y máscara en coordenadas normalizadas (el avance sobrevive a un redimensionado); `scratchSegment` interpola porque un barrido rápido genera eventos táctiles muy separados.
