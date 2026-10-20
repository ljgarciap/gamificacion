# syntax=docker/dockerfile:1
# Multi-stage: build Flutter Web del workspace -> nginx. Contexto de build = raíz del repo.
ARG FLUTTER_VERSION=3.32.8

# amd64 forzado: el Dart arm64 de esta imagen aborta con Bus error en Docker Desktop (Apple Silicon).
FROM --platform=linux/amd64 ghcr.io/cirruslabs/flutter:${FLUTTER_VERSION} AS build
WORKDIR /app
COPY . .
# --pwa-strategy=none: sin service worker. Con el service worker por defecto el navegador sigue
# sirviendo la versión anterior tras reconstruir (síntoma: "juego no disponible" con un juego nuevo)
# y además borra el service worker viejo que ya hubiera guardado. Revisar para producción.
RUN flutter config --no-analytics >/dev/null \
 && flutter pub get \
 && cd apps/standalone \
 && flutter build web --release --pwa-strategy=none

FROM nginx:1.27-alpine AS web
# Orígenes que pueden embeber la app en iframe (RNF-03). En local "*", en QA/prod restringir.
ENV ALLOWED_FRAME_ANCESTORS="*"
COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build /app/apps/standalone/build/web /usr/share/nginx/html
EXPOSE 80
