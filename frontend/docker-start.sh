#!/usr/bin/env sh
set -e

# Always make sure npm is installed on boot.
# This is to make the first run smoother so no need to run npm install manually.
npm install

npm run dev -- --host 0.0.0.0 --port 5173
