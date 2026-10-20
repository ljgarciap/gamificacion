# Modelo de amenazas de SPEC-003 (Cybersecurity, C4)

Revisa `diseno-spec-003.md` **antes** de escribir código. Activos a proteger: (1) que ningún actor interno o externo pueda orientar premios en su favor, (2) los secretos (clave del mapa, pepper, secretos de host y TOTP), (3) la integridad de la bitácora, (4) la disponibilidad del canje, (5) las cuentas de administración.
Escala: **A**lta (bloquea producción si no se mitiga), **M**edia, **B**aja.

## 1. Cuentas y sesiones
| # | Amenaza | Sev. | Mitigación exigida (tarea) |
|---|---|:--:|---|
| T1 | Fuerza bruta o *credential stuffing* contra el login | A | Bloqueo progresivo por cuenta **y** por IP, parametrizado con suelos duros; respuesta idéntica en todo fallo y mismo tiempo (hash señuelo) (P1) |
| T2 | Robo de contraseña sin 2FA | A | TOTP obligatorio antes de cualquier ruta salvo `/auth/*`; sesión sin `mfa_verified_at` no avanza (P1) |
| T3 | Repetición de un código TOTP observado | M | `totp_last_step`: un paso se acepta una sola vez; ventana ±1 (P1) |
| T4 | Códigos de recuperación débiles o reutilizables | M | Aleatorios de 80 bits, de un solo uso, guardados solo como huella HMAC; su uso queda en bitácora y avisa en el panel (P1) |
| T5 | Fijación o robo de sesión | A | Regenerar el id al autenticar y al verificar 2FA; cookie `HttpOnly`+`Secure`+`SameSite=Lax`; vencimiento por inactividad; invalidar sesiones al cambiar contraseña o 2FA (P1) |
| T6 | CSRF contra acciones críticas | A | Token CSRF en toda petición que cambia estado; las acciones críticas además exigen TOTP fresco (P1, P3) |
| T7 | Escalada de privilegios (operador hace lo del superadmin; IDOR) | A | Permiso declarado por ruta, verificado en servidor; prueba por cada celda de la matriz y prueba de arquitectura de rutas sin permiso (P2) |
| T8 | Enumeración de usuarios por el login o el restablecimiento | M | Respuestas idénticas; el restablecimiento de 2FA lo hace un superadmin, no un flujo público (P1) |
| T9 | Primer superadmin por defecto o creado por una ruta pública | A | Solo por comando de consola; sin credenciales sembradas; contraseña temporal de un solo uso con cambio obligatorio (P1) |
| T10 | XSS en el panel con textos de campaña o de usuarios | A | Sin `innerHTML`; escape por defecto de Angular; CSP estricta sin `unsafe-inline`; validación de longitud y charset en el servidor (P7/P8) |

## 2. Control dual y parámetros
| # | Amenaza | Sev. | Mitigación exigida |
|---|---|:--:|---|
| T11 | Autoaprobación (un mismo usuario solicita y aprueba) | A | CHECK de base `approved_by <> requested_by` + prueba de rechazo por la base (P3) |
| T12 | Dos cuentas del mismo humano o dos cuentas comprometidas | A | **Riesgo residual declarado**: el control dual no protege contra colusión. Mitigación: alta de usuarios solo por superadmin con bitácora, aviso permanente cuando haya menos de 2 aprobadores, y revisión periódica del listado (proceso) |
| T13 | Cambiar los `params` después de la aprobación (aprobar el lote A, ejecutar el B) | A | `params` inmutables por trigger; la ejecución usa los guardados, no los de la petición (P3) |
| T14 | Doble ejecución por carrera o repetición | A | `FOR UPDATE` + estado de un solo sentido + `executed_at` *write-once*; prueba de concurrencia real (P3) |
| T15 | Aprobación vieja usada tarde | M | Caducidad parametrizada, comprobada en la ejecución, no solo al aprobar (P3) |
| T16 | Bajar un umbral de seguridad para facilitar un ataque (intentos, vida del token) | A | Mínimos y máximos duros en código + control dual para parámetros de seguridad + historial de solo agregar (P4) |
| T17 | Un parámetro mal formado rompe el canje (disponibilidad) | M | Validación de tipo y rango al escribir; al leer, un valor inválido cae al valor por defecto y se registra (P4) |

## 3. Integración con el host
| # | Amenaza | Sev. | Mitigación exigida |
|---|---|:--:|---|
| T18 | **SSRF**: la URL de host apunta a la red interna, metadatos de nube o `localhost` | A | `HostUrlGuard`: https, sin credenciales, IP privadas/bucle/enlace local/metadatos rechazadas (v4 y v6, incluidas formas `::ffff:`), **conexión fijada a la IP validada** (anti *DNS rebinding*), sin redirecciones, tope de tiempo y tamaño; solo superadmin edita hosts. Pruebas con todas esas formas (P5) |
| T19 | Falsificar el aviso o repetirlo contra el host | A | HMAC-SHA256 sobre `timestamp.cuerpo`, ventana de ±5 min, `event-id` idempotente del lado del host; el simulador prueba firma alterada, marca vieja y duplicado (P5) |
| T20 | Fuga o reutilización del secreto de host | A | Cifrado con `SecretBox` ligado a la fila; nunca sale por la API; rotación con dos secretos simultáneos; secretos distintos por host (P5) |
| T21 | El host (o quien lo suplante) manipula el saldo mostrado | M | La respuesta de saldo va firmada y se valida; ante fallo, error explícito (no un valor por defecto) (P5) |
| T22 | Un host lento o malicioso degrada el canje | A | La entrega es **asíncrona por la outbox** (no en la petición del jugador); el saldo tiene tiempo máximo corto y *circuit breaker* parametrizado (P5) |
| T23 | Respuestas del host con contenido enorme o lento (consumo de recursos) | M | Tope de bytes y de tiempo en el cliente HTTP (P5) |
| T24 | Fugas por el mensaje de error del host en `last_error` | B | Recortar y no guardar cuerpos de respuesta; revisar en P5 (hallazgo F7 de B8) |

## 4. Giro extra (cambio del motor)
| # | Amenaza | Sev. | Mitigación exigida |
|---|---|:--:|---|
| T25 | Un cambio en el motor rompe la verificabilidad ya publicada | A | Con `R = 0` los vectores no cambian ni un byte (prueba de vectores obligatoria); dominio de compromiso distinto para `R > 0`; vectores cruzados PHP↔Python nuevos; **mutación** sobre el verificador (P6) |
| T26 | Elegir qué casilla de reserva se consume para favorecerse | A | Orden **determinista** por `idx` (siguiente libre) decidido por el servidor; el jugador no elige; la reserva está barajada y comprometida (P6) |
| T27 | Agotar la reserva a propósito (denegación de giros a otros) | M | Los giros hijos solo salen de una redención padre ganadora de **ese** usuario, con límite `extraSpins`; error explícito al agotarse (P6) |
| T28 | Consumo doble o fuera de orden bajo concurrencia | A | `FOR UPDATE SKIP LOCKED` + `consumed_at` *write-once*; prueba con procesos reales (CA-39) (P6) |
| T29 | Reserva mal dimensionada deja premios sin entregar | M | Validación al comprometer y advertencia en el panel (P6) |

## 5. Plataforma
| # | Amenaza | Sev. | Mitigación exigida |
|---|---|:--:|---|
| T30 | Dependencia nueva comprometida (Angular, paquetes de npm) | M | Versiones fijas y verificadas, `npm audit`/`composer audit` en cada bloque; **sin librería de TOTP** (implementación propia con vectores RFC) |
| T31 | Inyección de CSV en descargas (celdas que empiezan por `=`, `+`, `-`, `@`) | B | Los CSV de códigos son generados por el sistema (alfabeto controlado); en exportaciones con texto libre se neutralizan |
| T32 | Registro con datos sensibles (contraseñas, TOTP, secretos) | A | Prueba que recorre la bitácora y los logs tras flujos completos y no encuentra secretos; nunca se registran cuerpos de login |
| T33 | Panel y API de jugadores comparten superficie | M | Guardias, prefijos y CORS separados; un JWT de host no sirve en `/admin` ni al revés (CA-30) |

## 6. Riesgos residuales que acepta Luis (para registrar)
- **Colusión** de dos administradores o compromiso de dos cuentas (T12): el control dual la dificulta, no la impide.
- **Operador interno conocedor del mapa**: sigue mitigado por cifrado + bitácora + ancla publicada, no eliminado (R3 de SPEC-002).
- **Sin correo** (D8): el restablecimiento depende de un superadmin; si hay un solo superadmin y pierde su 2FA y sus códigos, se recupera por consola con acceso al servidor.

## 7. Qué debe revisar el Senior Reviewer/Cybersecurity en cada bloque
P1: T1–T9. P3: T11–T15. P4: T16–T17. P5: T18–T24 (incluye pruebas SSRF con todas las formas). **P6: T25–T29 antes de integrar.** P7/P8: T10 y T33.
