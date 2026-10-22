# SPEC-003 · P9 — Endurecimiento (RNF-32, RNF-35)

Alcance: lo que SPEC-002 dejó para producción (D7, memoria del tope de códigos, imagen sin root, `GAMIF_TRUSTED_PROXIES`) y la CSP estricta del panel. Fecha de las mediciones: 2026-10-22, Docker Desktop (Mac Apple Silicon), PostgreSQL 16 en el mismo equipo.

## 1. Imágenes de producción

| Imagen | Archivo | Qué es |
|---|---|---|
| `app` | `backend/deploy/Dockerfile` (target `app`) | php-fpm 8.3 como usuario **10001**, sin dependencias de desarrollo, mapa de clases autoritativo, OPcache sin revalidar, `display_errors` apagado, registros a stderr |
| `api` | `backend/deploy/Dockerfile` (target `web`) | nginx sin privilegios que solo entrega `public/index.php` a php-fpm (nunca un archivo: `/.env`, `/composer.json`, `/storage/...` → 404), `nosniff`, `no-store` |
| `panel` | `admin-panel/deploy/Dockerfile` | Angular compilado + nginx sin privilegios, CSP estricta por petición, proxy de `/admin/api/` al backend (mismo origen: no hay CORS) |

Ejemplo de despliegue: `deploy/compose.example.yml` (sistema de archivos de solo lectura, `cap_drop: ALL`, `no-new-privileges`, solo `storage/` escribible, puertos solo en `127.0.0.1`). Variables: `deploy/prod.env.example`.

Comprobado ejecutando las tres imágenes con `--read-only --cap-drop ALL --security-opt no-new-privileges`: `/up` 200, panel 200, panel→API 401 sin sesión; en producción las rutas `/v1/dev/*` dan 404, la cookie sale `secure; httponly; samesite=lax`, y `id` del contenedor es `uid=10001`.

### Defectos que salieron al construirlas (y cómo se evitan)
- `bootstrap/cache/packages.php` copiado del equipo de desarrollo nombraba proveedores de desarrollo (Pail) que no existen sin `--dev`: la imagen se rompía al arrancar. Ahora se borra y se regenera con `package:discover`.
- El mapa de clases autoritativo generado **antes** de copiar el código dejaba fuera las clases de la aplicación («Invalid route action»). Se genera después de copiarlo.
- Con sistema de archivos de solo lectura, nginx necesita `/etc/nginx/conf.d` escribible (genera su configuración desde la plantilla) y Laravel `bootstrap/cache` y `storage/`: están declarados en el compose.
- `X-Forwarded-For` se reenvía con `$proxy_add_x_forwarded_for` (conserva la cadena); con `$remote_addr` el panel habría ocultado la IP real al bloqueo por IP.

## 2. CSP estricta del panel (RNF-32)

```
default-src 'none'; script-src 'self' 'nonce-<id>'; style-src 'self' 'nonce-<id>'; img-src 'self' data:; font-src 'self';
connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'
```
Sin `unsafe-inline` ni `unsafe-eval`. Más: HSTS, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, COOP/CORP `same-origin`, `Cache-Control: no-store` en la página y en la API.

- El nonce es el `$request_id` de nginx (128 bits, distinto en cada petición): nginx lo escribe en la cabecera y, con `sub_filter`, en `ngCspNonce` de `index.html`; Angular lo pone en los `<style>` que inyecta.
- Angular, por defecto, incrusta el CSS crítico y un `<script>` de carga en `index.html`: eso rompe la CSP. Se desactivó (`optimization.styles.inlineCritical=false`) en la compilación de producción.
- **Hallazgo real de la prueba:** tres atributos `style="…"` estáticos en plantillas (Usuarios, Campañas) los bloquea `style-src-attr`. Ahora son clases. Los `[style.width.%]` de las barras no violan nada (se aplican por CSSOM).
- Prueba: `e2e/csp-produccion.spec.ts` (requiere `PANEL_URL`): cabeceras, nonce distinto por petición e igual al de la página, **ninguna violación `securitypolicyviolation` ni error de consola** en las 12 pantallas ni al abrir el selector y la confirmación en línea, todo `<style>`/`<script>` en línea con nonce, y la API con `no-store`. La prueba detectó el defecto anterior antes de corregirlo.
- Toda la suite E2E se puede correr contra la imagen: `PANEL_URL=http://localhost:8097 npx playwright test`.

## 3. D7 — techo de la bitácora (canje con candado global)

Medido con procesos reales (`RedeemThroughputBenchTest`, `GAMIF_BENCH=1`): cada proceso canjea 20 códigos distintos, cada canje escribe en la bitácora encadenada.

| procesos | canjes | canjes/s | p50 ms | p95 ms |
|---|---|---|---|---|
| 1 | 20 | 373 | 1,5 | 23,7 |
| 5 | 100 | 707 | 5,6 | 25,1 |
| 10 | 200 | 929 | 7,4 | 39,1 |
| 25 | 500 | 936 | 18,9 | 66,9 |
| 50 | 1000 | 958 | 37,5 | 118,5 |

El techo es ≈ **950 canjes/s** y se alcanza con ~10 procesos; más concurrencia solo sube la latencia (cola ante el candado), sin errores y con la cadena íntegra al final. **Conclusión: no hace falta pasar la bitácora a un lote asíncrono.** Límites de la medición: base y cliente en el mismo equipo (sin latencia de red; con red el candado se retiene lo mismo, porque se toma al final de la transacción, pero cada canje tarda más en total). Una promoción de ~100 canjes/s sostenidos usa ~10 % del techo. Repetir la medición en el entorno real antes del lanzamiento.

## 4. Memoria y tiempo con el tope de 1 000 000 de códigos

`MemoryCeilingBenchTest` (`GAMIF_BENCH=1`, `php -d memory_limit=-1 vendor/bin/phpunit`, base temporal). Pico de memoria sobre una base de ~50 MB del propio marco de pruebas:

| etapa | 100 000 códigos | 1 000 000 de códigos |
|---|---|---|
| generar códigos + CSV | 4,0 s · 52 MB | **112 s** · 52 MB (por tramos: no crece) |
| comprometer | 0,1 s | 2,3 s · 52 MB |
| activar | 4,9 s · 58 MB | 27 s · 106 MB |
| revelar | 0,6 s | 5 s · 122 MB |
| exportar auditoría | 0,5 s · 68 MB | 3,8 s · **222 MB** |
| serializar la exportación | 80 MB | **306 MB** |

- Con el `memory_limit` por defecto de PHP (128 MB) el lote de 1 M **no** se completa (muere en `PrizeVault`). La imagen fija `memory_limit = 512M`: cubre el pico medido (306 MB) con 1,6× de holgura. La exportación crece con el número de códigos (guarda todo en arreglos): si se sube el tope de códigos por lote, hay que volver a medir o pasar el exportador a escritura por tramos.
- **Plazo de la petición:** generar 1 M de códigos tarda ~2 min, más que el plazo normal de 60 s. Solo la ruta `POST /admin/api/v1/approvals/{id}/execute` recibe plazo largo (420 s, en el nginx de la API y en el del panel, y `max_execution_time=420` por `PHP_VALUE` en esa ruta). El resto conserva 60 s. Alternativa si estos tiempos molestan: ejecutar esa acción en una cola (cambio de diseño, no hecho).

## 5. Lista de verificación de producción

1. `APP_ENV=production`, `APP_DEBUG=false`, `GAMIF_BEACON=drand`; **ausentes** `GAMIF_DEV_ENDPOINTS`, `GAMIF_CORS_ORIGINS`, `GAMIF_HOST_ALLOW_LOCAL`, `GAMIF_DEMO_INITIAL_BALANCE`.
2. `SESSION_SECURE_COOKIE=true` (la cookie sin `Secure` sale con HTTP; con el panel detrás de un balanceador con TLS no debe faltar). TLS termina en el balanceador; HSTS ya lo manda el panel.
3. `GAMIF_TRUSTED_PROXIES` con la red del balanceador **y** la de los contenedores nginx. Sin esto el bloqueo por IP lo comparte todo el mundo (`docs/seguridad-b8.md`, F1).
4. Secretos en un gestor; respaldo cifrado de `GAMIF_PRIZE_MAP_KEY` (perderla deja ilegibles los lotes comprometidos).
5. Publicar el ancla de la bitácora fuera de la base al comprometer cada lote (`gamification:audit:anchor`).
6. Solo el balanceador alcanza los puertos 8081 (API) y 8082 (panel); la base no publica puerto.
7. Primer superadmin por consola: `docker compose … run --rm app php artisan admin:create-superadmin …`.
8. Migraciones: `php artisan migrate --force` como paso de despliegue (no al arrancar el contenedor).
9. Repetir las mediciones de las secciones 3 y 4 en el entorno real.

## 6. Cierre de hallazgos anteriores
- `docs/seguridad-b8.md` F5 (imagen del backend como root): **cerrado** para producción (imagen sin root). F8 (`X-Content-Type-Options` en la API): **cerrado** (nginx de la API).
