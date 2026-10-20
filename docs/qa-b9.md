# QA y revisión B9 (SPEC-002) — 2026-10-20

## Resultado de las suites

| Suite | Resultado |
|---|---|
| Backend PHP (PostgreSQL real, concurrencia real con `pcntl_fork`) | 402 pasan, 1 omitida a propósito (regenera el fixture del verificador) |
| Verificador Python (solo biblioteca estándar) | 35 de 35; vectores sin cambios |
| Flutter | core 35 · http 84 · sdk 55 · standalone 6 · game_roulette 27 · game_scratch 34 |
| `flutter analyze` (6 paquetes) | sin avisos |
| `composer audit` | sin avisos de seguridad |

## E2E en navegador real (Playwright, Chromium)

Web en :8080 + backend en :8000, con códigos de demostración reales y la baliza local. Ejecutado contra la pila de Docker, no contra mocks.

- **Ruleta ($ 10.000):** token de desarrollo (200) → saldo (200) → canje (200) → pantalla «¡Ganaste! $ 10.000 · Se acreditó a tu saldo» → saldo 130.000 → 140.000. El reintento del mismo código devuelve 409 y la pantalla muestra «Este código ya fue utilizado» (regla `rejectOnly`).
- **Raspa y Gana (sin premio):** canje 200 → «Revelar todo» → resultado. Repetir el código devuelve 200 con `alreadyPlayed: true` y el mismo `redemptionId` (regla `showPrevious`).
- CORS funciona desde el navegador (sin errores de consola; el único es el 409 esperado).
- Capturas en el directorio temporal de la sesión; los scripts no se versionan (no hay Playwright en el repo).

## Cobertura ya existente (no repetida aquí)

- Estadística del sorteo: `UniformTest`, `ShufflerTest`, `PoolAndAssignerTest` (el sesgo de módulo lo detectan el test exacto y los vectores, no el chi-cuadrado).
- Concurrencia: `RedeemConcurrencyTest` con procesos reales, comprobando el estado final de la base.
- Contrato: `OpenApiContractTest` y `ContractExamplesTest` comparan `docs/openapi.yaml` con el código.

## Hallazgos de B9

| # | Severidad | Hallazgo |
|---|---|---|
| Q1 | Baja | `dart format` cambiaría 46 de 58 archivos Dart: el proyecto escribe con líneas anchas y el formateador usa 80 por defecto. Ya ocurría antes de B7. |
| Q2 | Baja | En la primera carga de la web, el navegador reporta `ERR_ABORTED` en `GET /v1/balance` y `/v1/dev/demo-codes` (se reintentan y terminan en 200). Parece una petición doble cancelada al reconstruir el widget; sin efecto visible. |
| Q3 | Info | No hay E2E automatizado en el repositorio (solo scripts manuales). |
| Q4 | Info | La Raspa se probó en navegador con «Revelar todo»; el raspado con el dedo/ratón lo cubren los tests de widget, no el E2E. |

Sin hallazgos bloqueantes en el diff acumulado de B6–B8.
