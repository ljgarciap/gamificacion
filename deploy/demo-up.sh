#!/usr/bin/env bash
# Despliega la DEMOSTRACIÓN en un servidor con Docker (guía: docs/despliegue-demo.md). Se corre EN el servidor, desde la carpeta del proyecto:
#   deploy/demo-up.sh <IP pública> [correo del superadmin]
# - Sin dominio propio usa nombres de sslip.io (demo.<IP>.sslip.io y admin.<IP>.sslip.io), que resuelven a esa IP y permiten el certificado
#   automático. Para un dominio propio, define DEMO_DOMAIN y ADMIN_DOMAIN en el entorno antes de correrlo.
# - Genera deploy/demo.env con secretos aleatorios SOLO si no existe (nunca pisa uno existente: perder GAMIF_PRIZE_MAP_KEY deja ilegibles los
#   lotes comprometidos) y guarda la clave de la compuerta y la contraseña temporal del superadmin en deploy/demo-credentials.txt (0600).
# - Es idempotente: en los despliegues siguientes conserva secretos y datos, reconstruye lo que cambió y no vuelve a sembrar (ver CI/CD en docs/ci-cd.md).
# - Construye las imágenes de una en una (un servidor chico no aguanta las compilaciones de Flutter y Angular en paralelo), migra y crea el
#   superadmin. La MUESTRA no se siembra aquí: el superadmin la configura, siembra y enciende desde el panel («Muestra»); el servicio worker
#   ejecuta la siembra (espera a la baliza real).
set -euo pipefail
cd "$(dirname "$0")/.."

IP="${1:?uso: deploy/demo-up.sh <IP pública> [correo del superadmin]}"
ADMIN_EMAIL="${2:-}"
DEMO_DOMAIN="${DEMO_DOMAIN:-demo.${IP}.sslip.io}"
ADMIN_DOMAIN="${ADMIN_DOMAIN:-admin.${IP}.sslip.io}"
ENVFILE=deploy/demo.env
CREDS=deploy/demo-credentials.txt

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

# Entornos creados antes de SPEC-006: GAMIF_DEMO_MODE (emisor abierto) se retiró y la muestra la declara GAMIF_DEMO_ALLOWED. Solo se AGREGA lo que
# falta (nunca se pisan secretos ni valores existentes).
if grep -q '^GAMIF_DEMO_MODE=' "$ENVFILE"; then
  echo "==> $ENVFILE: se retira GAMIF_DEMO_MODE (ya no existe)"
  grep -v '^GAMIF_DEMO_MODE=' "$ENVFILE" > "$ENVFILE.tmp" && cat "$ENVFILE.tmp" > "$ENVFILE" && rm -f "$ENVFILE.tmp"
fi
grep -q '^GAMIF_DEMO_ALLOWED=' "$ENVFILE" || { echo "==> $ENVFILE: se agrega GAMIF_DEMO_ALLOWED=true"; echo 'GAMIF_DEMO_ALLOWED=true' >> "$ENVFILE"; }
grep -q '^GAMIF_BALANCE=' "$ENVFILE" || { echo "==> $ENVFILE: se agrega GAMIF_BALANCE=stub"; echo 'GAMIF_BALANCE=stub' >> "$ENVFILE"; }

# Entornos creados antes de SPEC-006A: sin correo configurado la muestra no puede enviar el código de ingreso (y el valor por defecto de Laravel,
# `log`, dejaría los códigos en el registro). Solo se AGREGA lo que falta, con el correo SIMULADO (Mailpit): no sale nada del servidor hasta que
# alguien lo cambie a un SMTP real en deploy/demo.env.
if ! grep -q '^MAIL_MAILER=' "$ENVFILE"; then
  echo "==> $ENVFILE: se agrega el correo simulado (Mailpit); para uno real, editar MAIL_* y volver a desplegar"
  { echo 'MAIL_MAILER=smtp'; echo 'MAIL_HOST=mailpit'; echo 'MAIL_PORT=1025'; echo 'MAIL_FROM_ADDRESS=muestra@ejemplo.com'; echo 'MAIL_FROM_NAME=Muestra'; } >> "$ENVFILE"
fi

# Mailpit solo se levanta si el correo apunta a él (MAIL_HOST=mailpit); con un SMTP real no hace falta.
COMPOSE=(docker compose -f deploy/compose.demo.yml --env-file "$ENVFILE")
if grep -q '^MAIL_HOST=mailpit' "$ENVFILE"; then COMPOSE=(docker compose -f deploy/compose.demo.yml -f deploy/compose.mailpit.yml --env-file "$ENVFILE"); fi

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

# Campañas de demostración anteriores a SPEC-006 (sin la marca is_demo): se adoptan UNA vez. Este script solo corre en el servidor de demostración
# (GAMIF_DEMO_ALLOWED lo pone él mismo), que por decisión (D24) no tiene campañas reales; el comando queda en la bitácora.
if [ ! -f deploy/.adopted ]; then
  echo "==> Adoptando las campañas de demostración anteriores (una sola vez)"
  "${COMPOSE[@]}" exec -T app php artisan gamification:demo:adopt-legacy --yes < /dev/null && touch deploy/.adopted
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
