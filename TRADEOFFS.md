# Development Tradeoffs

This document outlines the implementation decisions, compromises and tradeoffs I made while developing the Laravel API Platform. The choices not specified in the requirements where I had to evaluate multiple valid approaches. 

## Implementation Decisions

### JWT Verification Strategy
**Decision:** Custom middleware in API Gateway vs Laravel Passport/Sanctum  
**Tradeoff:** Control and simplicity vs feature completeness  
**Reasoning:**
- Requirements specified HS256 with shared secret (not OAuth flows)
- Custom middleware gives exact control over verification logic
- **Cost:** Had to implement token parsing, validation and error handling manually
- **Alternatives considered:** Laravel Passport (OAuth2) or Sanctum (overkill for simple JWT verification)

### Idempotency Storage Design
**Decision:** Store full HTTP response body in database vs just success indicator  
**Tradeoff:** Storage overhead vs exact response replay  
**Reasoning:**
- Guarantees identical response for duplicate requests (including timestamps, IDs)
- Handles edge cases like different error messages for same validation failure
- **Cost:** about 1 KB per request, potential JSON serialization issues
- **Alternative considered:** Store just task ID + status code (90% smaller but less reliable)

### Cache Key Strategy
**Decision:** Hash-based cache keys vs simple concatenation  
**Tradeoff:** Collision safety vs readability  
**Reasoning:**
- `tasks:list:{userId}:{hash(filters)}` prevents key length issues
- Handles complex filter combinations safely
- **Cost:** Harder to debug cache keys, hash computation overhead
- **Alternative considered:** `tasks:list:{userId}:status={status}&page={page}` (readable but can exceed Redis key limits)

## Additional Design Choices

### Error Response Consistency
**Decision:** Custom exception handler vs per-controller error handling  
**Tradeoff:** Global consistency vs granular control  
**Reasoning:**
- Single point of truth for error format across all services
- Automatic handling of Laravel validation errors
- **Cost:** Less flexibility for service-specific error details
- **Alternative considered:** Manual error formatting (more control but inconsistent)

## Development Decisions

### Container Development Strategy
**Decision:** Volume mounts for live code reload vs rebuild on changes  
**Tradeoff:** Development speed vs production similarity  
**Reasoning:**
- Instant feedback loop during development
- No need to rebuild containers for code changes
- **Cost:** Development environment differs from production
- **Alternative considered:** Multi-stage builds with dev/prod targets (slower iteration)

## Service Integration

### Request ID Propagation
**Decision:** Middleware-based vs manual header passing  
**Tradeoff:** Automatic propagation vs explicit control  
**Reasoning:**
- Middleware ensures no request lacks correlation ID
- Automatic forwarding to downstream services
- **Cost:** Hidden behavior, harder to debug if middleware fails
- **Alternative considered:** Manual header management (explicit but error-prone)

---

These decisions focus on practical implementation choices made during development within the constraints of the assignment.
