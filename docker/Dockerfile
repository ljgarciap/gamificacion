# syntax=docker/dockerfile:1
# Imagen propia de Flutter (SPEC-008): las de cirruslabs dejaron de publicarse. Contexto de build = raíz del repo.
#   sdk   -> SDK oficial verificado, sin privilegios (servicios `dev` y `tools` de docker-compose)
#   build -> compila Flutter Web del workspace
#   web   -> nginx con el resultado
# Para cambiar de versión hay que cambiar FLUTTER_VERSION y FLUTTER_SHA256 juntos (el sha256 está en
# https://storage.googleapis.com/flutter_infra_release/releases/releases_linux.json) y la versión del CI.

# amd64 forzado: el SDK oficial de Linux es x64 (y el Dart arm64 de la imagen anterior abortaba con Bus error en Docker Desktop).
FROM --platform=linux/amd64 debian:12-slim@sha256:e67eeead736ff7bbe258ef088bff683635c561b1fcfefad0806c1df4ab49ce0e AS sdk
ARG FLUTTER_VERSION=3.50.1
ARG FLUTTER_SHA256=4ad17fc8462fbcef2ba19ff4a78c0a16b7147fde1add8578e267ea62cdb92678
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl git unzip xz-utils \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --create-home --uid 10001 flutter \
 && install -d -o flutter -g flutter /opt/flutter /app /app/.dart_tool /app/apps/standalone/.dart_tool
USER flutter
ENV PATH="/opt/flutter/bin:${PATH}" PUB_CACHE=/home/flutter/.pub-cache
# Si el archivo descargado no coincide con el sha256, la construcción se detiene antes de descomprimirlo.
RUN curl --proto '=https' --tlsv1.2 -fsSL --retry 3 -o /tmp/flutter.tar.xz \
      "https://storage.googleapis.com/flutter_infra_release/releases/stable/linux/flutter_linux_${FLUTTER_VERSION}-stable.tar.xz" \
 && echo "${FLUTTER_SHA256}  /tmp/flutter.tar.xz" | sha256sum --check --strict - \
 && tar -xJf /tmp/flutter.tar.xz -C /opt \
 && rm /tmp/flutter.tar.xz \
 && flutter config --no-analytics >/dev/null \
 && flutter precache --web \
 && install -d "$PUB_CACHE"
WORKDIR /app
EXPOSE 8081

FROM sdk AS build
COPY --chown=flutter:flutter . .
# URL del backend que usará la app web (la ve el NAVEGADOR, no el contenedor). Vacía = backend simulado.
ARG GAMIF_API_URL=""
# --pwa-strategy=none: sin service worker. Con el service worker por defecto el navegador sigue
# sirviendo la versión anterior tras reconstruir (síntoma: "juego no disponible" con un juego nuevo)
# y además borra el service worker viejo que ya hubiera guardado. Revisar para producción.
RUN flutter pub get --enforce-lockfile \
 && cd apps/standalone \
 && flutter build web --release --pwa-strategy=none --dart-define=GAMIF_API_URL=${GAMIF_API_URL}

FROM nginx:1.27-alpine AS web
# Orígenes que pueden embeber la app en iframe (RNF-03). En local "*", en QA/prod restringir.
ENV ALLOWED_FRAME_ANCESTORS="*"
COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build /app/apps/standalone/build/web /usr/share/nginx/html
EXPOSE 80
