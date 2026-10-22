# Despliegue de demostración (local, teléfono y VPS)

Un mismo código sirve a los tres casos. Lo que cambia es **cómo la app independiente obtiene un usuario y los códigos de prueba**:

| Modo | Rutas | Cuándo existen | Para qué |
|---|---|---|---|
| Desarrollo local | `/v1/dev/token`, `/v1/dev/demo-codes` | Solo con `APP_ENV=local` (o testing) **y** `GAMIF_DEV_ENDPOINTS=true`. En producción no existen aunque la variable esté puesta | Tu máquina |
| **Modo demo** | `/v1/demo/token`, `/v1/demo/codes` | En **cualquier** entorno, solo si se activa `GAMIF_DEMO_MODE=true` (apagado: no existen) | Un servidor de demostración (VPS) |

La web se compila con `GAMIF_DEMO_MODE=true` para usar el segundo par. `/v1/dev/*` nunca se reabre en producción.

> **Seguridad del modo demo.** Es un emisor de tokens abierto: quien llegue a `/v1/demo/token` puede actuar como cualquier usuario del emisor de demostración (`standalone-dev`) y ver la lista de códigos de prueba. Por eso solo se usa (1) detrás de una compuerta con clave en el proxy (la trae `deploy/Caddyfile.demo`), (2) con un emisor y una clave propios de la demo, y (3) **sin campañas reales** en el mismo servidor. Tiene límite de peticiones por IP (30/min el token, 60/min la lista). Los tokens duran 5 minutos.

## 1. En tu máquina (lo de siempre)
```bash
docker compose up -d db backend panel web     # panel :4200, juegos :8080, API :8000
docker compose exec backend php artisan gamification:demo:seed        # códigos de prueba (los 4 juegos)
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
3. Levanta: `docker compose -f deploy/compose.demo.yml --env-file deploy/demo.env up -d --build` (la primera vez tarda varios minutos: compila la web de Flutter).
4. Prepara la base y los datos:
   ```bash
   C="docker compose -f deploy/compose.demo.yml --env-file deploy/demo.env"
   $C run --rm app php artisan migrate --force
   $C exec app php artisan gamification:demo:seed          # ~1 min por juego: espera a la baliza real (drand)
   $C exec app php artisan admin:create-superadmin tu@correo.com --name="Tu Nombre"
   ```
5. Entra a `https://demo.tudominio.com` (el navegador pide usuario y clave de la compuerta) y a `https://admin.tudominio.com` para el panel.

**Cómo funciona la compuerta:** Caddy pide usuario y clave para la web y para las llamadas de la API que **no** traen `Authorization: Bearer …` (pedir token, listar códigos). Las llamadas con un token ya emitido pasan y las valida la API. Es lo que permite que la app funcione tras pasar la compuerta (el navegador reenvía la clave a su mismo origen) sin dejar el emisor de tokens abierto.

**Mantenimiento:**
- Los códigos de prueba se gastan al canjearlos. Para más, vuelve a correr el `seed` (crea lotes nuevos; los comprometidos no se pueden borrar).
- Actualizar: `git pull` y el comando `up -d --build` del paso 3; `migrate --force` si hay migraciones.
- El panel de producción es el de siempre (contraseña + 2FA); recuerda `docs/hardening-p9.md` y `docs/revision-p10.md` para el resto de la lista de verificación.

## Pruebas de este modo
`tests/Feature/Dev/DemoModeTest.php` (funciona en producción solo activándolo, no reabre `/v1/dev/*`, límite de peticiones), `DemoModeOffTest.php` (apagado no existe) y `dev_client_test.dart` (el cliente usa `/v1/demo/*`). La lógica de la compuerta de Caddy se comprobó contra un Caddy real: sin clave ni token → 401; con token → pasa; con clave → pasa.
