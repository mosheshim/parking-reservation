#!/usr/bin/env sh
set -e

npm ci

npm run dev -- --host 0.0.0.0 --port 5173
