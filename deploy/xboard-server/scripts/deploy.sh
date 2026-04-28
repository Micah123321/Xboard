#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

sh ./scripts/init.sh

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose plugin is required. Install Docker with the 'docker compose' command."
  exit 1
fi

docker compose pull
docker compose up -d
docker compose ps

echo "Deployment started."
echo "Check scheduler with: sh ./scripts/status.sh"
