#!/usr/bin/env sh
set -e

# Here to ensure node_modules is always installed on start so no need to run npm installed manually.
npm install

npm run dev -- --host 0.0.0.0 --port 5173
