#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

for arg in "$@"; do
  case "$arg" in
    -h|--help)
      echo "Usage: sh ./scripts/update.sh"
      echo "Runs: docker compose pull && docker compose run -it --rm web php artisan xboard:update && docker compose up -d"
      exit 0
      ;;
    *)
      echo "Unknown option: $arg"
      echo "Usage: sh ./scripts/update.sh"
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

run_tty_args="-it"
if [ ! -t 0 ] || [ ! -t 1 ]; then
  run_tty_args=""
fi

if [ -n "$run_tty_args" ]; then
  docker compose run -it --rm web php artisan xboard:update
else
  docker compose run --rm web php artisan xboard:update
fi

docker compose up -d
docker compose ps
