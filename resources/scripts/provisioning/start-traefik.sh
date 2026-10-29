cd "$ROOT/infrastructure"
docker compose pull --quiet
docker compose up -d --remove-orphans
status=missing
for _ in $(seq 1 30); do
  status=$(docker inspect -f '{{.State.Health.Status}}' mini-forge-traefik 2>/dev/null || echo missing)
  if [ "$status" = "healthy" ]; then
    docker compose ps --format 'table {{.Name}}\t{{.Image}}\t{{.Status}}'
    exit 0
  fi
  sleep 2
done
echo "Traefik did not become healthy (status: $status)" >&2
docker compose logs --tail 30 traefik >&2
exit 1
