#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

run_migrate=false

for arg in "$@"; do
  case "$arg" in
    --migrate)
      run_migrate=true
      ;;
    -h|--help)
      echo "Usage: sh ./scripts/update.sh [--migrate]"
      echo "  --migrate  Run 'php artisan migrate --force' after containers are updated."
      exit 0
      ;;
    *)
      echo "Unknown option: $arg"
      echo "Usage: sh ./scripts/update.sh [--migrate]"
      exit 1
      ;;
  esac
done

if [ ! -f .env ]; then
  echo ".env is missing. Run: sh ./scripts/init.sh"
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose plugin is required. Install Docker with the 'docker compose' command."
  exit 1
fi

docker compose pull
docker compose up -d

if [ "$run_migrate" = "true" ]; then
  docker compose exec -T web php artisan migrate --force
else
  echo "Migration skipped. Re-run with --migrate when the release requires database migrations."
fi

docker compose ps
