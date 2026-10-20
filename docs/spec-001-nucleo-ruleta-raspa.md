# SPEC-001 — Núcleo multiplataforma + Ruleta + Raspa y Gana (MVP)

Estado: **BORRADOR — pendiente de aprobación de Luis**
Origen: artifact "Gira y Gana" (SuperGIROS Móvil) + decisiones de Luis 2026-10-20.

## 1. Objetivo
Plataforma de minijuegos promocionales en Flutter que funcione como (a) app independiente,
(b) widget/paquete dentro de otras apps Flutter, (c) vista web embebida (iframe/WebView) y
(d) módulo add-to-app en apps nativas. Juegos del roadmap: Ruleta, Raspa y Gana, Concéntrese,
Tirar dados. **MVP: núcleo + Ruleta + Raspa y Gana.**

## 2. Contrato común a todos los juegos (del artifact)
1. Usuario ingresa un **código promocional** → el cliente lo envía al backend.
2. El **servidor decide el resultado** (nunca el cliente). El cliente solo lo representa.
3. El código se **redime atómicamente antes** de animar (cierre de app a mitad de juego no permite reclamo doble).
4. **Un uso por código.** Reintentar un código usado devuelve el mismo resultado ya asignado (idempotente), sin volver a jugar.
5. Premio monetario → evento de saldo hacia el host; el saldo visible se actualiza al instante.
6. Existen resultados "sin premio" (campañas con probabilidad real).
7. Todo lo variable (premios, montos, probabilidades, textos, tema, juego asignado) viene de la **campaña**, nunca hardcodeado (regla global: parametrización).

## 3. Requerimientos funcionales
| ID | Requerimiento |
|---|---|
| RF-01 | Pantalla de ingreso de código con validación, mensajes de error claros (inválido, usado, vencido, campaña inactiva) y enlace de ayuda configurable. |
| RF-02 | El código determina el **juego** (ruleta, raspa, …) y la **campaña**; el cliente enruta al módulo correspondiente. |
| RF-03 | Ruleta: N segmentos (4–12) definidos por campaña; el backend devuelve `segmentIndex`; animación ease-out con vueltas completas y offset aleatorio dentro del segmento; duración configurable (def. 4.6 s). |
| RF-04 | Raspa y Gana: cuadrícula (def. 3×3) de símbolos predeterminada por el backend; el usuario raspa con dedo/mouse; auto-revelado al superar umbral (def. 60 %); botón "revelar todo" accesible. |
| RF-05 | Pantalla de resultado: premio o "sin premio", acciones continuar / volver, configurables por el host. |
| RF-06 | Saldo en cabecera opcional (el host puede ocultarlo) con actualización en vivo. |
| RF-07 | Tema configurable (colores, tipografía, logo, textos); paleta por defecto = SuperGIROS (#0B4EF0 / #14235C / #FFC800 / #21C1F3 / #F2F4F7). |
| RF-08 | Modo demo/sandbox con códigos de prueba que fuerzan cada resultado (solo en builds no productivos). |
| RF-09 | i18n: es (default), en. |
| RF-10 | Canal de eventos hacia el host: `ready`, `codeValidated`, `gameStarted`, `prizeWon`, `balanceChanged`, `closed`, `error`. |
| RF-11 | Registro de juegos extensible: agregar un juego nuevo = un módulo que implementa `GameModule`, sin tocar el núcleo. |

## 4. Modos de integración (decididos por Luis: los 4)
| Modo | Mecanismo | Entrega |
|---|---|---|
| Standalone móvil/web | App propia | APK/IPA + build web |
| Paquete Flutter | `GamificationView` widget + `GamificationConfig` | package versionado |
| Web embebida | Flutter Web hospedado; config por query params + `postMessage` bidireccional | URL + guía de embebido |
| Add-to-app nativo | Flutter module con `MethodChannel`/Pigeon | guía Android/iOS |

Autenticación en embebido: el host entrega un **token de sesión corto** (JWT) y `userId`; el
backend lo valida. El cliente nunca recibe secretos de larga vida.

## 5. Backend (decidido: ambos)
Interfaz `GamificationRepository` (Dart) con 2 adaptadores:
- **HttpRepository** → backend propio (FastAPI + PostgreSQL): campañas, códigos, premios, redención atómica, panel de admin (fase posterior).
- **Adaptador del host** → el host implementa el contrato (OpenAPI publicado).
- **MockRepository** → desarrollo/tests/demo, determinista.

Contrato mínimo (borrador):
- `POST /v1/codes/redeem` `{code}` + `Idempotency-Key` → `{redemptionId, game, campaign{theme,config}, outcome{...}}`
- `GET /v1/balance` → `{amount, currency}`
La redención y la asignación de premio ocurren en una sola transacción servidor.

## 6. Requerimientos no funcionales
- RNF-01 Resultado no manipulable desde el cliente (el cliente no conoce probabilidades ni puede elegir el índice).
- RNF-02 Anti fuerza bruta: rate limit por usuario/IP y bloqueo progresivo en intentos de código inválidos (backend).
- RNF-03 Web: `frame-ancestors`/lista de orígenes permitidos; `postMessage` valida `origin` en ambos sentidos.
- RNF-04 60 fps en gama media; animaciones con `CustomPainter`, sin dependencias de imagen pesadas.
- RNF-05 Accesibilidad: semántica, contraste AA, alternativa sin gestos (botón revelar), respeta "reducir movimiento".
- RNF-06 Responsive: móvil, tablet, web; funciona en viewport embebido pequeño.
- RNF-07 Cobertura de pruebas: ≥ 80 % en núcleo; golden tests de ruleta y raspa; test de integración del flujo completo con mock.

## 7. Criterios de aceptación (MVP)
- CA-1 Con mock, código válido de ruleta → gira y se detiene exactamente en el segmento indicado por el servidor, en los N segmentos configurados.
- CA-2 Mismo código dos veces → el segundo intento no vuelve a jugar; muestra el resultado previo o "código ya usado" según configuración de campaña.
- CA-3 Cerrar la app durante la animación y reabrir → el código figura como redimido y el premio no se pierde ni se duplica.
- CA-4 Raspa: revelar ≥ umbral dispara resultado; resultado coincide con la cuadrícula del servidor.
- CA-5 Premio monetario emite `balanceChanged` y el saldo de cabecera se actualiza.
- CA-6 Cambiar colores/textos/premios solo por configuración, sin recompilar el código de juego.
- CA-7 El mismo código fuente corre en Android, iOS y Web; el build web funciona dentro de un iframe de prueba y se comunica por `postMessage`.
- CA-8 Un juego de prueba trivial se registra como `GameModule` sin modificar el núcleo.

## 8. Fuera de alcance del MVP
Concéntrese, Dados, panel de administración, analítica, notificaciones push, backend de producción
(el MVP usa Mock + contrato OpenAPI; el FastAPI real es SPEC-002).

## 9. Decisiones de negocio (Luis, 2026-10-20) — spec APROBADA
1. Código ya usado: **configurable por campaña** (`usedCodeBehavior: showPrevious | rejectOnly`). Afecta CA-2.
2. Tipos de premio en MVP: **dinero, cupón, puntos, giros extra y sin premio**. `Prize.type` es un enum extensible; el host decide qué hacer con `coupon`/`points` vía evento `prizeWon`. "Giro extra" = el outcome trae `extraSpins: n` y el flujo permite reintentar sin nuevo código (el servidor emite un nuevo `redemptionId` hijo por cada giro extra).
3. Moneda: **fija COP** (formato `$ 1.234.567`, sin decimales). Sin configuración de moneda por campaña.
4. Branding por defecto: **SuperGIROS** (tema base); sigue siendo reemplazable por configuración (RF-07).
