#!/usr/bin/env sh
set -e

if [ ! -d node_modules ]; then
	npm install
fi

npm run dev -- --host 0.0.0.0 --port 5173
