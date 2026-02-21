# Upgrade from `new-dev` to Latest Docker Version

This guide uses Docker Compose to move from an old `new-dev` deployment flow to the latest version.

## 1. Get the project

```bash
git clone -b compose --depth 1 https://github.com/Micah123321/Xboard Xboard-new
cd Xboard-new
```

If your local file is `compose.sample.yaml`, create `compose.yaml` first:

```bash
cp compose.sample.yaml compose.yaml
```

## 2. Initialize with built-in SQLite + Redis

```bash
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    web php artisan xboard:install
```

## 3. Update `.env` to MySQL configuration

Edit `.env` and switch database settings to MySQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xboard
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

Then run:

```bash
docker compose run -it --rm web php artisan xboard:update
```

## 4. Start services

```bash
docker compose up -d
```

## 5. Check status

```bash
docker compose ps
docker compose logs -f web
docker compose logs -f horizon
```
