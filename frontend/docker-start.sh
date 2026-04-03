#!/usr/bin/env sh
set -e

if [ ! -f .env ] && [ -f .env.example ]; then
	cp .env.example .env
fi

if [ ! -d node_modules ]; then
	npm install
fi

npm run dev -- --host 0.0.0.0 --port 5173
