if [ "$(docker inspect -f '{{.State.Running}}' mini-forge-traefik 2>/dev/null || true)" = "true" ]; then
  echo "SKIPPED: Mini Forge's Traefik already serves ports 80 and 443"
  exit 0
fi
busy=$(ss -ltnpH '( sport = :80 or sport = :443 )' || true)
if [ -n "$busy" ]; then
  echo "Ports 80/443 are in use by another service. Mini Forge does not stop other services; free the ports and provision again:" >&2
  echo "$busy" >&2
  exit 1
fi
echo "Ports 80 and 443 are free"
if command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
  if ! ufw status | grep -Eq '^(80|443|80,443)(/tcp)?[[:space:]]+ALLOW'; then
    echo "WARNING: ufw is active and may block ports 80/443. Mini Forge does not change firewall rules; to open them run: ufw allow 80,443/tcp"
  fi
fi
