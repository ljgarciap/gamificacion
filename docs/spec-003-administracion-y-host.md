# SPEC-003 — Panel de administración, integración con la app host y giro extra

Estado: **APROBADA por Luis (2026-10-20, "procede")** con las propuestas por defecto en D8 (sin correo), D9 y D10 (solo español). D3 y D5 siguen abiertas (legal): no bloquean las tareas.
Origen: lo que SPEC-002 dejó explícitamente para esta etapa (§11, D2, D5, D6, D7) + decisiones de Luis 2026-10-20 (sección 2).
Depende de: SPEC-002 completa (B1–B9, `docs/qa-b9.md`, `docs/seguridad-b8.md`).
Prioridad: **después de SPEC-004** (Concéntrese y Dados, pedidos por Luis para la demo el 2026-10-20). No dependen entre sí.

## 1. Objetivo
Hoy los lotes, campañas y parámetros se manejan por línea de comandos y por configuración de entorno, y la acreditación al host es un *stub*. Esta etapa agrega:
1. Un **panel de administración web** con cuentas, roles y segundo factor, para operar campañas y lotes sin tocar la consola.
2. La **integración real con la app host**: aviso de acreditación firmado con reintentos idempotentes, y saldo leído del host, probados con un host simulador.
3. El **giro extra** como premio real (reserva dentro del lote), sin romper la verificabilidad del sorteo.
4. La **administración de parámetros** (límites, reintentos, vida de tokens) desde el panel, según la regla global de "nada de umbrales hardcodeados".

Fuera de alcance: multi-tenant por app host, analítica, gestor de secretos en AWS, nuevos juegos (Concéntrese, Dados), y las decisiones legales D3/D5 (ver §9: se diseña el mecanismo técnico, la decisión es de Luis con asesoría legal).

## 2. Decisiones de Luis (2026-10-20)
| # | Decisión |
|---|---|
| P1 | Panel en **Angular**, como cliente separado de una API `/admin` del backend Laravel. |
| P2 | Acceso con **cuentas propias + segundo factor TOTP obligatorio**. Roles: superadmin, operador, auditor. Independiente de cualquier host. |
| P3 | **Giro extra = reserva dentro del mismo lote**: un premio "giro extra" reserva casillas del propio lote para sus giros hijos (stock exacto y verificable). |
| P4 | **No existe aún un host real**: se publica el contrato del aviso y se construye un **host simulador**. Cuando exista el host real, solo se conecta. |
| P5 | **Control dual diferido a esta etapa** (SPEC-002 R3): las acciones críticas requieren la aprobación de un segundo administrador. |
| P6 | La exportación de auditoría **no se firma**; la integridad descansa en el ancla publicada (decidido en B9). |

## 3. Requerimientos funcionales

### 3.1 Cuentas y acceso
| ID | Requerimiento |
|---|---|
| RF-30 | Usuarios del panel con rol único: **superadmin** (todo, incluye usuarios y parámetros), **operador** (campañas y lotes; no usuarios ni parámetros), **auditor** (solo lectura, incluye bitácora y exportaciones). |
| RF-31 | Alta de usuarios solo por un superadmin (no hay auto-registro). El primer superadmin se crea por comando (`admin:create-superadmin`), nunca por defecto. |
| RF-32 | Inicio de sesión con correo y contraseña **más TOTP obligatorio**; códigos de recuperación de un solo uso; el superadmin puede restablecer el 2FA de otro usuario (queda en bitácora). |
| RF-33 | Contraseña mínima de 12 caracteres, verificada contra contraseñas comunes; bloqueo progresivo por intentos fallidos; sesión con vencimiento por inactividad (30 min, parametrizable) y cierre de todas las sesiones al cambiar la contraseña. |
| RF-34 | Toda acción del panel (incluidos los inicios de sesión fallidos) se registra en la **bitácora encadenada existente**, con actor `admin:<id>`. La bitácora no lleva contraseñas ni secretos. |

### 3.2 Panel
| ID | Requerimiento |
|---|---|
| RF-35 | **Campañas**: crear/editar nombre, juego, tema, textos, `usedCodeBehavior`, configuración del juego y premios (tipo, etiqueta, monto). Una campaña con un lote comprometido no cambia sus reglas ni sus premios (lo impone ya la base). |
| RF-36 | **Lotes**: crear borrador con su pool, generar códigos (descarga **una única vez**), comprometer, activar, cerrar, revelar. Cada paso muestra el estado, lo que cambia y lo que ya no se podrá cambiar. Nunca se muestra qué código tiene qué premio. |
| RF-37 | **Consulta de redenciones** y códigos por lote: estado, fecha, premio, usuario (seudónimo). Sin el mapa código→premio de los códigos no redimidos. |
| RF-38 | **Auditoría**: visor de bitácora con verificación de la cadena, exportación de auditoría de un lote revelado, generación del ancla y su descarga. |
| RF-39 | **Avisos de acreditación** (outbox): ver pendientes, enviados y fallidos; reintentar un fallido a mano; ver el último error. |
| RF-40 | **Parámetros** con alta/edición/historial (ver §3.4). |
| RF-41 | **Usuarios**: alta, cambio de rol, desactivación, restablecer 2FA (solo superadmin). |
| RF-42 | Tablero con stock restante por premio, canjes por día y avisos fallidos. |

### 3.3 Control dual (P5)
| ID | Requerimiento |
|---|---|
| RF-43 | Estas acciones requieren **solicitud y aprobación de dos usuarios distintos** (el aprobador no puede ser el solicitante): **activar un lote**, **generar/exportar los códigos en claro**, **exportar la auditoría** y **cambiar un parámetro de seguridad**. |
| RF-44 | La solicitud caduca (24 h, parametrizable), se ejecuta una sola vez y queda en bitácora con solicitante, aprobador y hora. Un solo superadmin en el sistema puede operar con el modo de emergencia explícito del RF-45. |
| RF-45 | Mientras exista **un solo usuario con permiso de aprobar**, el sistema lo advierte en el panel; no se desactiva el control en silencio. La excepción (aprobarse a uno mismo) solo existe en entornos `local`/`testing`. |

### 3.4 Parámetros
| ID | Requerimiento |
|---|---|
| RF-46 | Los valores hoy en `config/gamification.php` que son de negocio u operación (límites de intentos y bloqueos, tope de peticiones, reintentos y esperas de la outbox, vida y desfase del token, caducidad de solicitudes duales, inactividad de sesión) pasan a una **tabla parametrizada** con CRUD en el panel. El entorno queda como valor inicial. |
| RF-47 | Cada parámetro tiene tipo, mínimo y máximo (un superadmin no puede, por ejemplo, poner 0 intentos o un token de 30 días). Cada cambio guarda valor anterior, nuevo, actor y hora, y va a la bitácora. |
| RF-48 | Los parámetros se leen con caché corta: un cambio aplica en menos de 1 minuto sin reiniciar. |

### 3.5 Integración con la app host (D2)
| ID | Requerimiento |
|---|---|
| RF-49 | **Hosts registrados** (emisor, URL de callback, URL de saldo, secreto) administrados en el panel. Los secretos se guardan **cifrados** (mismo esquema que el mapa de premios) y nunca se devuelven por la API; solo se puede rotar. Durante una rotación hay dos secretos válidos a la vez. |
| RF-50 | **Aviso de acreditación firmado**: `POST` a la URL del host con cuerpo JSON (el `payload` de la outbox) y cabeceras `X-Gamif-Event-Id` (= `redemption_id`), `X-Gamif-Timestamp` y `X-Gamif-Signature` = HMAC-SHA256 de `timestamp + "." + cuerpo`. El host acepta solo marcas de tiempo de ±5 min y es **idempotente por `event-id`**. |
| RF-51 | Política de respuesta: `2xx` = entregado; `5xx`, `408`, `429` y fallos de red = reintento con la espera creciente existente; el resto de `4xx` = **fallido permanente** (no se reintenta, queda para revisión). Respeta `Retry-After`. |
| RF-52 | **Saldo del host**: `GET` firmado a la URL de saldo del host con el `sub` del usuario; el host elige el adaptador por el emisor del token (el id de usuario ya va prefijado con el emisor). Si el host no responde, la API responde un error claro y nunca un saldo inventado. |
| RF-53 | **Host simulador** (servicio en `docker compose`) que verifica la firma, aplica la idempotencia, lleva una billetera de prueba y puede simular fallos (lento, 500, 4xx) para probar los reintentos. Documento del contrato para quien implemente el host real (`docs/contrato-host.md`). |
| RF-54 | Seguridad de las URL de host: solo `https` (y `http` en `local`), sin seguir redirecciones, sin resolver a direcciones privadas, de bucle o de enlace local salvo en `local`, con tiempo máximo por llamada (SSRF). |

### 3.6 Giro extra (P3, D6)
| ID | Requerimiento |
|---|---|
| RF-55 | Un lote puede declarar **`R` casillas de reserva** (R ≥ 0). El pool tiene `N + R` premios: `N` para los códigos y `R` para giros hijos. Todo se baraja junto con el mismo algoritmo y queda dentro del compromiso. **Con `R = 0` el comportamiento y los vectores actuales no cambian ni un byte.** |
| RF-56 | Al ganar un premio "giro extra", cada giro hijo consume la **siguiente casilla de reserva** libre del lote (contador atómico) y crea una redención hija con su propio `redemptionId`, que puede ser cualquier premio, incluido otro giro extra (acotado por la reserva). |
| RF-57 | Si la reserva se agota, el giro hijo no se otorga y el sistema lo informa; el pool debe validarse al comprometer para que la reserva cubra el peor caso razonable (el panel advierte cuando `R` es menor que los giros extra que el pool puede generar). |
| RF-58 | El verificador independiente y la exportación incluyen las `R` casillas y comprueban que el conteo total es exactamente el stock. La versión del compromiso sube; los lotes anteriores siguen verificando como antes. |
| RF-59 | El cliente Flutter deja de bloquear la repetición cuando el resultado trae `extraSpins` y juega los giros hijos con la información que devuelva el servidor (nunca los decide). |

## 4. Requerimientos no funcionales
- **RNF-30** Ningún secreto, contraseña, TOTP, ni mapa código→premio sale en una respuesta de la API ni en un registro.
- **RNF-31** La API `/admin` usa un guardia propio, separado del JWT de los hosts; las dos superficies no comparten credenciales ni rutas.
- **RNF-32** El panel se sirve con CSP estricta, cookies `HttpOnly`/`Secure`/`SameSite` y protección CSRF; CORS limitado al origen del panel.
- **RNF-33** El panel cumple WCAG AA (contraste, teclado, etiquetas) y funciona en pantalla de 1280 px y en tableta.
- **RNF-34** La acreditación es "al menos una vez" y el host "una sola vez" por idempotencia: ningún reintento puede acreditar dos veces.
- **RNF-35** Hardening pendiente de SPEC-002: medir el techo de la bitácora (D7), memoria para el tope de 1 millón de códigos, imagen de producción (php-fpm + nginx, sin root) y `GAMIF_TRUSTED_PROXIES` configurado.

## 5. Matriz de permisos (propuesta)
| Acción | superadmin | operador | auditor |
|---|:--:|:--:|:--:|
| Ver campañas, lotes, redenciones, bitácora, outbox | ✔ | ✔ | ✔ |
| Crear/editar campañas y borradores de lote | ✔ | ✔ | — |
| Comprometer, cerrar y revelar un lote | ✔ | ✔ | — |
| Activar lote · generar códigos · exportar auditoría · cambiar parámetro de seguridad | ✔ (con aprobación dual) | ✔ (con aprobación dual) | exportar auditoría: no (solo consulta y verifica; la solicita un operador o superadmin) |
| Reintentar aviso fallido | ✔ | ✔ | — |
| Hosts y secretos · parámetros · usuarios | ✔ | — | — |

## 6. Riesgos
| # | Riesgo | Mitigación |
|---|---|---|
| A1 | Cuenta de administrador comprometida reparte o desvía premios | 2FA obligatorio, control dual en lo crítico, bitácora encadenada con ancla, bloqueo progresivo |
| A2 | Un operador define un pool que lo favorece | El compromiso y la fotografía del pool se publican antes de activar; el control dual aplica a activar |
| A3 | Repetición o falsificación del aviso al host | Firma HMAC con marca de tiempo (±5 min) + idempotencia por `event-id` del lado del host |
| A4 | **SSRF** por una URL de host maliciosa o mal configurada | RF-54: solo https, sin redirecciones, sin direcciones privadas, solo superadmin edita hosts |
| A5 | Fuga del secreto de un host | Cifrado en reposo, nunca se devuelve, rotación con dos secretos simultáneos |
| A6 | **El giro extra altera el motor ya auditado** | Con `R = 0` los vectores no cambian; mutación y vectores cruzados PHP↔Python obligatorios; revisión de Cybersecurity y Senior Reviewer antes de implementar |
| A7 | Reserva agotada deja un giro hijo sin premio | Validación al comprometer y advertencia en el panel; error explícito, nunca un premio inventado |
| A8 | Un solo administrador anula el control dual | RF-45: advertencia permanente; la autoaprobación solo en local/testing |

## 7. Criterios de aceptación
- CA-30 Sin sesión y sin 2FA no se accede a ninguna ruta `/admin`; un token de host no sirve en `/admin` ni al revés.
- CA-31 Cada rol puede hacer exactamente lo de la matriz (§5), probado con una prueba por celda.
- CA-32 Una acción dual no se ejecuta con el mismo usuario como solicitante y aprobador, ni con una aprobación caducada, ni dos veces.
- CA-33 Cada acción del panel deja una entrada en la bitácora y la cadena sigue íntegra; no aparece ningún secreto en ella.
- CA-34 Un cambio de parámetro respeta mínimo/máximo, queda con historial y se aplica en menos de 1 minuto.
- CA-35 El aviso al host lleva firma válida; el simulador rechaza una firma alterada, una marca de tiempo vieja y un duplicado (idempotencia).
- CA-36 Reintentos: ante `5xx`/timeouts se reintenta con espera creciente y el simulador acredita **una sola vez**; un `4xx` permanente no se reintenta.
- CA-37 Las URL de host a direcciones privadas, con redirección o con `http` fuera de `local` se rechazan.
- CA-38 Con `R = 0` todos los vectores y exportaciones existentes verifican sin cambios; con `R > 0` el verificador reproduce las `N + R` asignaciones y detecta una alterada en un solo registro.
- CA-39 Bajo concurrencia real, `K` giros hijos simultáneos consumen exactamente `K` casillas distintas de reserva y nunca más de `R`.
- CA-40 E2E en navegador: un operador crea una campaña y un lote, otro administrador aprueba la activación y un jugador canjea un código; el simulador acredita y el saldo se actualiza.

## 8. Desglose propuesto (PM lo ajusta)
| Id | Tarea | Rol | Depende de |
|---|---|---|---|
| C1 | Esta spec | Analista | — |
| C2 | Spec de diseño del panel (flujos de lote y control dual, pantallas, estados vacíos y de error) | UX/UI | C1 |
| C3 | Diseño técnico: guardia `/admin`, modelo de datos, contrato del host, **cambio del motor para `R`** | Arquitecto | C1 |
| C4 | Modelo de amenazas: acceso admin, control dual, SSRF, secretos de host, cambio de motor | Cybersecurity | C3 |
| P1 | Usuarios, roles, contraseñas, 2FA TOTP, sesiones y bitácora de accesos | Backend | C3, C4 |
| P2 | API `/admin` de campañas, premios y consulta (permisos por rol) | Backend | P1 |
| P3 | Ciclo de vida de lotes por API + **control dual** | Backend | P1 |
| P4 | Parámetros con CRUD, rangos, historial y caché | Backend | P1 |
| P5 | Hosts, callback firmado, política de reintentos, saldo del host, **host simulador** y `contrato-host.md` | Backend/DevOps | C3, C4 |
| P6 | **Giro extra**: reserva en el motor, redención hija, verificador, API y cliente Flutter | Backend/Frontend | C3, C4 |
| P7 | Panel Angular: base, login con 2FA, guardas por rol | Frontend | C2, P1 |
| P8 | Panel: campañas, lotes y control dual, redenciones, auditoría, outbox, parámetros, usuarios, tablero | Frontend | P2–P5, P7 |
| P9 | Hardening de SPEC-002 (D7, memoria, imagen de producción) | DevOps/Backend | — |
| P10 | Senior Reviewer · Cybersecurity · QA (incluye E2E del CA-40 y concurrencia del CA-39) | Senior/QA | todas |

Orden sugerido: C2–C4 en paralelo → P1 → P2/P3/P4/P5 → P6 (con su revisión de seguridad aparte, antes de integrar) → P7/P8 → P9 → P10.

## 9. Decisiones pendientes (Luis)
- **D3 — ABIERTA (legal):** ¿la regulación de juegos promocionales en Colombia exige registrar la campaña y conservar la auditoría un tiempo mínimo? Hasta saberlo, la bitácora no se purga.
- **D5 — ABIERTA (legal/privacidad):** `redemptions` guarda el id de usuario del host y es inmutable. Mecanismo propuesto si hay que poder anonimizar: guardar `user_id` cifrado con una clave por usuario destruible (*crypto-shredding*). Se implementa solo si Luis lo decide tras la asesoría legal; no está en las tareas de arriba.
- **D8 — Correo del panel:** el restablecimiento de contraseña y las invitaciones necesitan enviar correo. ¿Hay un servicio de correo (SMTP, SES) disponible? Propuesta mínima: sin correo; el superadmin entrega una contraseña temporal de un solo uso por un canal aparte.
- **D9 — Quién es el primer superadmin y cuántos habrá:** el control dual exige al menos 2 usuarios con permiso de aprobar (RF-45).
- **D10 — Idioma del panel:** propuesta: español únicamente.
