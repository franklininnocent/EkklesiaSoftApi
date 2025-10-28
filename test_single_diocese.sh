#!/bin/bash
# Quick test for single endpoints

# Login
LOGIN_RESPONSE=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "franklininnocent.fs@gmail.com", "password": "Secrete*999"}')

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.access_token')

# Get first diocese ID
DIOCESES=$(curl -s -X GET "http://localhost:8000/api/ecclesiastical/dioceses?per_page=1" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json")

DIOCESE_ID=$(echo "$DIOCESES" | jq -r '.data.data[0].id')

echo "Testing diocese ID: $DIOCESE_ID"
echo ""

# Test single diocese
echo "=== Single Diocese ===" 
curl -s -X GET "http://localhost:8000/api/ecclesiastical/dioceses/$DIOCESE_ID" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.'

echo ""
echo "=== Statistics ==="
curl -s -X GET "http://localhost:8000/api/ecclesiastical/dioceses/statistics" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.'

echo ""
echo "=== Archdioceses ==="
curl -s -X GET "http://localhost:8000/api/ecclesiastical/dioceses/archdioceses" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.'

echo ""
echo "=== Bishops List ==="
curl -s -X GET "http://localhost:8000/api/ecclesiastical/bishops" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.'
