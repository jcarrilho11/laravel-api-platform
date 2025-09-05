#!/bin/bash

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}=== Laravel API Platform Test Suite ===${NC}\n"

# Base URL
BASE_URL="http://localhost:8080"

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Function to print test results
print_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ $2${NC}"
        ((TESTS_PASSED++))
    else
        echo -e "${RED}✗ $2${NC}"
        ((TESTS_FAILED++))
    fi
}

# 1. Test Authentication
echo -e "${YELLOW}1. Testing Authentication Service${NC}"
echo "   Testing login with valid credentials..."
RESPONSE=$(curl -s -X POST $BASE_URL/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}' \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')
TOKEN=$(echo "$BODY" | grep -o '"token":"[^"]*' | cut -d'"' -f4)

if [ "$HTTP_CODE" = "200" ] && [ ! -z "$TOKEN" ]; then
    print_result 0 "Login successful (HTTP $HTTP_CODE)"
    echo "   Token: ${TOKEN:0:50}..."
else
    print_result 1 "Login failed (HTTP $HTTP_CODE)"
    echo "   Response: $BODY"
    exit 1
fi

# 2. Test invalid login
echo "   Testing login with invalid credentials..."
RESPONSE=$(curl -s -X POST $BASE_URL/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"wrong@example.com","password":"wrongpass"}' \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
if [ "$HTTP_CODE" = "401" ]; then
    print_result 0 "Invalid login correctly rejected (HTTP $HTTP_CODE)"
else
    print_result 1 "Invalid login handling incorrect (HTTP $HTTP_CODE)"
fi

# 3. Test Task Creation
echo -e "\n${YELLOW}2. Testing Task Service - Create Task${NC}"
echo "   Creating a new task..."
TASK_RESPONSE=$(curl -s -X POST $BASE_URL/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: create-task-$(date +%s)" \
  -d '{"title":"Test Task","description":"This is a test task","status":"pending"}' \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$TASK_RESPONSE" | tail -n1)
BODY=$(echo "$TASK_RESPONSE" | sed '$d')
TASK_ID=$(echo "$BODY" | grep -o '"id":"[^"]*' | cut -d'"' -f4)

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
    if [ ! -z "$TASK_ID" ]; then
        print_result 0 "Task created successfully (HTTP $HTTP_CODE)"
        echo "   Task ID: $TASK_ID"
    else
        print_result 1 "Task creation failed - no ID returned (HTTP $HTTP_CODE)"
        echo "   Response: $BODY"
    fi
else
    print_result 1 "Task creation failed (HTTP $HTTP_CODE)"
    echo "   Response: $BODY"
fi

# 4. Test Get All Tasks
echo -e "\n${YELLOW}3. Testing Task Service - Get All Tasks${NC}"
RESPONSE=$(curl -s -X GET $BASE_URL/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

if [ "$HTTP_CODE" = "200" ]; then
    TASK_COUNT=$(echo "$BODY" | grep -o '"id"' | wc -l)
    print_result 0 "Retrieved tasks successfully (HTTP $HTTP_CODE, Count: $TASK_COUNT)"
else
    print_result 1 "Failed to retrieve tasks (HTTP $HTTP_CODE)"
fi

# Note: Single task GET, UPDATE, and DELETE endpoints are not implemented in this version

# 5. Test Idempotency
echo -e "\n${YELLOW}4. Testing Idempotency${NC}"
IDEMPOTENCY_KEY="test-key-$(date +%s)"
echo "   Creating task with idempotency key: $IDEMPOTENCY_KEY"

# First request
RESPONSE1=$(curl -s -X POST $BASE_URL/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMPOTENCY_KEY" \
  -d '{"title":"Idempotent Task","description":"Testing idempotency","status":"pending"}' \
  -w "\n%{http_code}")

HTTP_CODE1=$(echo "$RESPONSE1" | tail -n1)
BODY1=$(echo "$RESPONSE1" | sed '$d')
TASK_ID1=$(echo "$BODY1" | grep -o '"id":"[^"]*' | cut -d'"' -f4)

# Second request with same idempotency key
RESPONSE2=$(curl -s -X POST $BASE_URL/tasks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMPOTENCY_KEY" \
  -d '{"title":"Idempotent Task","description":"Testing idempotency","status":"pending"}' \
  -w "\n%{http_code}")

HTTP_CODE2=$(echo "$RESPONSE2" | tail -n1)
BODY2=$(echo "$RESPONSE2" | sed '$d')
TASK_ID2=$(echo "$BODY2" | grep -o '"id":"[^"]*' | cut -d'"' -f4)

if ([ "$HTTP_CODE1" = "200" ] || [ "$HTTP_CODE1" = "201" ]) && [ "$HTTP_CODE2" = "200" ] && [ "$TASK_ID1" = "$TASK_ID2" ]; then
    print_result 0 "Idempotency working correctly (same task ID returned)"
elif ([ "$HTTP_CODE1" = "200" ] || [ "$HTTP_CODE1" = "201" ]) && [ "$HTTP_CODE2" = "409" ]; then
    print_result 0 "Idempotency working (409 Conflict on duplicate)"
else
    print_result 1 "Idempotency not working as expected"
    echo "   First response: HTTP $HTTP_CODE1, ID: $TASK_ID1"
    echo "   Second response: HTTP $HTTP_CODE2, ID: $TASK_ID2"
fi

# 6. Test Unauthorized Access
echo -e "\n${YELLOW}5. Testing Authorization${NC}"
echo "   Testing access without token..."
RESPONSE=$(curl -s -X GET $BASE_URL/tasks \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
if [ "$HTTP_CODE" = "401" ]; then
    print_result 0 "Unauthorized access correctly blocked (HTTP $HTTP_CODE)"
else
    print_result 1 "Authorization not working (HTTP $HTTP_CODE)"
fi

# 10. Test with Invalid Token
echo "   Testing with invalid token..."
RESPONSE=$(curl -s -X GET $BASE_URL/tasks \
  -H "Authorization: Bearer invalid_token_here" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
if [ "$HTTP_CODE" = "401" ]; then
    print_result 0 "Invalid token correctly rejected (HTTP $HTTP_CODE)"
else
    print_result 1 "Invalid token not rejected (HTTP $HTTP_CODE)"
fi

# Summary
echo -e "\n${YELLOW}=== Test Summary ===${NC}"
echo -e "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
echo -e "Tests Failed: ${RED}$TESTS_FAILED${NC}"

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "\n${GREEN}All tests passed successfully! ✓${NC}"
    exit 0
else
    echo -e "\n${RED}Some tests failed. Please review the output above.${NC}"
    exit 1
fi
