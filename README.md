# Parking Reservation System

## Setup

Minimal steps to run everything via Docker:

**Start stack: `make docker-up` (or `docker-compose up -d`).**

- **Backend & DB**
  - On first run, the backend container will:
    - Copy `backend/.env.example` to `backend/.env` automatically if it does not exist.
    - Install Composer dependencies and `npm` packages (if missing).
    - Run `php artisan key:generate` if `APP_KEY` is not set.
    - Run `php artisan migrate --force` and `php artisan db:seed --force`.
    - Start the Reverb websocket server, HTTP server, queue worker, and cron.

- **Frontend**
  - On first run, the frontend container automatically copies `frontend/.env.example` to `frontend/.env` if needed.
  - It installs `npm` dependencies (if missing) and starts the Vite dev server on `http://localhost:5173` (see [docker-compose.yml](cci:7://file:///Users/moshe.shimanovich/Documents/parking-reservation/docker-compose.yml:0:0-0:0)).

With this setup, the full application (DB, backend HTTP, Reverb, queue, cron, frontend) works with a single `docker-compose up -d`.

## Architecture Decisions

### Concurrency / Race Condition Handling

- **Primary strategy: Postgres EXCLUDE constraint** on `reservations`:
  - `EXCLUDE USING GIST (spot_id WITH =, tsrange(start_time, end_time) WITH &&) WHERE status = 'Booked'`.
  - The database rejects overlapping active reservations for the same spot, even when requests race.
- **Conflict handling in code**:
  - [ReservationService::createReservation](cci:1://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/app/Services/ReservationService.php:30:4-82:5) wraps the insert in a transaction and catches `QueryException`.
  - Overlap violations are detected by [isOverlapConstraintViolation()](cci:1://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/app/Services/ReservationService.php:374:4-388:5) and mapped to `ReservationTimeConflictException`, which the controller returns as HTTP `409`.
- **Additional guards**:
  - `reservations_valid_time_range` CHECK constraint enforces `end_time > start_time`.
  - A partial index on `end_time` for `status = 'Booked'` supports the stale-reservation worker that marks expired bookings as `Completed`.

### WebSocket vs. REST

- **REST API responsibilities** (see [backend/routes/api.php](cci:7://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/routes/api.php:0:0-0:0)):
  - `POST /login` authenticates a user and returns a JWT.
  - Authenticated routes (via `jwt` middleware):
    - `GET /spots` returns parking spot metadata.
    - `GET /slots?date=YYYY-MM-DD` returns a full daily availability snapshot.
    - `POST /reservations` creates a reservation.
    - `PUT /reservations/{id}/complete` marks a reservation as completed.

- **WebSocket / broadcasting responsibilities**:
  - Backend uses Laravel broadcasting with the `reverb` driver ([config/broadcasting.php](cci:7://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/config/broadcasting.php:0:0-0:0), [config/reverb.php](cci:7://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/config/reverb.php:0:0-0:0)).
  - [ReservationService](cci:2://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/app/Services/ReservationService.php:23:0-389:1) computes only the affected slot cells for a reservation and broadcasts `ParkingSlotStatusChanged` events so clients can patch the UI without refetching the whole snapshot.

- **Frontend realtime client**:
  - [frontend/src/realtime/EchoClient.js](cci:7://file:///Users/moshe.shimanovich/Documents/parking-reservation/frontend/src/realtime/EchoClient.js:0:0-0:0) configures `laravel-echo` + `pusher-js` against Reverb using `WEBSOCKET_APP_KEY`, `WEBSOCKET_HOST`, `WEBSOCKET_PORT`, `WEBSOCKET_SCHEME` from `frontend/.env`.
  - JWT is sent in the `Authorization` header for websocket auth (`/broadcasting/auth`).
  - A connection lifecycle controller implements exponential backoff + jitter to avoid reconnect storms.

- **Division of labor**
  - **REST**: the UI first fetches the daily availability snapshot over HTTP (after login) and only then opens the websocket connection.
  - **WebSockets**: once connected, clients receive incremental updates when reservations are created or completed, streaming only the impacted slot cells to all clients viewing that date.
  - There is no explicit snapshot “version” coordination between HTTP and websocket events. Consistency and overlap rules are enforced in the backend, and the time window between the initial HTTP snapshot and the socket connection is small enough that the risk of missed or inconsistent updates is negligible for this use case.

### Domain Assumptions

- **Parking spots**
  - Each row in `parking_spots` represents a single physical spot identified by `spot_number`.
  - Only one active (`Booked`) reservation can exist per spot at any time; overlapping ranges are rejected by the EXCLUDE constraint.

- **Reservations & status model**
  - Reservation times are stored in UTC as `start_time` / `end_time`.
  - Business rules allow reservations only within the daily slot window derived from configured slot times (via [SlotService](cci:2://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/app/Services/SlotService.php:12:0-150:1)).
  - `status` is an enum with `Booked` and `Completed`.
  - `completed_at` is set when a reservation is completed (either via API or background job).

- **Slot model & timezone**
  - All slot rules are currently defined in the `Asia/Jerusalem` timezone (`SlotService::SLOT_TIMEZONE`).
  - Fixed daily slot definitions: `08:00–12:00`, `12:00–16:00`, `16:00–22:00`.
  - [SlotService](cci:2://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/app/Services/SlotService.php:12:0-150:1) converts these local ranges to UTC per date and exposes them as `SlotDefinition` value objects (including a stable `key` and `startUtc` / `endUtc`), which are reused for both DB overlap checks and API payloads.
  - The current product requirements do not specify multi-timezone behavior, but all slot and timezone logic is encapsulated in [SlotService](cci:2://file:///Users/moshe.shimanovich/Documents/parking-reservation/backend/app/Services/SlotService.php:12:0-150:1), so it can be extended to support multiple parking lots with different timezones and slot configurations.

- **Users & auth**
  - The DB is initialized with a standard `users` table seeded with two driver accounts:
    1. `driver1@parking.com` / `password123`
    2. `driver2@parking.com` / `password123`
  - API authentication is JWT-based (`jwt` middleware and `AuthController@login`).
  - The frontend sends `Authorization: Bearer <token>` for both REST requests ([ApiClient](cci:2://file:///Users/moshe.shimanovich/Documents/parking-reservation/frontend/src/api/ApiClient.js:10:0-74:1)) and websocket auth (`EchoClient`).

## Planned vs. Actual (Retrospective)

<!--
Briefly list your initial time estimates vs. actual time spent for major components.
Suggested bullets:
- DB & Migrations: planned Xh, actual Yh
- Auth & Security: planned Xh, actual Yh
- WebSocket / Real-time updates: planned Xh, actual Yh
- REST API & Business Logic: planned Xh, actual Yh
- Frontend integration: planned Xh, actual Yh

Add 1–2 sentences about delays or scope changes and how you adjusted your scope or plan to meet the final deadline.
-->