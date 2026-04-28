#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose plugin is required. Install Docker with the 'docker compose' command."
  exit 1
fi

docker compose ps

echo
echo "Recent scheduler logs:"
docker compose logs --tail=80 scheduler || true

echo
echo "Laravel schedule list:"
if ! docker compose exec -T web php artisan schedule:list; then
  echo "schedule:list failed. Ensure the web container is running and .env is valid."
fi

echo
echo "Manual GFW sync command:"
echo "  docker compose exec -T web php artisan sync:server-gfw-checks"
