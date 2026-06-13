# Servana edge image (Plan §26.1: nginx config + built SPA assets).
# Runs as the non-root `nginx` user via the unprivileged base image.
# Used by docker-compose.prod.yml. Dev uses the stock unprivileged image with a
# bind-mounted config + public/ (see docker-compose.yml) for live edits.

# ---------- SPA build ----------
FROM node:20-alpine AS spa-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Outputs to public/spa per vite.config.ts.
RUN npm run build

# ---------- runtime (non-root nginx) ----------
FROM nginxinc/nginx-unprivileged:1.27-alpine AS prod
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
# Laravel public root (index.php, .htaccess, robots.txt, brand assets) ...
COPY --chown=nginx:nginx public /var/www/html/public
# ... plus the freshly built SPA bundle.
COPY --from=spa-build --chown=nginx:nginx /app/public/spa /var/www/html/public/spa

EXPOSE 8080
