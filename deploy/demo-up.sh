#!/usr/bin/env bash
# Despliega la DEMOSTRACIÓN en un servidor con Docker (guía: docs/despliegue-demo.md). Se corre EN el servidor, desde la carpeta del proyecto:
#   deploy/demo-up.sh <IP pública> [correo del superadmin]
# - Sin dominio propio usa nombres de sslip.io (demo.<IP>.sslip.io y admin.<IP>.sslip.io), que resuelven a esa IP y permiten el certificado
#   automático. Para un dominio propio, define DEMO_DOMAIN y ADMIN_DOMAIN en el entorno antes de correrlo.
# - Genera deploy/demo.env con secretos aleatorios SOLO si no existe (nunca pisa uno existente: perder GAMIF_PRIZE_MAP_KEY deja ilegibles los
#   lotes comprometidos) y guarda la clave de la compuerta y la contraseña temporal del superadmin en deploy/demo-credentials.txt (0600).
# - Es idempotente: en los despliegues siguientes conserva secretos y datos, reconstruye lo que cambió y no vuelve a sembrar (ver CI/CD en docs/ci-cd.md).
# - Construye las imágenes de una en una (un servidor chico no aguanta las compilaciones de Flutter y Angular en paralelo), migra, crea el
#   superadmin y siembra los códigos de prueba (espera a la baliza real: ~1 minuto por juego) en segundo plano.
set -euo pipefail
cd "$(dirname "$0")/.."

IP="${1:?uso: deploy/demo-up.sh <IP pública> [correo del superadmin]}"
ADMIN_EMAIL="${2:-}"
DEMO_DOMAIN="${DEMO_DOMAIN:-demo.${IP}.sslip.io}"
ADMIN_DOMAIN="${ADMIN_DOMAIN:-admin.${IP}.sslip.io}"
ENVFILE=deploy/demo.env
CREDS=deploy/demo-credentials.txt
COMPOSE=(docker compose -f deploy/compose.demo.yml --env-file "$ENVFILE")

b64() { openssl rand -base64 32 | tr -d '\n'; }

if [ ! -f "$ENVFILE" ]; then
  echo "==> Generando $ENVFILE con secretos nuevos"
  GATE_USER=demo
  GATE_PASS="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 16)"
  GATE_HASH="$(docker run --rm caddy:2-alpine caddy hash-password --plaintext "$GATE_PASS")"
  sed \
    -e "s|^DEMO_DOMAIN=.*|DEMO_DOMAIN=${DEMO_DOMAIN}|" \
    -e "s|^ADMIN_DOMAIN=.*|ADMIN_DOMAIN=${ADMIN_DOMAIN}|" \
    -e "s|^DEMO_USER=.*|DEMO_USER=${GATE_USER}|" \
    -e "s|^DEMO_PASSWORD_HASH=.*|DEMO_PASSWORD_HASH='${GATE_HASH}'|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" \
    -e "s|^APP_KEY=.*|APP_KEY=base64:$(b64)|" \
    -e "s|^APP_URL=.*|APP_URL=https://${DEMO_DOMAIN}|" \
    -e "s|^GAMIF_PRIZE_MAP_KEY=.*|GAMIF_PRIZE_MAP_KEY=$(b64)|" \
    -e "s|^GAMIF_CODE_PEPPER=.*|GAMIF_CODE_PEPPER=$(b64)|" \
    -e "s|^GAMIF_ADMIN_KEY=.*|GAMIF_ADMIN_KEY=$(b64)|" \
    -e "s|PON_AQUI_LA_CLAVE_BASE64|$(b64)|" \
    deploy/demo.env.example | sed -e 's/[[:space:]]*# .*$//' > "$ENVFILE"
  chmod 600 "$ENVFILE"
  umask 077
  {
    echo "Compuerta de la demo (juegos y API): https://${DEMO_DOMAIN}"
    echo "  usuario: ${GATE_USER}"
    echo "  clave:   ${GATE_PASS}"
    echo "Panel de administración: https://${ADMIN_DOMAIN}"
  } > "$CREDS"
else
  echo "==> $ENVFILE ya existe: se conserva"
fi

echo "==> Construyendo las imágenes (una a la vez)"
export COMPOSE_PARALLEL_LIMIT=1
"${COMPOSE[@]}" build

echo "==> Levantando"
"${COMPOSE[@]}" up -d

echo "==> Migrando la base"
"${COMPOSE[@]}" exec -T app php artisan migrate --force

if [ -n "$ADMIN_EMAIL" ]; then
  echo "==> Creando el superadmin ${ADMIN_EMAIL} (la contraseña temporal se guarda en $CREDS)"
  OUT="$("${COMPOSE[@]}" exec -T app php artisan admin:create-superadmin "$ADMIN_EMAIL" --name="Superadmin" 2>&1 || true)"
  echo "$OUT" | grep -v "Contraseña temporal" || true
  { echo "Superadmin: ${ADMIN_EMAIL}"; echo "$OUT" | grep "Contraseña temporal" || echo "(ya existía o falló: ver la salida)"; } >> "$CREDS"
fi

# La siembra crea lotes NUEVOS cada vez: solo se hace en el primer despliegue (marca deploy/.seeded) o si se pide con DEMO_SEED=1.
if [ "${DEMO_SEED:-auto}" = "1" ] || { [ "${DEMO_SEED:-auto}" = "auto" ] && [ ! -f deploy/.seeded ]; }; then
  echo "==> Sembrando los códigos de prueba en segundo plano (tarda varios minutos: espera a la baliza real)"
  touch deploy/.seeded
  setsid nohup "${COMPOSE[@]}" exec -T app php artisan gamification:demo:seed < /dev/null > deploy/seed.log 2>&1 &
  echo "    avance: tail -f deploy/seed.log"
else
  echo "==> Códigos de prueba: ya sembrados (DEMO_SEED=1 para sembrar más)"
fi

echo "==> Comprobando que la API responde"
for i in $(seq 1 30); do
  if "${COMPOSE[@]}" exec -T api wget -qO- http://127.0.0.1:8080/up > /dev/null 2>&1; then echo "    /up responde"; break; fi
  [ "$i" = 30 ] && { echo "    la API no respondió: revisar docker compose logs" >&2; exit 1; }
  sleep 2
done

echo
echo "Listo."
# Las credenciales solo se muestran si es el primer despliegue (en uno de CI no se imprimen secretos en el registro).
if [ -t 1 ] && [ -f "$CREDS" ]; then echo "Credenciales en $CREDS:"; cat "$CREDS"; fi
