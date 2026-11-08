# QA de SPEC-006A (cuentas persistentes de la muestra) — 2026-11-08

Spec: `spec-006a-cuentas-de-la-muestra.md` (APROBADA) · Diseño: `diseno-spec-006a.md` · Amplía `qa-spec-006.md`.

## Suites
| Suite | Resultado |
|---|---|
| Backend PHP | **1073 pasan**, 4 omitidas, 0 fallan (604.183 aserciones) |
| Cuentas, backend | `DemoAccountsTest` 54 (ingreso, enumeración, límites, caducidad, retención, correo cifrado, borrado), `DemoAccountsAdminTest` 13 (lista enmascarada, borrar, correo de prueba, reinicio con elección, bitácora sin secretos) |
| Concurrencia real (procesos de PostgreSQL) | 40 verificaciones simultáneas del mismo código → UNA gana; 50 pedidos simultáneos al mismo correo → UN correo; 30 ingresos simultáneos → UNA cuenta; 20 personas contra un tope de 5 → exactamente 5 |
| Flutter | core 38 · http 134 · sdk 105 · standalone 12 · `flutter analyze` y `dart format -l 120` limpios |
| Panel Angular (unitarias) | 22 de 22 (2 nuevas: parámetros de cuentas y modo del correo) |
| E2E del panel «Muestra» (`e2e/muestra.spec.ts`) | 9 de 9 (4 nuevas: bloque Cuentas con páginas y borrado, reinicio con elección obligatoria, parámetros con techos, correo de prueba inválido; accesibilidad WCAG AA) |
| **E2E del recorrido completo** (`e2e/muestra-recorrido.spec.ts`) | **1 de 1** (≈2,7 min) con drand REAL, Mailpit y el verificador independiente |

## Recorrido CA-20 en navegador real
Compose de la prueba: `docker/compose.muestra-e2e.yml` (backend + worker en modo demostración, **Mailpit** como correo simulado, API en `localhost:8025`). La prueba, además de lo de `qa-spec-006.md`:
1. Parte sin cuentas de corridas anteriores (las borra por la API del panel).
2. En la app: aviso de privacidad visible → correo único → «Revisa tu correo». Un código **errado** (`000000`) responde el mismo «no es válido o venció», se borra el campo y el foco vuelve a él.
3. **Lee el código del correo real** recibido en Mailpit (API de Mailpit) y entra: cuenta nueva con el saldo inicial del escenario.
4. Compra, saldo cargado y juega dos códigos (igual que SPEC-006).
5. **Sale y vuelve a entrar con el mismo correo** (espera el enfriamiento de 62 s: el servidor no reenvía antes de 60 s): aterriza en «Tus códigos» con **el saldo acumulado** y **los dos códigos «Jugado»**; la API de la cuenta (`/v1/demo/account`) coincide.
6. El panel lista la cuenta con el correo **enmascarado** (`e***@ejemplo.com`, nunca completo) y su saldo; la página no contiene el correo completo.
7. Apaga y **Reinicia eligiendo «Conservar»** (el botón Confirmar está deshabilitado hasta elegir): pool nuevo, **la cuenta y su saldo siguen**. Luego **borra la cuenta desde el panel** (con confirmación): 0 cuentas.
8. Verificador independiente sobre los lotes del primer pool (igual que SPEC-006).
9. Cero errores de consola salvo el 401 esperado del código errado (se exige exactamente uno).

## Hallazgos del QA y cómo se resolvieron
- **Entorno (no era un defecto):** el E2E del recorrido fallaba al encender («Apagada»): la base de desarrollo no tenía aplicada la migración de M7 (`demo_accounts`) y el panel no podía leer el estado. Se aplicó y el E2E se reescribió para cuentas.
- **PHP `$` y el salto de línea final (corregido en M7):** la mutación del chequeo de caracteres de control escapó porque `$` acepta un `\n` final; hubo un hueco real (`ana\n@…`). Expresiones con `/D` y casos nuevos.
- **Teclas perdidas al llenar campos de Flutter web (prueba corregida):** el campo recién aparecido tarda en recibir el foco; la prueba espera 400 ms y teclea con retardo. Esto llevó a una mejora de UX: tras un código errado el campo se **borra y recupera el foco** (antes quedaba el código errado y había que borrarlo dígito por dígito).
- **Contador de reenvío con reloj real (corregido):** usaba `DateTime.now()`; ahora cuenta ticks (no depende del reloj y es probable en pruebas).
- **Mailpit en solo lectura (corregido):** escribe su base en `/tmp`; la pila de despliegue le da `tmpfs: [/tmp]` (se comprobó con `docker run --read-only`).

## Verificación por mutación
- **M7 (backend):** 24 mutaciones, todas detectadas (la del chequeo de control se reforzó, ver arriba).
- **M8 (panel):** 4 de 4: Confirmar habilitado sin elegir, elección por defecto «conservar», parámetro sin validar, borrar sin llamar al servidor.
- **M9 (app):** 13 mutaciones; 11 detectadas al primer intento. Escaparon 2: (a) quitar `_codes.clear()` al salir es **equivalente** (el ingreso siguiente ya reemplaza la lista y la pantalla de acceso no la muestra; se deja como defensa en profundidad); (b) usar el saldo de la sesión en vez del de la cuenta: se agregó una prueba con saldos distintos y la mutación ya se detecta.
- **M10 (despliegue/E2E):** reiniciar borrando cuentas aunque se elija conservar → **el E2E del recorrido falla** (`accounts-total` esperaba 1 y recibe 0). La lógica de `demo-up.sh` (agrega el correo simulado si falta; suma Mailpit solo con `MAIL_HOST=mailpit`) se probó con dos `.env` de ejemplo; los compose se validaron con `docker compose config`.

## Límites conocidos (no bloqueantes)
- **Aviso de privacidad PROVISIONAL** (`demoPrivacy`): la redacción definitiva es de Luis (D5); sin casilla de aceptación porque el alcance legal es decisión de negocio.
- La sesión de la app vive en memoria: recargar la página obliga a ingresar de nuevo (con el mismo correo recupera todo). Si se pide un código antes de 60 s desde el anterior, el servidor no envía otro (sigue valiendo el primero, 10 min).
- Mailpit guarda en memoria y su bandeja solo se ve por túnel SSH al loopback del servidor; con un SMTP real se quita (ver `despliegue-demo.md`). **Un servidor real nunca debe quedar con `MAIL_MAILER=log`** (el panel avisa).
- Sin probar contra un SMTP real ni en un VPS real: se comprobará en el primer despliegue.
- El push de los commits locales sigue pendiente del cambio de cuenta de GitHub (403) y de la autorización de Luis; un push a `master` despliega por CI.
- Pendientes de negocio de Luis: D3 (registro legal / retención de auditoría), D5 (privacidad: redacción del aviso), D9 (primer superadmin).
