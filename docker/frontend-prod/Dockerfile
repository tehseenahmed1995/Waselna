# syntax=docker/dockerfile:1

# -----------------------------------------------------------------------------
# Waselna Web_Client — PRODUCTION image.
# Builds the Vite/React SPA to a static bundle and serves it with nginx.
# VITE_API_BASE_URL is baked at build time (it is compiled into the bundle), so
# the pipeline passes it as a build arg pointing at the public API URL.
# -----------------------------------------------------------------------------

FROM node:22-alpine AS build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
ARG VITE_API_BASE_URL=/api/v1
ARG VITE_REVERB_APP_KEY=waselna
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=https
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME
RUN npm run build

FROM nginx:1.27-alpine AS runtime
COPY docker/frontend-prod/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=5 CMD wget -qO- http://127.0.0.1/ >/dev/null 2>&1 || exit 1
CMD ["nginx", "-g", "daemon off;"]