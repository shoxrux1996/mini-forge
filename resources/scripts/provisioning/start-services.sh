cd "$ROOT/infrastructure"
docker compose pull --quiet
docker compose up -d --remove-orphans
for container in mini-forge-traefik mini-forge-postgres mini-forge-redis; do
  status=missing
  for _ in $(seq 1 30); do
    status=$(docker inspect -f '{{.State.Health.Status}}' "$container" 2>/dev/null || echo missing)
    [ "$status" = "healthy" ] && break
    sleep 2
  done
  if [ "$status" != "healthy" ]; then
    echo "$container did not become healthy (status: $status)" >&2
    docker logs --tail 30 "$container" >&2 || true
    exit 1
  fi
done
docker compose ps --format 'table {{.Name}}\t{{.Image}}\t{{.Status}}'
