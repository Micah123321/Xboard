#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

mkdir -p .docker/.data storage/logs storage/theme plugins

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example."
  echo "Edit .env before starting services."
else
  echo ".env already exists."
fi

echo "Runtime directories are ready:"
echo "  .docker/.data"
echo "  storage/logs"
echo "  storage/theme"
echo "  plugins"
