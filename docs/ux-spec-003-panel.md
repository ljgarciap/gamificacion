# UX/UI SPEC-003 — Panel de administración (tarea C2)

Estado: **borrador para aprobación de Luis.** Cubre P7 (base, acceso, guardas) y P8 (pantallas). Fuente funcional: `docs/spec-003-administracion-y-host.md` (RF-30…RF-48, RNF-32/33) y contrato `docs/admin-api-v1.md`. Solo español (D10). Pantalla objetivo 1280 px, usable en tableta (≥ 768 px); en móvil no es objetivo, pero no debe romperse.

## 1. Principios
1. **Lo irreversible se dice antes, no después.** Cada paso que no se puede deshacer (comprometer, activar, cerrar, revelar, descargar códigos) muestra *qué cambia* y *qué ya no se podrá cambiar* y pide confirmación explícita.
2. **El secreto se muestra una vez y se avisa.** Contraseña temporal, secreto TOTP, códigos de recuperación, secreto de host y CSV de códigos: pantalla con aviso "no se volverá a mostrar", botón copiar/descargar y casilla "Ya lo guardé" que habilita cerrar.
3. **La interfaz refleja permisos, el servidor los impone.** Lo que el rol no puede hacer no se dibuja (menú) o se muestra deshabilitado con motivo (botón sobre un dato visible). Un `403` igualmente se maneja.
4. **Nunca se muestra qué código tiene qué premio** ni se infiere (búsqueda de código sin canjear: "premio oculto hasta revelar").
5. **Estados explícitos:** cargando (esqueleto), vacío (con la acción siguiente), error (mensaje del servidor + reintentar), sin permiso. Nada de pantallas en blanco.
6. Misma identidad visual que la app de jugadores (tokens de `ux-spec-001`), con sobriedad: es una herramienta de trabajo, no una promoción.

## 2. Tokens y componentes
Reutiliza los tokens de `ux-spec-001` (primary `#0B4EF0`, navy `#14235C`, accent `#FFC800`, background `#F2F4F7`, surface `#FFFFFF`, error `#D32F2F`, success `#1B9E5A`) más:
| Token | Valor | Uso |
|---|---|---|
| warning | `#B26A00` sobre `#FFF4DB` | avisos de control dual, pendientes que caducan |
| danger-surface | `#FDECEC` | confirmaciones destructivas |
| muted | `#5B6478` | texto secundario (AA sobre blanco: 5.9:1) |
Tipografía Inter 14/16 px; tablas densas 13 px. Radio 12 en tarjetas, 8 en controles. Espaciado base 8.

Componentes compartidos: **Shell** (barra lateral + cabecera), **Tabla** (orden, paginación del servidor, fila expandible), **Insignia de estado** (texto + icono + color, nunca solo color), **Diálogo de confirmación** (título, consecuencias en lista, campo de confirmación escrita para lo irreversible), **Diálogo de secreto de una vez**, **Banner** (info/aviso/error), **Campo de código TOTP** (6 dígitos, `inputmode=numeric`, `autocomplete=one-time-code`), **Estado vacío**, **Esqueleto**.

## 3. Estructura y navegación
Barra lateral fija (colapsable en tableta) y cabecera con el nombre, el rol y "Cerrar sesión". Elementos del menú según permisos (`/auth/me` → `permissions`):

| Entrada | Ruta | Permiso | Roles |
|---|---|---|---|
| Resumen | `/` | `dashboard.view` | todos |
| Campañas | `/campanas` | `campaigns.view` | todos (escribir: superadmin, operador) |
| Lotes | `/lotes` | `batches.view` | todos (escribir: superadmin, operador) |
| Aprobaciones | `/aprobaciones` (insignia con pendientes) | `approvals.view` | todos (solicitar/aprobar: superadmin, operador) |
| Avisos al host | `/avisos` | `outbox.view` | todos (reintentar: superadmin, operador) |
| Buscar código | `/codigos` | `batches.view` | todos |
| Hosts | `/hosts` | `hosts.manage` | superadmin |
| Parámetros | `/parametros` | `settings.manage` | superadmin |
| Usuarios | `/usuarios` | `users.manage` | superadmin |
| Mi cuenta | `/cuenta` | `account.self` | todos |

Guardas: sin sesión completa → `/acceso`; ruta sin permiso → pantalla "No tienes acceso a esta sección" (sin revelar si existe). Banner global permanente cuando `fewApprovers` sea verdadero (RF-45): "Solo hay una persona que puede aprobar: el control dual no puede cumplirse. Crea otro operador o superadmin."
Sesión: aviso 2 minutos antes del vencimiento por inactividad (30 min parametrizable) con "Seguir conectado"; al vencer, se vuelve a `/acceso` conservando la ruta de destino.

## 4. Acceso y cuenta (P7)
Una sola pantalla `/acceso` con pasos (el servidor dice cuál sigue con `next`); un indicador "Paso x de y" y foco automático en el primer campo.
1. **Correo y contraseña.** Error único para toda falla ("Correo o contraseña incorrectos"); nunca se distingue bloqueo, cuenta desactivada ni correo inexistente. Con `429`: "Demasiados intentos. Espera {retryAfter} s" con cuenta regresiva.
2. **Cambio de contraseña temporal** (`next: password`): actual, nueva y repetir; la política se muestra *antes* de escribir (12–72 caracteres, sin el correo, no común) y el `422 weakPassword` dice qué falta. Medidor solo informativo.
3. **Enrolar 2FA** (`next: enroll`): código QR (generado en el cliente a partir de `uri`, sin servicios externos) + secreto en Base32 para escribir a mano; campo de 6 dígitos para confirmar. Luego **diálogo de códigos de recuperación** (10, formato `XXXX-XXXX-XXXX-XXXX`): copiar, descargar `.txt`, imprimir, casilla "Los guardé en un lugar seguro" obligatoria.
4. **Código 2FA** (`next: mfa`): 6 dígitos; enlace "Usar un código de recuperación" cambia el campo al formato largo y advierte "Cada código sirve una sola vez; te quedan los demás".
Mi cuenta: nombre y rol (lectura), cambiar contraseña (cierra las demás sesiones: se avisa antes). Cerrar sesión en la cabecera.

## 5. Resumen (RF-42)
Tarjetas: campañas, lotes por estado, **códigos activos canjeados / total** (barra con porcentaje), canjes totales y últimas 24 h, **avisos al host** pendientes / enviados / fallidos (rojo si hay fallidos, con enlace filtrado) y el más antiguo pendiente, aprobaciones pendientes y "esperando ejecución" (enlace), administradores.
Pendiente de API (ver §12): stock restante por premio y canjes por día; hoy `GET /dashboard` solo trae conteos. El diseño reserva el espacio (dos gráficos bajo las tarjetas), pero no se implementan hasta tener el dato.

## 6. Campañas y premios (RF-35)
**Lista:** tabla (nombre, juego, comportamiento de código usado, premios, lotes, insignia *Congelada*, actualizada). Filtro por juego. Botón "Nueva campaña" (solo con `campaigns.manage`).
**Detalle/edición** en pestañas: *General* (nombre, juego, título, comportamiento de código usado) · *Tema* (editor guiado de colores y textos con vista previa; "Ver JSON" avanzado) · *Juego* (configuración según `gameType`: ruleta = segmentos 4–12 con el premio de cada uno; raspa = cuadrícula; concéntrese = símbolos; dados = combinaciones por premio) · *Premios* (tabla con tipo, etiqueta, monto COP, meta; para `extraSpin` el campo "Giros por premio" 1–10 con ayuda "necesita reserva en el lote").
- **Congelación:** con `frozen` los campos que impone la base (`gameType`, comportamiento, configuración del juego, premios usados) se muestran deshabilitados con el motivo "Tiene un lote comprometido" y una insignia; el título, el tema y los premios no usados siguen editables. Un `409 campaignFrozen` residual se muestra junto al campo.
- Los errores `422` se asocian al campo; la configuración del juego se valida a fondo **al comprometer el lote** y la ayuda lo dice.
- Premios: dinero y puntos exigen monto entero ≥ 0 (COP sin decimales, formato `$ 50.000`); "Eliminar" con confirmación; `prizeId` solo al crear (no se edita).
- No hay "Eliminar campaña" (la API no lo permite).

## 7. Lotes y control dual (RF-36, RF-37, RF-43)
Es la pantalla más delicada. El ciclo de vida se muestra siempre como **línea de pasos**: `Borrador → Con códigos → Comprometido → Activo → Cerrado → Revelado`, con el actual resaltado y los siguientes atenuados.

**Lista:** filtros por campaña y estado; columnas nombre, campaña, estado, códigos (generados/total), canjeados (barra), reserva, vigencia, fechas.

**Crear borrador (asistente de 3 pasos):**
1. Campaña y nombre, vigencia opcional (`redeemableUntil`).
2. **Pool**: una fila por premio (premio de la campaña, cantidad). Totales en vivo: *códigos N*, *casillas de reserva R*, *posiciones N + R*. Si algún premio es `extraSpin`, aparece el campo **Reserva (R)** con el mínimo calculado `Σ cantidad × giros`; por debajo, error en línea ("La reserva no cubre el peor caso: mínimo {x}"); por encima del mínimo se muestra cuántas casillas quizá nunca se entreguen. Con R = 0 y giro extra en el pool, el botón se bloquea con el motivo.
3. **Revisión**: resumen del pool y frase fija "Después de comprometer, el pool, el mazo y el número de códigos no se podrán cambiar."

**Detalle del lote** (cabecera con estado, línea de pasos y la acción disponible; pestañas *Resumen · Compromiso · Canjes · Reserva (si R > 0)*):

| Estado | Acción principal | Pantalla de confirmación (qué cambia / qué ya no se podrá) |
|---|---|---|
| Borrador sin códigos | **Generar códigos** → control dual `codes.generate` (prefijo) | "Se creará un archivo con los N códigos en claro. Se entrega **una sola vez** y se destruye tras la descarga o a los {export_ttl} min. Es la única copia." |
| Borrador con códigos | **Comprometer** | "Se fija el mazo con una baliza pública (drand). Ya no se podrán cambiar pool, reserva, premios usados ni la configuración del juego." Muestra el recibo (`commitment`, `chainHash`, `beaconRound`, `codesDigest`) con botón copiar. Si la baliza no está lista (`503`/`beaconNotReady`): "Reintentaremos" y no se pierde nada |
| Comprometido | **Solicitar activación** → control dual `batch.activate` | "Una segunda persona debe aprobarla con un código nuevo. Al activar, los códigos empiezan a valer." |
| Activo | **Cerrar** | "Se detienen los canjes. **Los giros pendientes caducan** ({pendingSpins})." Antes de confirmar se muestran los giros pendientes. Confirmación escrita (nombre del lote) |
| Cerrado | **Revelar** | "Se publica la semilla; cualquiera podrá verificar el sorteo. No se puede deshacer." Confirmación escrita |
| Revelado | **Exportar auditoría** → control dual `audit.export` | Descarga única del JSON del verificador, con SHA-256 y **ancla** para publicar fuera de la base |

- **Pestaña Compromiso:** datos públicos del compromiso y, tras revelar, la semilla; enlace a la guía de verificación de terceros. Nunca el mapa código→premio.
- **Pestaña Canjes:** tabla paginada (200 por página) con usuario seudónimo, premio, posición y fecha; con reserva, los giros hijos se distinguen en un `spinsPlayed` aparte (no se mezclan con los canjes de código).
- **Pestaña Reserva:** casillas consumidas / R, giros pendientes (con aviso "caducan al cerrar") y casillas sin consumir al cierre.
- **Stock por premio:** tabla premio · en el pool · canjeados (`redeemedByPrize`) · restantes (no visible: el restante exacto se deduce y se muestra solo como cifra, sin mapa por código).

**Descarga única (patrón compartido, códigos y auditoría):** tras ejecutar, diálogo con cuenta regresiva hasta `downloadUntil`, botón "Descargar", y al terminar el navegador compara el SHA-256 recibido (`X-Content-SHA256`) con el esperado y muestra ✓ o un error rojo "El archivo no coincide: no lo uses". Segunda descarga o vencimiento → `410 gone`: "Ya no está disponible; para obtener códigos hay que recrear el lote."

## 8. Aprobaciones (control dual, RF-43…RF-45)
Pantalla `/aprobaciones` con pestañas **Esperan mi aprobación · Mis solicitudes · Esperan ejecución · Historial**.
Cada fila: acción legible ("Activar el lote *Promo Navidad*"), parámetros inmutables mostrados completos, solicitante, caducidad con cuenta regresiva (aviso naranja < 2 h) y estado (`pendiente`, `aprobada`, `ejecutada`, `caducada`).
- **Aprobar:** diálogo que repite lo que se aprueba (parámetros reales, no un resumen) y pide un **código TOTP nuevo**: "Usa un código que no hayas usado para entrar (espera al siguiente si es el mismo)". Mensajes: `selfApproval` ("No puedes aprobar tu propia solicitud"), `invalidCode`, `expired`.
- **Ejecutar:** solo el solicitante; botón visible tras aprobar. `beaconNotReady` en la activación: "La baliza aún no publica la ronda; la aprobación sigue vigente. Reintenta en unos segundos" (botón reintentar, sin pedir otra aprobación).
- **Solicitar** desde el contexto (lote, parámetro de seguridad): crea la solicitud y lleva a esta pantalla con un aviso "Pídele a otra persona que la apruebe".
- Si `fewApprovers`: el banner global y, al solicitar, una nota "Ahora mismo nadie más puede aprobarla".

## 9. Avisos al host / outbox (RF-39)
Tabla con filtro por estado (pendiente, enviado, fallido) y contadores arriba; columnas: canje, usuario, premio y tipo, intentos, próximo intento, último error (truncado, expandible). **Reintentar** solo en filas `failed` (operador/superadmin), con la nota "El host pudo haber recibido ya el aviso: debe tratarlo como idempotente". Auditor: sin botón.

## 10. Buscar código (RF-37)
Campo único que acepta el formato de una persona (minúsculas, guiones, O/0, I/L/1). Se envía por POST y **no** se guarda en el historial del navegador ni en el estado de la URL. Resultado: lote, estado, posición, si fue canjeado, cuándo y por quién (seudónimo) y premio; si no se ha canjeado, "Premio oculto hasta revelar el lote". Mensaje fijo de que la búsqueda queda en la bitácora. `429`: cuenta regresiva ("30 búsquedas por minuto").

## 11. Hosts, parámetros y usuarios (solo superadmin)
**Hosts (RF-49, RF-54):** tabla (emisor, URL de callback, URL de saldo, activo, "secreto anterior vigente"). Alta: emisor (fijo después), dos URL con la regla visible (https, dominio público) y error del servidor por campo; al crear, **diálogo de secreto de una vez**. *Rotar secreto*: confirmación que explica que habrá dos secretos válidos a la vez y muestra el nuevo una vez; *Retirar secreto anterior* aparece mientras exista uno. Un host no se borra: "Desactivar".
**Parámetros (RF-46…RF-48):** tabla agrupada por tema (acceso, redención, outbox, token, control dual, sesión) con etiqueta, valor actual, unidad, rango, valor por defecto e insignia *Modificado*. Edición en línea con validación de rango; un parámetro con insignia *Seguridad* no se edita aquí: el botón dice "Solicitar cambio (requiere aprobación)" y crea la solicitud `setting.change`. "Historial" abre un panel lateral (anterior → nuevo, quién, cuándo). Aviso "Aplica en menos de 1 minuto".
**Usuarios (RF-41):** tabla (correo, nombre, rol, activo, 2FA enrolado, debe cambiar contraseña, último acceso). Alta: correo, nombre, rol → **contraseña temporal una vez**. Acciones por fila: cambiar rol, desactivar/activar (ambas cierran sus sesiones: se avisa), restablecer 2FA, restablecer contraseña (otra temporal una vez). `409 lastSuperadmin`: "No puedes dejar el sistema sin un superadmin activo". No puedes desactivarte ni cambiarte el rol a ti mismo desde aquí (se evita el error común).

## 12. Brechas de la API que esta spec detecta
Para decidir antes de P8 (no cambian el diseño, cambian el trabajo de backend):
1. **Visor de bitácora y verificación de la cadena (RF-38):** no existe ruta (`GET /audit`, `POST /audit/verify`); hoy solo hay CLI. Propuesta: listado paginado filtrable (acción, sujeto, actor, fecha) + verificación del encadenado por tramos, ambos de solo lectura (permiso nuevo `audit.view`: todos los roles, ya que el auditor debe poder leerla).
2. **Generar y descargar el ancla (RF-38):** solo CLI; el `audit.export` ya devuelve `anchor`. Propuesta: mostrarla en la entrega de la exportación y un `GET /audit/anchor` de solo lectura.
3. **Tablero (RF-42):** falta *stock restante por premio* y *canjes por día* en `GET /dashboard`.
4. **Contrato máquina-legible** (`openapi-admin.yaml`, anunciado en `admin-api-v1.md`): no existe; el cliente Angular se tipará a mano contra el markdown. Propuesta: generarlo antes de P8 y validarlo con una prueba de contrato como ya se hace con `/v1`.

## 13. Accesibilidad y calidad (RNF-33)
- WCAG AA: contraste ≥ 4.5:1, foco visible, orden de tabulación lógico, todos los controles con etiqueta; diálogos con trampa de foco y `Esc`; anuncios `aria-live` para errores y resultados de acciones; tablas con encabezados asociados.
- Insignias y errores con texto e icono, no solo color.
- Teclado completo en el asistente de lote y en el diálogo de aprobación.
- Formato COP `$ 50.000`, fechas en hora local con zona visible (`America/Bogota`), UTC al pasar el cursor.

## 14. Seguridad del cliente (RNF-32)
CSP estricta (sin `unsafe-inline`, sin orígenes de terceros), sin `localStorage` para nada sensible, cookies solo de sesión, el token CSRF se lee de la cookie `XSRF-TOKEN` y se envía en `X-XSRF-TOKEN`. Los secretos mostrados una vez viven solo en memoria del componente y se borran al cerrar el diálogo. QR generado localmente. Ningún secreto, código ni TOTP en la URL, el historial, los registros de consola ni la telemetría.

## 15. Criterios de aceptación de la UI (para Playwright)
- UA-1 Un operador completa el recorrido de CA-40 desde el navegador: crea campaña y lote (con reserva), genera códigos (aprobado por un segundo usuario), compromete, solicita y consigue la activación, cierra y revela.
- UA-2 El mismo usuario no puede aprobar su propia solicitud: el mensaje aparece y la solicitud sigue pendiente.
- UA-3 Un auditor ve todo y no tiene ninguna acción de escritura (ni en el menú ni como botón).
- UA-4 Secretos de una vez: tras cerrar el diálogo, no hay forma de recuperarlos desde la interfaz.
- UA-5 Un campo de campaña congelada está deshabilitado y explica el motivo.
- UA-6 Un parámetro de seguridad crea una solicitud, no se edita en línea.
- UA-7 Descarga única: la segunda falla con `410` y la interfaz lo explica; el hash coincide en la primera.
- UA-8 Sesión caducada: el aviso previo aparece y, al vencer, se vuelve al acceso sin perder los datos de la ruta.
- UA-9 Navegación completa solo con teclado en acceso, asistente de lote y aprobación; sin violaciones graves de axe.
- UA-10 Capturas de cada pantalla en 1280 px y 768 px sin desbordes.

## 16. Decisiones de Luis (2026-10-21)
1. **Tablero:** se amplía `GET /dashboard` con stock restante por premio y canjes por día (backend, antes de P8).
2. **Bitácora y ancla:** se agregan rutas de solo lectura (listado filtrable, verificación de la cadena por tramos, ancla) con permiso nuevo `audit.view` para todos los roles (backend, antes de P8; incluye su fila en `AdminPermissionsTest`).
3. **Interfaz:** Angular Material, tematizado con los tokens de §2.
4. Pendiente de técnica (la resuelvo yo): contrato `openapi-admin.yaml` con prueba de contrato, antes de tipar el cliente (§12.4).
