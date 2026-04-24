FROM node:20-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN ADMIN_BUILD_OUT_DIR=dist npm run build

FROM caddy:2-alpine

COPY Caddyfile /etc/caddy/Caddyfile
COPY --from=build /app/dist/ /usr/share/caddy/assets/admin/

EXPOSE 80
