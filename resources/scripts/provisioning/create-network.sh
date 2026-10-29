created=0
if ! docker network inspect "$NETWORK" >/dev/null 2>&1; then
  docker network create "$NETWORK" >/dev/null
  echo "Created Docker network $NETWORK"
  created=1
fi
# No outside access: sites reach Postgres and Redis here, nothing else.
if ! docker network inspect "$DATA_NETWORK" >/dev/null 2>&1; then
  docker network create --internal "$DATA_NETWORK" >/dev/null
  echo "Created internal Docker network $DATA_NETWORK"
  created=1
fi
if [ "$created" = 0 ]; then
  echo "SKIPPED: Docker networks $NETWORK and $DATA_NETWORK already exist"
fi
