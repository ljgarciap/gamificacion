# SPEC-002 — Motor de asignación de premios y backend

Estado: **APROBADA por Luis 2026-10-20**
Origen: pedido de Luis 2026-10-20 ("lógica para la asignación de premios y garantizar la aleatoriedad").
Decisiones tomadas: premios **pre-asignados** al generar los códigos · **stock limitado** por premio ·
aleatoriedad **verificable por terceros** (compromiso/semilla + baliza pública) · **backend real Laravel 11 + PostgreSQL**
empezando por el motor · la **app host acredita** el premio al recibir nuestro aviso firmado (D1 y D2 resueltas, ver §12).

## 1. Qué se puede y qué no se puede "garantizar"
No existe "aleatoriedad garantizada" como absoluto. Lo que sí se garantiza y se prueba:

| Propiedad | Cómo se logra | Cómo se demuestra |
|---|---|---|
| **Impredecible** | Entropía del sistema operativo (`os.urandom` / `secrets`), nunca `random` (Mersenne Twister es predecible) | Revisión de código + test que prohíbe importar `random` en el módulo del motor |
| **Uniforme** (sin sesgo) | Fisher-Yates + enteros uniformes por *rejection sampling* (sin `% n` sesgado) | Test exacto del muestreador + test estadístico (chi-cuadrado) sobre miles de barajadas |
| **Distribución exacta** | El pool tiene exactamente las cantidades definidas (stock); se baraja el pool, no se sortea cada código | Test: conteos por premio == stock, siempre |
| **Inmutable** | El lote se *compromete* (hash publicado) antes de activarse; tras activarse no se puede tocar | Restricciones en BD + bitácora encadenada |
| **Verificable** | Al cerrar la campaña se revela la semilla; cualquiera recalcula la baraja y la compara | Verificador independiente y publicado, con vectores de prueba |

**Límite honesto:** el compromiso/semilla prueba que **nadie cambió el resultado después** de comprometerlo y que la baraja
salió de la semilla. **No** prueba por sí solo que un operador interno no conozca de antemano qué códigos ganan (ver §7, R3).

## 2. Modelo de decisión (por qué pre-asignado)
El azar ocurre **una sola vez, al crear el lote**. Al redimir NO hay sorteo: se consulta el premio que ese código ya tiene.
Consecuencias buenas: la distribución es exacta, nunca se paga de más, no hay carreras de stock y la redención es una
búsqueda atómica. Es lo que describe el artifact original ("el premio que ese código ya tenía asignado").

## 3. Ciclo de vida de un lote
```
borrador ──commit──▶ comprometido ──activar──▶ activo ──cerrar──▶ cerrado ──revelar──▶ revelado
 (editable)          (hash público,            (redimible)       (no redimible)         (semilla pública)
                      inmutable)
```
1. **Borrador:** el administrador define el pool: lista `(premio, cantidad)` + cuántos códigos "sin premio". `N` = total de códigos.
2. **Generación de códigos:** se crean `N` códigos aleatorios (CSPRNG). Se guarda solo su **huella** (HMAC con pepper del servidor); el texto plano se entrega **una única vez** (exportación para distribuir) y no se almacena.
3. **Comprometer:** el servidor genera una semilla secreta `S` (32 bytes, CSPRNG), fija la **ronda de baliza** `R` (la primera ronda de drand
   posterior a `ahora + margen`, margen por defecto 60 s) y calcula el compromiso
   `C = SHA256("gamif/v1/commit" ‖ batch_id ‖ SHA256(pool_canónico) ‖ codes_digest ‖ chain_hash ‖ be64(R) ‖ S)`.
   Publica `C`, el pool, `codes_digest`, `chain_hash` y `R` (**no** `S`). **`R` va dentro de `C`**: el operador no puede escoger después una ronda conveniente.
4. **Asignar:** cuando drand publica la ronda `R`, se obtiene su valor aleatorio `B` y se calcula `S' = HMAC-SHA256(clave = S, "gamif/v1/mix" ‖ B ‖ batch_id)`.
   Se baraja el pool con el flujo determinista de `S'` y el índice `i` del código recibe `pool_barajado[i]`. Si drand no responde, la activación **espera y reintenta**; nunca se cae a una semilla sin baliza.
5. **Activar:** el lote queda inmutable y redimible. Se guarda `B` (valor de la ronda) para auditoría.
6. **Cerrar:** termina la ventana de redención (o se agotan los códigos).
7. **Revelar:** se publica `S`. Cualquiera verifica: (a) `C` se reproduce con `S`, pool, `codes_digest`, `chain_hash` y `R`; (b) consulta por su cuenta a drand
   el valor de la ronda `R` y comprueba que coincide con `B`; (c) recalcula `S'` y la baraja y la compara con las asignaciones usadas; (d) los conteos coinciden con el stock.

> *Por qué la baliza (D1 = sí):* sin ella el operador podría probar semillas hasta lograr una baraja favorable. Con ella, `S` queda comprometida **antes** de que exista `B`,
> y `B` es impredecible y público: ninguna de las dos partes controla el resultado final. Validación de la firma BLS de la ronda: la hace el verificador independiente; el servidor
> registra `chain_hash`, `R` y `B` y puede verificar la firma con una librería de drand si se decide (no bloquea la etapa 1).

## 4. Algoritmo (especificación exacta, para que el verificador sea independiente)
- **Codificación:** `‖` es concatenación de bytes; los campos de longitud variable (`batch_id`, `chain_hash`, `B`) se prefijan con su largo en 4 bytes big-endian para evitar ambigüedades; `be64(x)` es un entero de 8 bytes big-endian.
- **Flujo determinista (DRBG):** bloque `i` = `HMAC-SHA256(clave = S', mensaje = "gamif/v1/shuffle" ‖ be64(i))`; los bytes se consumen en orden.
- **Entero uniforme en `[0, n)`:** leer 4 bytes como `x` (uint32 big-endian); `límite = (2³² // n) · n`; si `x ≥ límite` descartar y leer otros 4; devolver `x mod n`. (Elimina el sesgo del módulo.)
- **Baraja (Durstenfeld):** para `i` de `N-1` a `1`: `j = uniforme(i+1)`; intercambiar `a[i]` y `a[j]`. `a` parte del pool expandido en orden canónico.
- **Dos implementaciones independientes:** el motor del backend (PHP) y el **verificador de referencia** (Python, sin dependencias, en `verifier/`). Los **vectores de prueba**
  (semilla → baraja esperada, versionados en el repo) los genera el verificador y el backend debe reproducirlos byte a byte: si dos implementaciones escritas por separado coinciden, la especificación es inequívoca.
- El módulo del motor es **puro** (sin red, sin BD, sin reloj): entra `(pool, semilla)` y sale la baraja. Eso lo hace totalmente testeable.

## 5. Redención (cuando el usuario juega)
- `POST /v1/codes/redeem` (contrato de SPEC-001) → busca la huella del código; `UPDATE … SET redeemed_at = now() WHERE code_hash = ? AND redeemed_at IS NULL RETURNING …` (**atómico**: con 100 peticiones simultáneas del mismo código, exactamente una gana).
- **Idempotencia:** unicidad `(usuario, Idempotency-Key)`; el reintento devuelve la misma redención.
- **Presentación del resultado (la decide el servidor y se persiste para poder repetirla idéntica):**
  - *Ruleta:* si el premio aparece en varios segmentos (ej. dos "Sin premio"), el servidor elige uno al azar (CSPRNG) y guarda `segmentIndex`.
  - *Raspa:* el servidor construye la cuadrícula: premio ⇒ 3 símbolos del premio + relleno sin ningún otro símbolo repetido 3 veces; sin premio ⇒ ningún símbolo 3 veces. Posiciones al azar (CSPRNG). Se guarda la `grid`.
- **Acreditación del premio** (dinero, puntos, cupón): se registra en una *outbox* y se notifica a la app host (callback firmado, reintentos idempotentes). En esta etapa: adaptador *stub*; la integración con el host real es SPEC-003.

## 6. Requerimientos
| ID | Requerimiento |
|---|---|
| RF-20 | CRUD de campañas y lotes en borrador; el pool define premios, cantidades y "sin premio" (parámetros, no constantes) |
| RF-21 | Generar `N` códigos únicos de ≥ 50 bits de entropía, alfabeto sin caracteres ambiguos, con dígito verificador para rechazar errores de tipeo antes de consultar la BD |
| RF-22 | Comprometer, activar, cerrar y revelar según §3; transiciones inválidas rechazadas |
| RF-23 | Asignación por baraja determinista según §4; stock exacto garantizado |
| RF-24 | Redención atómica e idempotente según §5, con errores tipados (`invalidCode`, `alreadyUsed`, `expired`, `campaignInactive`, `rateLimited`) |
| RF-25 | Bitácora **append-only encadenada** (cada entrada incluye el hash de la anterior): creación, compromiso, activación, redenciones, cierre, revelación, accesos de administradores |
| RF-26 | Exportación de auditoría del lote (JSON canónico firmado) y **verificador independiente** con guía para terceros |
| RF-27 | Panel/API de estadísticas: stock restante por premio, redimidos, sin premio — **sin** exponer el mapa código↔premio |
| RF-28 | El cliente Flutter usa `HttpRepository` contra este backend; el mock queda solo para desarrollo |

## 7. Modelo de amenazas (Ciberseguridad)
| # | Amenaza | Mitigación |
|---|---|---|
| R1 | Adivinar códigos (fuerza bruta) | ≥ 50 bits + dígito verificador; límite por usuario/IP, bloqueo progresivo, respuesta de tiempo constante; alertas por patrones |
| R2 | Fuga de la BD revela códigos sin redimir | Solo se guardan huellas HMAC con *pepper* fuera de la BD; texto plano exportado una vez |
| R3 | **Operador interno conoce qué códigos ganan** y los desvía | El mapa código↔premio se guarda **cifrado** (clave fuera de la BD); la API de administración nunca lista asignaciones; accesos auditados; control dual para exportar. *No eliminable del todo*: se mitiga y se audita. Detección: la bitácora permite revisar quién redimió los premios altos |
| R4 | Manipular el resultado tras el compromiso | Compromiso publicado + restricciones en BD que impiden modificar un lote `comprometido`+; bitácora encadenada |
| R5 | Escoger la semilla a conveniencia | Baliza pública mezclada tras el compromiso (D1) |
| R6 | Doble redención por concurrencia o reintentos | `UPDATE … WHERE redeemed_at IS NULL` atómico + idempotencia; test de concurrencia |
| R7 | Cliente manipulado dicta el resultado | El cliente nunca envía ni conoce el resultado (ya garantizado en SPEC-001) |
| R8 | Reutilizar la baraja revelada de una campaña en otra | La derivación incluye `batch_id`; un lote = una semilla |
| R9 | Token del host falsificado/robado | JWT corto validado contra la clave del host; el `userId` viene del token, no del cuerpo |
| R10 | Enumerar/forzar el panel de administración | Autenticación fuerte, roles (superadmin/operador/auditor), límites de tasa, todo auditado |

## 8. Pila técnica (decisiones del Arquitecto)
**Laravel 11 (PHP 8.3) + PostgreSQL 16** (Luis 2026-10-20: el panel de admin con roles, las colas y el límite de tasa ya vienen en el framework y coincide con 4 proyectos del workspace).
Servicios `backend` y `db` en `docker-compose` (mismo flujo local que el front) · migraciones y Eloquent · colas para la *outbox* y los reintentos hacia la host · Pest/PHPUnit.
`pepper`, clave de cifrado y JWT desde variables de entorno ahora, gestor de secretos (AWS) después.
Estructura: `backend/app/Engine/` (motor **puro**: sin Laravel, sin BD, sin red, sin reloj; solo `random_bytes` y `hash_hmac`), `Domain/`, `Http/`.
`verifier/` (Python, sin dependencias) vive en la raíz del repo, fuera del backend, a propósito: debe ser independiente.
Los tests prohíben usar `rand`, `mt_rand`, `random_int` y `shuffle` dentro de `Engine/` (el estadístico depende solo de bytes del DRBG propio).

## 9. Criterios de aceptación
- CA-20 Para cualquier pool válido, los conteos asignados == stock (property test con muchos pools aleatorios).
- CA-21 Misma `S'` y pool ⇒ misma baraja (determinismo); distinta `S'` ⇒ barajas distintas.
- CA-22 Muestreador uniforme sin sesgo: para `n` que no es potencia de 2, todos los resultados posibles son igual de frecuentes. Test **exacto** (no estadístico): se enumeran todas las entradas de un muestreador de rango reducido y se cuenta cuántas producen cada resultado.
- CA-23 Test estadístico (chi-cuadrado, umbral fijo e independiente de la semilla): cada premio es equiprobable en cada posición.
- CA-24 El código del motor no usa funciones de azar de propósito general (`rand`, `mt_rand`, `random_int`, `shuffle`); test automático sobre el código fuente.
- CA-24b Las dos implementaciones (PHP y el verificador Python) producen exactamente los mismos vectores de prueba.
- CA-25 100 redenciones concurrentes del mismo código ⇒ exactamente 1 éxito, 99 `alreadyUsed`.
- CA-26 Mismo `Idempotency-Key` ⇒ misma respuesta, sin segundo premio ni segundo evento de acreditación.
- CA-27 Verificador independiente: dado `S`, pool y exportación, reproduce las asignaciones y detecta una exportación alterada en un solo registro.
- CA-28 Un lote `comprometido` no se puede modificar (a nivel de BD, no solo de API).
- CA-29 Un caso de extremo a extremo: lote → códigos → activar → redimir desde la app Flutter por `HttpRepository` → saldo/evento; luego cerrar, revelar y verificar.

## 10. Desglose PM
| # | Tarea | Rol | Depende |
|---|---|---|---|
| B1 | Esqueleto `backend/` (FastAPI, Postgres, Alembic, servicios en compose, salud, pruebas en Docker) | DevOps/Backend | spec aprobada |
| B2 | Motor puro: DRBG, entero uniforme, baraja, validación de pool, compromiso + vectores + tests exactos y estadísticos | Backend | B1 |
| B3 | Modelo de datos y ciclo de vida del lote (migraciones, restricciones de inmutabilidad, bitácora encadenada, huellas, cifrado del mapa) | Backend | B2 |
| B4 | Generación de códigos con dígito verificador + exportación única | Backend | B3 |
| B5 | Redención atómica e idempotente + presentación (segmento / cuadrícula) + límites de tasa + outbox stub | Backend | B3 |
| B6 | Verificador independiente + guía para terceros + exportación de auditoría | Backend/Tech Writer | B3 |
| B7 | Contrato OpenAPI (cierra T3) y `HttpRepository` en Flutter; app standalone configurable mock/real | Frontend | B5 |
| B8 | Revisión de seguridad | Cybersecurity | B5 |
| B9 | Senior Reviewer · QA (concurrencia, estadística, E2E) | Senior/QA | B7 |

## 11. Fuera de alcance de esta etapa
Panel de administración visual (SPEC-003) · integración real de acreditación con la app host (SPEC-003) · gestor de secretos en AWS · multi-tenant por app host · analítica.

## 12. Decisiones pendientes (Luis)
- **D1 — RESUELTA (sí):** se mezcla una baliza pública externa (drand); la ronda `R` va dentro del compromiso.
- **D2 — RESUELTA:** la **app host acredita** el dinero/puntos al recibir nuestro aviso firmado (callback con reintentos idempotentes). Esta etapa usa un *stub*; la integración real es SPEC-003.
- **D3 — ABIERTA (legal, fuera de lo técnico):** ¿la regulación aplicable (juegos promocionales en Colombia) exige registrar o autorizar la campaña y conservar la auditoría un tiempo mínimo? Define la retención de la bitácora y quién firma la revelación. Hasta saberlo, la bitácora **no se purga**.
- **D4 — Por defecto (cambiable sin impacto):** código visible `PREFIJO-XXXX-XXXX`: 10 caracteres aleatorios (alfabeto Crockford sin `0 O 1 I L U`, ≈ 50 bits) + 1 de verificación.
