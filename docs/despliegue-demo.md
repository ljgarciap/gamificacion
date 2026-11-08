# Despliegue de demostración (local, teléfono y VPS)

Un mismo código sirve a los tres casos. Lo que cambia es **cómo la app independiente obtiene un usuario y los códigos**:

| Modo | Rutas | Cuándo existen | Para qué |
|---|---|---|---|
| Desarrollo local | `/v1/dev/token`, `/v1/dev/demo-codes` | Solo con `APP_ENV=local` (o testing) **y** `GAMIF_DEV_ENDPOINTS=true`. En producción no existen aunque la variable esté puesta | Tu máquina |
| **Muestra real** (SPEC-006) | `GET /v1/demo/status`, `POST /v1/demo/session`, `POST /v1/demo/purchase` | Siempre registradas; con la muestra apagada, `session` y `purchase` responden 404 y `status` dice `{"enabled": false}`. La enciende y apaga el **superadmin desde el panel** | Un servidor de demostración (VPS) |

La app (web) lleva fija solo la URL de la API: al arrancar pregunta `/v1/demo/status` y, si la muestra está encendida, ofrece el recorrido completo (acceso → tienda → pago simulado → saldo cargado → códigos → juego), con un aviso permanente «MUESTRA · datos simulados». No hay variable de compilación para el modo. `/v1/dev/*` nunca se reabre en producción. El antiguo modo `GAMIF_DEMO_MODE` (`/v1/demo/token`, `/v1/demo/codes`) **se retiró**: eso era un emisor de tokens abierto.

> **Seguridad de la muestra.** `POST /v1/demo/session` emite tokens de un usuario SIMULADO (prefijo del panel + identificador aleatorio) con vida de hasta 10 minutos, y esos usuarios solo pueden canjear códigos de campañas de demostración que la propia compra les entregó. Aun así es un emisor abierto, por eso: (1) solo existe si el servidor declara `GAMIF_DEMO_ALLOWED=true` y el superadmin la enciende desde el panel (con control de caducidad, 24 h por defecto), (2) va detrás de la compuerta con clave del proxy (`deploy/Caddyfile.demo`), (3) emisor y clave propios de la demo, (4) **sin campañas reales** en el mismo servidor (el interruptor se niega a encender si las hay) y (5) límite de peticiones por IP (20/min sesión y compra, 60/min estado).

## Muestra desde el panel («Muestra», solo superadmin)
1. Entra al panel → **Muestra**. Define el escenario: usuario simulado, catálogo (hasta 5 productos), reglas de carga (% de saldo, compra mínima, un código por cada $X, topes por compra y por sesión) y la **mezcla de premios** por juego (cuántos códigos con premio por monto y cuántos sin premio). **Guardar configuración.**
2. **Sembrar**: el worker crea un lote por juego con el pool exacto (compromiso + baliza drand real; tarda ~1 min por juego). El estado muestra el avance.
3. **Encender**. A partir de ahí la app ofrece el recorrido. **Apagar** es inmediato; **Reiniciar** apaga, retira los códigos (también los entregados) y siembra un pool nuevo.
4. Todo cambio (configuración, encendido, apagado, siembra) queda en la bitácora de administración con quién y cuándo.

Requisitos del servidor: `GAMIF_DEMO_ALLOWED=true`, `GAMIF_BALANCE=stub`, el emisor `standalone-dev` (HS256) en `GAMIF_HOSTS` y el servicio `worker` corriendo (`schedule:work`: ejecuta cada minuto la caducidad y las corridas pedidas desde el panel).

## 1. En tu máquina (lo de siempre)
```bash
docker compose up -d db backend panel web     # panel :4200, juegos :8080, API :8000
docker compose exec backend php artisan gamification:demo:seed        # códigos de prueba para el flujo de desarrollo local
docker compose exec backend php artisan admin:create-superadmin tu@correo.com --name="Tu Nombre"
```

## 2. Verlo en el teléfono (misma red Wi‑Fi)
La web lleva fija la URL de la API al compilar, y en el teléfono `localhost` es el propio teléfono.
```bash
IP=$(ipconfig getifaddr en0)                      # p. ej. 192.168.1.20
# 1) permitir ese origen en el backend (backend/.env):
#    GAMIF_CORS_ORIGINS=http://localhost:8080,http://localhost:8081,http://$IP:8080
docker compose up -d backend
# 2) recompilar la web apuntando a la API por la IP:
GAMIF_API_URL=http://$IP:8000 docker compose up -d --build web
```
En el teléfono abre `http://<IP>:8080`. Sirve para los juegos; el panel exige HTTPS fuera de `localhost`.

## 3. VPS (demostración para otras personas)
Requisitos: un servidor Linux (2 vCPU, 4 GB) con Docker y Docker Compose, un dominio con **dos** nombres apuntando a su IP (`demo.tudominio.com` para juegos y API, `admin.tudominio.com` para el panel) y los puertos 80/443 abiertos.

1. Trae el repositorio al servidor y entra a la carpeta.
2. `cp deploy/demo.env.example deploy/demo.env` y llénalo:
   - `DEMO_DOMAIN`, `ADMIN_DOMAIN`, `DEMO_USER`.
   - `DEMO_PASSWORD_HASH`: `docker run --rm caddy:2-alpine caddy hash-password --plaintext 'tu-clave'` (entre comillas simples en el archivo).
   - `DB_PASSWORD` (inventa una), `APP_KEY` (`echo "base64:$(openssl rand -base64 32)"`).
   - `GAMIF_PRIZE_MAP_KEY`, `GAMIF_CODE_PEPPER`, `GAMIF_ADMIN_KEY`: tres valores de `openssl rand -base64 32`. **Respalda `GAMIF_PRIZE_MAP_KEY`**: sin ella los lotes comprometidos quedan ilegibles.
   - En `GAMIF_HOSTS`, pon como `key` otro `openssl rand -base64 32` (clave del emisor de demostración).
   - `GAMIF_DEMO_ALLOWED=true` y `GAMIF_BALANCE=stub` (ya vienen en el ejemplo).
3. Levanta: `docker compose -f deploy/compose.demo.yml --env-file deploy/demo.env up -d --build` (la primera vez tarda varios minutos: compila la web de Flutter).
4. Prepara la base y los datos:
   ```bash
   C="docker compose -f deploy/compose.demo.yml --env-file deploy/demo.env"
   $C run --rm app php artisan migrate --force
   $C exec app php artisan admin:create-superadmin tu@correo.com --name="Tu Nombre"
   ```
5. Entra a `https://admin.tudominio.com` (panel), configura, siembra y enciende la **Muestra**; luego abre `https://demo.tudominio.com` (el navegador pide usuario y clave de la compuerta).

**Atajo automatizado:** `deploy/demo-up.sh <IP> [correo del superadmin]`, ejecutado EN el servidor desde la carpeta del proyecto, hace todo lo anterior: genera `deploy/demo.env` con secretos aleatorios (solo si no existe: nunca los pisa), construye las imágenes de una en una, levanta, migra, crea el superadmin y adopta una sola vez las campañas de demostración anteriores. La muestra la siembra el worker cuando el superadmin lo pide desde el panel. Las credenciales quedan en `deploy/demo-credentials.txt` (0600). Sin dominio propio usa `demo.<IP>.sslip.io` y `admin.<IP>.sslip.io`, que resuelven a esa IP y permiten el certificado automático; con dominio propio define `DEMO_DOMAIN` y `ADMIN_DOMAIN` antes de correrlo. Para subir el código sin Git: `git ls-files -co --exclude-standard | tar -czf - -T - | ssh root@<IP> 'mkdir -p /opt/gamificacion && tar -xzf - -C /opt/gamificacion'`.

Desplegado el 2026-10-22 en un VPS de 1 CPU / 3,8 GB (Ubuntu 26.04, Docker 29): con 2 GB de swap compiló Flutter y Angular sin problemas; los certificados de Let's Encrypt se obtuvieron al arrancar.

**Cómo funciona la compuerta:** Caddy pide usuario y clave para la web y para las llamadas de la API que **no** traen `Authorization: Bearer …` (pedir token, listar códigos). Las llamadas con un token ya emitido pasan y las valida la API. Es lo que permite que la app funcione tras pasar la compuerta (el navegador reenvía la clave a su mismo origen) sin dejar el emisor de tokens abierto.

**Mantenimiento:**
- Los códigos de la muestra se gastan al jugarlos. Para un pool nuevo usa **Reiniciar** en el panel (los lotes comprometidos no se borran: se cierran y se siembran otros).
- Actualizar: `git pull` y el comando `up -d --build` del paso 3; `migrate --force` si hay migraciones.
- El panel de producción es el de siempre (contraseña + 2FA); recuerda `docs/hardening-p9.md` y `docs/revision-p10.md` para el resto de la lista de verificación.

## Pruebas de este modo
Backend: `tests/Feature/Demo/DemoShopTest.php` y `tests/Feature/Concurrency/DemoPurchaseConcurrencyTest.php` (sesión, compra, sorteo, barrera de canje), `tests/Feature/Admin/DemoSwitchTest.php` (interruptor), `tests/Feature/Dev/LegacyDemoRoutesTest.php` (el emisor abierto antiguo ya no existe) y `DemoAdoptLegacyTest.php`. Flutter: `demo_api_test.dart`, `demo_contract_test.dart`, `demo_journey_test.dart` y `apps/standalone/test/widget_test.dart`. Panel: `admin-panel/e2e/muestra.spec.ts`. Recorrido completo panel → app: `admin-panel/e2e/muestra-recorrido.spec.ts`. Informe: `docs/qa-spec-006.md`. La compuerta de Caddy se comprobó contra un Caddy real: sin clave ni token → 401; con token → pasa; con clave → pasa.
