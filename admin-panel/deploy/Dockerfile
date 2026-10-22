# syntax=docker/dockerfile:1
# Panel de administración (SPEC-003 P9, RNF-32): Angular compilado -> nginx sin privilegios, con CSP estricta por petición.
# Contexto de build = admin-panel/.
FROM node:24-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npx ng build --configuration production

FROM nginxinc/nginx-unprivileged:1.27-alpine AS panel
# Dónde está la API del panel (mismo origen para el navegador: nginx hace de proxy, así no hay CORS).
ENV ADMIN_UPSTREAM="backend:8000"
# Red del balanceador que pone X-Forwarded-For (para limitar por IP real); por defecto las redes privadas de Docker.
ENV REAL_IP_FROM="172.16.0.0/12"
COPY --from=build /app/dist/admin-panel/browser /usr/share/nginx/html
COPY deploy/panel.conf.template /etc/nginx/templates/default.conf.template
COPY deploy/security-headers.conf /etc/nginx/snippets/security-headers.conf
EXPOSE 8080
