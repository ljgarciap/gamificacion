# Arquitectura y plan — Gamificación (borrador del Arquitecto + PM)

## Estructura (monorepo Flutter con Melos, pub workspaces)
```
Gamificacion/
├─ packages/
│  ├─ gamification_core/     Dart puro: modelos, GamificationRepository, GameModule, GameRegistry,
│  │                         tema (tokens), eventos, i18n, MockRepository. Sin dependencia de UI de juego.
│  ├─ game_roulette/         Widget + painter de la ruleta, implementa GameModule
│  ├─ game_scratch/          Raspa y Gana, implementa GameModule
│  ├─ gamification_sdk/      Fachada pública: GamificationView, GamificationConfig, HostBridge
│  │                         (callbacks Dart | postMessage web | MethodChannel nativo)
│  └─ gamification_http/     HttpRepository (cliente del backend / contrato OpenAPI)
├─ apps/
│  └─ standalone/            App móvil + web; también sirve de host de demo/iframe de prueba
├─ backend/                  (SPEC-002) FastAPI + PostgreSQL
└─ docs/                     specs, ADRs, contrato OpenAPI
```
Regla de dependencias: `game_*` → `gamification_core`; `sdk` → core + juegos; `apps` → sdk. Los juegos nunca se conocen entre sí.

## Decisiones técnicas (resueltas por el Arquitecto, dentro del diseño)
- **Estado:** Riverpod. **Navegación:** `Navigator` simple interno (sin go_router) para no pelear con rutas del host al embeber.
- **Animación:** `CustomPainter` + `AnimationController`; el juego recibe el resultado ya resuelto y solo anima hacia él. Raspa: capa de cobertura con `BlendMode.clear` y medición de porcentaje por muestreo de celdas.
- **Puente host↔Flutter:** interfaz `HostBridge` con 3 implementaciones por plataforma mediante imports condicionales (`dart:js_interop` en web, `MethodChannel`/Pigeon nativo, callbacks en paquete).
- **Redención:** `Idempotency-Key` generado en cliente por intento; el servidor persiste el resultado, así el reintento tras cierre devuelve el mismo outcome.
- **Configuración:** campaña (tema + juego + premios + textos) llega del backend; el cliente trae un default embebido solo para modo demo.
- **Web embebida:** build `--wasm`/canvaskit, sin dependencias de `dart:io`; orígenes permitidos configurables.

## ADRs a escribir
ADR-001 monorepo+Melos · ADR-002 contrato servidor-decide · ADR-003 HostBridge multi-canal · ADR-004 Riverpod.

## Desglose PM (tareas atómicas)
| # | Tarea | Rol | Depende |
|---|---|---|---|
| T1 | Verificar versión Flutter/Dart, crear monorepo + Melos + CI local de lint/test | DevOps | spec aprobada |
| T2 | `gamification_core`: modelos (Campaign, Outcome, Prize, Theme), interfaces, GameRegistry, eventos | Frontend | T1 |
| T3 | MockRepository determinista + códigos demo + contrato OpenAPI `docs/openapi.yaml` | Backend/Frontend | T2 |
| T4 | Tema por tokens + i18n es/en | Frontend | T2 |
| T5 | Flujo común: pantalla de código, errores, resultado, saldo | Frontend | T2–T4 |
| T6 | `game_roulette` (painter, animación a índice, offset aleatorio, golden tests) | Frontend | T5 |
| T7 | `game_scratch` (cobertura, umbral, revelar todo, a11y, golden tests) | Frontend | T5 |
| T8 | `gamification_sdk` + HostBridge (callbacks, postMessage web, MethodChannel stub) | Frontend | T6/T7 |
| T9 | App standalone + página HTML host de prueba con iframe | Frontend/DevOps | T8 |
| T10 | Test de integración flujo completo + escenario de cierre a mitad de juego | QA | T9 |
| T11 | Revisión Cybersecurity: bridge/origins, anti-bruteforce, token | Cybersecurity | T8 |
| T12 | Guía de embebido (web / paquete / add-to-app) + ADRs | Tech Writer | paralelo |

Gates: UX/UI (spec visual de ruleta/raspa/resultado, tomando la paleta SuperGIROS) antes de T5; Game Expert no aplica (no es CardGame; la probabilidad vive en el backend); Senior Reviewer tras T8; QA tras T10.
