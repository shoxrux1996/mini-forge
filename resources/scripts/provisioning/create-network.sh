if docker network inspect "$NETWORK" >/dev/null 2>&1; then
  echo "SKIPPED: Docker network $NETWORK already exists"
  exit 0
fi
docker network create "$NETWORK"
echo "Created Docker network $NETWORK"
