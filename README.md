# Laravel API Platform

A production-ready API platform built with Laravel, Docker, Postgres and Redis following microservices architecture.

## Architecture

**Components:**
- **Nginx** (edge/load balancer) - public entrypoint on port 8080
- **API Gateway** (Laravel) - routes `/auth/*` → Auth Service, `/tasks/*` → Task Service  
- **Auth Service** (Laravel) - JWT authentication with HS256
- **Task Service** (Laravel) - task management with idempotency
- **Postgres** - separate databases (auth_db, tasks_db)
- **Redis** - caching and rate limiting

See [APPROACH.md](./APPROACH.md) for detailed architecture decisions, data models and implementation approach.

## Quick Start

- **Prerequisites:** Docker and Docker Compose. For CLI examples, you'll also need `jq` and `uuidgen`.

**Installing Prerequisites:**
- **macOS:** `brew install jq` (uuidgen is built-in)
- **Ubuntu/Debian:** `sudo apt-get install jq uuid-runtime`
- **CentOS/RHEL:** `sudo yum install jq util-linux`
- **Windows:** Use WSL2 or install via package managers

```bash
docker compose up -d --build
```

Wait ~10-20s for migrations and seeding to complete.

Health check:
```bash
curl -s http://localhost:8080/health
```

## API Usage

### Authentication

Login with seeded user:
```bash
curl -s http://localhost:8080/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"password"}' | jq .
```

Extract token for subsequent requests:
```bash
curl -s http://localhost:8080/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"password"}' | jq -r .token > .token
TOKEN=$(cat .token)
```

### Task Management

Create task (requires Idempotency-Key):
```bash
# Single command approach 
TOKEN=$(curl -s http://localhost:8080/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"password"}' | jq -r .token) && \
curl -s http://localhost:8080/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Buy milk","status":"pending"}' | jq .
```

List tasks (paginated, cached 30s):
```bash
# Single command approach 
TOKEN=$(curl -s http://localhost:8080/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"password"}' | jq -r .token) && \
curl -s "http://localhost:8080/tasks?status=pending&page=1&limit=10" \
  -H "Authorization: Bearer $TOKEN" | jq .
```
#### Error Handling & Security

Test invalid login (shows error envelope):
```bash
curl -i http://localhost:8080/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"wrong@example.com","password":"wrong"}'
```

Test unauthorized access:
```bash
# No token
curl -i http://localhost:8080/tasks

# Invalid token
curl -i http://localhost:8080/tasks -H "Authorization: Bearer invalid"
```

#### Idempotency Testing

Create task and test replay behavior:
```bash
# Create with idempotency key
IDEM_KEY=$(uuidgen)
curl -i http://localhost:8080/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Demo Task","status":"pending"}'

# Replay same request (expect identical response)
curl -i http://localhost:8080/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Demo Task","status":"pending"}'

# Replay with different body (expect 409 conflict)
curl -i http://localhost:8080/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"title":"Changed Title","status":"pending"}'
```


## Key Features

### Security & Authentication
- JWT (HS256) verification on `/tasks/*` endpoints
- Bearer token authentication with `aud=task-api` and `iss=http://auth-service`
- JWT claims include: `sub`, `role`, `iss`, `aud`, `exp`
- JWT expiration: 15 minutes
- Rate limiting: 60 requests/minute per IP

### Idempotency & Caching
- POST `/tasks` idempotent via `Idempotency-Key` header
- GET `/tasks` cached for 30s per user + filter combination
- Cache invalidation on task creation
- Redis-backed caching and login throttling

### Observability
- **X-Request-Id** propagation through all services
- Structured JSON logs include: request ID, user (sub), route, status, latency
- Uniform error envelopes: `{ "code": "validation_error", "message": "Description", "details": { ... } }`

## Error Handling

All API responses follow consistent error envelope format:
```json
{
  "code": "validation_error",
  "message": "The given data was invalid",
  "details": {
    "email": ["The email field is required"]
  }
}
```

**HTTP Status Codes:**
- `400` - Bad Request (e.g., missing Idempotency-Key)
- `401` - Unauthorized (missing/invalid JWT)
- `403` - Forbidden (valid JWT, insufficient permissions)
- `404` - Not Found
- `409` - Conflict (idempotency conflict)
- `422` - Validation Error
- `5xx` - Server Error

## Testing

Run the integration test suite:
```bash
chmod +x tests/integration-test.sh
./tests/integration-test.sh
```

**Test Coverage:**
- Happy path: login → create task → list tasks
- Idempotency: duplicate requests with same key
- Unauthorized access attempts
- JWT verification on protected endpoints

## API Documentation

**OpenAPI Specifications:**
- Auth Service: [`openapi/auth.yaml`](./openapi/auth.yaml)
- Task Service: [`openapi/tasks.yaml`](./openapi/tasks.yaml)

Import into Swagger UI, Insomnia or Postman for interactive testing.

## Configuration

**Default Credentials:** `test@example.com` / `password`

**Service Ports:**
- Nginx (public): `http://localhost:8080`
- API Gateway: `9000` (internal)
- Auth Service: `9001` (internal)  
- Task Service: `9002` (internal)

**Environment Variables:**
- JWT secret, audience and issuer configured via `docker-compose.yml`
- Database connections and Redis settings per service
- See individual service `.env.example` files for full configuration options

## Roadmap (Next Steps)

- Enabling HTTPS with valid certificates
- Storing secrets securely (not in the compose file)
- Using smaller, production-ready Docker images
- Adding basic monitoring and alerts (logs and metrics)
- Improving rate limiting and caching as traffic grows
- Making the database more robust (indexes, connection pooling)
- Tightening API validation and sensible limits

## Documentation

- [Architecture & Approach](./APPROACH.md) - Design decisions and data models
- [Development Tradeoffs](./TRADEOFFS.md) - Technical decisions while developing
