  # Laravel API Platform — Approach

  This document outlines the design and plan to implement a productionish mini API platform composed of Nginx (edge), a Laravel API Gateway, a Laravel Auth Service, a Laravel Tasks Service, with Postgres and Redis.

  ## Architecture

  ```
  +-----------------+           +--------------------+
  |     NGINX       |  :8080    |    API Gateway     |
  |  (edge/load     +---------->+  Laravel (9000)    +---------------------+
  |   balancer)     |           |  JWT verify,       |                     |
  | adds X-Request-Id           |  path proxy, rate  |                     |
  +---------+-------+           |  limiting, logs    |                     |
            |                   +----------+---------+                     |
            |                              |                               |
            | /auth/*                      | /tasks/*                      |
            v                              v                               v
  +--------------------+         +--------------------+           +------------------+
  |   Auth Service     |         |   Tasks Service    |           |     Postgres     |
  | Laravel (9001)     |         | Laravel (9002)     |           |  (auth_db,       |
  | POST /auth/login   |         | GET/POST /tasks    |           |   tasks_db)      |
  | Issues HS256 JWT   |         | Caching, idempot.  |           +------------------+
  +--------------------+         +--------------------+                    ^
            ^                              ^                               |
            |                              |                               |
            +------------------------------+-------------------------------+
                                  Redis (cache, rate limit)
  ```

  - Nginx listens on host :8080 and forwards all traffic to `api-gateway:9000`, injecting/forwarding `X-Request-Id`.
  - API Gateway performs path-based routing:
    - `/auth/*` → Auth Service
    - `/tasks/*` → Tasks Service
  - Security: Gateway verifies Bearer JWT for `/tasks/*` using shared HS256 secret.
  - Observability: `X-Request-Id` generated at edge and propagated end-to-end. Structured JSON logs include request/response metadata.
  - State: Postgres hosts `auth_db` and `tasks_db`. Redis is the Laravel cache for all services.

  ## Implementation Starting Point

  I will start with Laravel scaffolding for each service (`api-gateway/`, `auth-service/`, `tasks-service/`) to provide the framework foundation. This includes the standard Laravel directory structure, configuration files and basic setup needed to run the applications in Docker. The actual business logic will be implemented in routes, controllers, migrations and seeders as mentioned in the endpoints and data model sections below.

  ## Invariants / Guarantees

  - JWT is required and verified (HS256) for any `/tasks/*` request. Missing/invalid → 401.
  - `X-Request-Id` is present on all requests/responses; Gateway generates if missing, forwards downstream, and echoes back.
  - Error envelope format for non-2xx: `{ code, message, details? }` with appropriate HTTP status.
  - Idempotency for `POST /tasks` using header `Idempotency-Key` (case-insensitive). Same key + same body → return original 200 response without duplicate DB insert.
  - Caching of `GET /tasks` responses in Tasks Service with per-user+filters key; TTL ~30s. Cache is invalidated on successful `POST /tasks` for that user.
  - Structured logs include: `ts, level, service, route, method, status, latency_ms, request_id, user_sub?`.

  ## Endpoints

  - Auth Service
    - POST `/auth/login`
      - Body: `{ email: string, password: string }`
      - Success 200: `{ token: string, expires_in: number, token_type: "Bearer" }`
        - JWT claims: `sub` (user id), `role`, `iss`, `aud`, `exp` (e.g., 15m)
      - Errors:
        - 400: `{ code: "bad_request", message }` for malformed input
        - 401: `{ code: "invalid_credentials", message }`
        - 429: `{ code: "too_many_requests", message }` (throttle via Redis)
        - 5xx: `{ code: "server_error", message }`

  - Tasks Service
    - GET `/tasks?status=&page=&limit=`
      - Auth: Bearer JWT required (gateway-verified)
      - Query: `status` (optional, e.g., pending|done), `page` (default 1), `limit` (default 20, max 100)
      - Success 200: `{ data: Task[], page, limit, total }`
      - Caching: 30s per user+filters
      - Errors:
        - 401: `{ code: "unauthorized", message }` (if gateway passes through)
        - 422: `{ code: "validation_error", message, details }`
        - 5xx: `{ code: "server_error", message }`

    - POST `/tasks`
      - Headers: `Idempotency-Key: <uuid>` required
      - Body: `{ title: string, status?: "pending"|"done" }`
      - Success 200: `{ id, title, status, created_at }`
        - Idempotent replay with same key/body returns same 200 and body
      - Errors:
        - 400: `{ code: "bad_request", message }` (missing key)
        - 401: `{ code: "unauthorized", message }`
        - 409: `{ code: "idempotency_conflict", message }` (same key, different body)
        - 422: `{ code: "validation_error", message, details }`
        - 5xx: `{ code: "server_error", message }`

  ## Data Model (Postgres)

  - `auth_db.users`
    - `id` UUID PK
    - `email` text unique not null
    - `password_hash` text not null (bcrypt)
    - `role` text not null default 'user'
    - `created_at`, `updated_at` timestamps

  - `tasks_db.tasks`
    - `id` UUID PK
    - `user_id` UUID not null (FK logical to auth, not enforced cross-DB)
    - `title` text not null
    - `status` text not null check in ('pending','done') default 'pending'
    - `created_at`, `updated_at`

  - `tasks_db.idempotency_keys`
    - `id` UUID PK
    - `key` text unique not null
    - `user_id` UUID not null
    - `request_hash` text not null (hash of normalized body)
    - `response_body` jsonb not null
    - `status_code` int not null
    - `created_at`

  Notes:
  - We keep idempotency entries to replay responses. Unique(`key`). If a key is reused with a different `request_hash`, return 409.

  ## Redis Usage

  - Auth Service:
    - Login throttle (e.g., 5 attempts per minute per IP/email). TTL: 60s.

  - Tasks Service:
    - Cache GET `/tasks` responses. Key: `tasks:list:{userId}:{hash(filters)}`. TTL: 30s.
    - Invalidate on successful POST `/tasks` for that `userId` (delete matching list keys).

  - API Gateway:
    - Optional simple rate limit per IP (e.g., 60 rpm). TTL: 60s.
    - Optional small cache for parsed/verified JWT by token signature for a few seconds to reduce HMAC work. TTL: 5–15s.

  ## Error Mapping

  - 400 Bad Request → `{"code":"bad_request","message":"Invalid or missing parameters"}`
  - 401 Unauthorized → `{"code":"unauthorized","message":"Missing or invalid token"}`
  - 403 Forbidden → `{"code":"forbidden","message":"Insufficient permissions"}`
  - 404 Not Found → `{"code":"not_found","message":"Resource not found"}`
  - 409 Conflict → `{"code":"idempotency_conflict","message":"Same key with different payload"}`
  - 422 Unprocessable Entity → `{"code":"validation_error","message":"Validation failed","details":{...}}`
  - 429 Too Many Requests → `{"code":"too_many_requests","message":"Rate limit exceeded"}`
  - 5xx → `{"code":"server_error","message":"Unexpected error"}`

  ## Tests Plan

  - Happy path E2E (through Gateway):
    1) POST `/auth/login` with seeded user → 200 + JWT
    2) POST `/tasks` with Idempotency-Key → 200
    3) GET `/tasks` → 200, contains created task and validate cache behavior (second call fast, X-Request-Id propagated)

  - Negative/idempotency:
    - Replay POST `/tasks` with same Idempotency-Key and same body → 200 and identical payload
    - Replay POST `/tasks` with same Idempotency-Key and different body → 409
    - Unauthorized GET `/tasks` without Bearer token → 401

  Tests will be implemented as Laravel feature tests in each service and/or a small Gateway integration test. Optional minimal end-to-end script with HTTP client assertions.

  ## Done Criteria (Timebox)

  - `docker-compose up` starts all services: Nginx, Gateway, Auth, Tasks, Postgres, Redis.
  - Auth: seeded users, `POST /auth/login` issues HS256 JWT with claims: `sub, role, iss, aud, exp`.
  - Gateway: path proxies, verifies JWT on `/tasks/*`, propagates headers (`X-Request-Id`) and returns error envelope.
  - Tasks: `GET /tasks` (paginated, cached 30s), `POST /tasks` (idempotent via Idempotency-Key) with cache invalidation.
  - Structured logs with `X-Request-Id`, user sub (if present), route, status, latency.
  - At least 2 tests: one happy path, one idempotency replay or unauthorized.
  - README includes run instructions and a short "What I’d do next".
