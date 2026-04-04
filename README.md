# Parking Reservation System

## Setup

Minimal steps to run everything via Docker:

For the first run, you can either:

- Run `make docker-init` to copy frontend/backend `.env.example` files into `.env` and start the stack with `docker-compose up -d`, or
- Manually copy the `.env.example` to `.env` in each of the frontend/backend/db directories and then start the stack with `make docker-up` (or `docker-compose up -d`).


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

This project is implemented as a regular Laravel application. While Lumen would likely be a better fit for a small API-focused backend, I chose Laravel for a faster and more comfortable development experience.

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
      - The route expects `start_time` / `end_time` in UTC; convert Jerusalem (Asia/Jerusalem) local reservation bounds to UTC before calling the endpoint.
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
  - There is no explicit snapshot “version” coordination between HTTP and websocket events. I chose not to implement this because the risk of missed or inconsistent updates in the short window between the initial HTTP snapshot and the socket connection is low, and time was limited. Consistency and overlap rules are enforced in the backend.

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
 
- **Infrastructure**: planned 2h, actual 3h
  Encountered initial configuration issues while setting up the WebSocket, which required additional time to troubleshoot and resolve.

- **DB & Migrations**: planned 1h, actual ~1h  
  Initial planning for tables and indexes went as expected. Since I had never used GiST before, I spent time learning it and confirming the queries use the index as expected. Some index adjustments were made during development to better support query patterns, but these changes did not significantly impact the timeline.

- **Auth & Security**: planned 2h, actual 1h  
  Initially planned to implement JWT validation manually, but switched to using an existing package to save time and reduce complexity.

- **WebSocket / Real-time updates**: planned 2h, actual ~2h  
  Implementation was more straightforward than anticipated, resulting in a faster completion. A scope adjustment was to not add explicit snapshot “version” control between the HTTP availability snapshot and websocket events.

- **REST API & Business Logic**: planned 3h, actual 5h  
  Took slightly longer than expected, but remained within acceptable limits. No scope adjustments were required.

- **Frontend integration**: planned 2h, actual 1h  
  Completed as expected without any deviations.

Overall, the project stayed within the expected time frame. Additional time was invested in refining and cleaning up the codebase after achieving functional correctness. In a real-world scenario with stricter deadlines, this refactoring phase could have been deferred to a separate task to prioritize delivery.