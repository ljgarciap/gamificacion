# SPEC-003 · P10 — Revisión final independiente

Revisor: subagente en frío (sin el razonamiento de la implementación), 2026-10-22. Veredicto: **integrable con correcciones**. Ejecutó: suite PHP completa (824 ok / 4 omitidas, incluida la concurrencia de CA-39), 12 unitarias del panel, 62 pruebas del verificador Python, `scripts/e2e_host_sim.php` («TODO OK»), CA-40 de punta a punta con el verificador independiente, CSP sobre la imagen de producción (3/3) y 2 mutaciones (detectadas). No ejecutó la suite E2E completa.

Sin hallazgos en: login por pasos, 2FA, TOTP de un solo uso, hash señuelo, regeneración de sesión, autorización por ruta, control dual (autoaprobación, caducidad, doble ejecución, descarga única atómica), cliente (sin `innerHTML`/`localStorage`/`eval`; secretos solo en memoria), contrato OpenAPI vs rutas.

## Estado de los hallazgos

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| 1 | Alta | El despliegue no entrega avisos al host ni purga exportaciones (nadie invocaba `outbox:dispatch` ni `admin:purge-exports`) | **Corregido**: `Schedule` en `routes/console.php` (10 s / 1 min) y servicio `worker` (`schedule:work`) en `deploy/compose.example.yml` |
| 2 | Media | La imagen traía `storage/app/demo-codes.json`, scripts E2E, `.phpunit.result.cache` | **Corregido**: `.dockerignore` (`.env*`, `storage/app/**`, `scripts/`, `tests/`, `.phpunit*`, `database/*.sqlite`); verificado en la imagen |
| 3 | Media | `GAMIF_TRUSTED_PROXIES` vacío arrancaba y el bloqueo por IP se volvía global | **Corregido**: `App\Support\TrustedProxies`; en producción vacío o `*` sin confirmar impide arrancar (`none` = sin proxy). 4 pruebas unitarias; comprobado en la imagen |
| 4 | Media | El contrato con el host deduplica por `X-Gamif-Event-Id`, que no va firmado (reenvío con otro id = acreditar de nuevo) | **ABIERTO — decisión de Luis**: cambia la firma que usan los hosts (`HMAC(ts.eventId.cuerpo)`); la alternativa mínima es exigir al host deduplicar por `body.redemption_id` |
| 5 | Media | Cada GET anónimo al panel crea una fila en `sessions`, sin límite | **Mitigado**: `limit_req` 20 r/s (ráfaga 40) por IP real en ambos nginx (`REAL_IP_FROM` = red del balanceador). No se cambió el arranque de sesión (el flujo CSRF la necesita) |
| 6 | Media | La entrega única se pierde al recargar/navegar | **Mitigado**: aviso `beforeunload`, confirmación al salir de Aprobaciones y texto en el panel. Sin resolver: persistir `sha256`/`downloadUntil` para recuperar la vista tras recargar, y no destruir el archivo hasta confirmar la entrega (cambio de diseño) |
| 7 | Baja | El panel no trataba el 419 como sesión vencida | **Corregido** (interceptor + E2E; mutación detectada) |
| 8 | Baja | `batchId` con forma de UUID pero inválido daba 500 | **Corregido**: UUID estricto; prueba; mutación detectada |
| 9 | Baja | El auditor no puede solicitar `audit.export` aunque la spec lo sugiere | **ABIERTO — decisión de Luis** (ajustar la spec o dar el permiso) |
| 10 | Baja | `lang="en"`/título del HTML; inactividad fuera de `SettingsRegistry`; `SESSION_LIFETIME` engañoso; falta «imprimir» en códigos de recuperación | `lang="es"` y título: **corregidos**; `prod.env.example` aclarado. **Abiertos**: llevar la inactividad (`GAMIF_ADMIN_SESSION_IDLE`) a `SettingsRegistry` y el botón «imprimir» |
| 11 | Baja | `/` mostraba la portada de Laravel; login del panel alcanzable por la API de hosts; `tinker` en `require`; `memory_limit` × `max_children` | **Corregidos**: portada quitada, `tinker` a `require-dev` (la imagen ya no lo trae), `/admin/` limitado también en el nginx de la API. **Abierto**: separar por red el acceso a `/admin/` (depende del despliegue) y dimensionar `pm.max_children` con la RAM real |
| 12 | Baja | Con `exportTampered` el archivo en claro quedaba en disco | **Corregido** (se borra; prueba) |
| 13 | Baja | E2E combinados: el bloqueo por IP acumulado entre archivos hacía fallar pruebas | **Corregido**: `e2e_admin_users.php` limpia los contadores de acceso en cada corrida |
| — | Info | Los mensajes de `QueryException` traen los valores enlazados (hashes, TOTP cifrado) y irían al log (RNF-30/T32) | **Verificado y corregido**: se registra la consulta con marcadores; prueba con mutación |
| — | Info | T12: un superadmin puede reiniciar 2FA/contraseña de otro aprobador | Riesgo residual ya declarado; el revisor sugiere espera o doble control en esos reinicios (decisión de Luis) |

## Cobertura que el revisor echó en falta
CA-35/CA-36 solo con el script manual `e2e_host_sim.php`; UA-9 por teclado en el asistente de lote y en el diálogo de aprobación (solo axe); UA-8 con caducidad real (no simulada); CA-30/CA-33 existen sin el rótulo del criterio.
