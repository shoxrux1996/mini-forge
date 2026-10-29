if docker compose version >/dev/null 2>&1; then
  echo "SKIPPED: Docker Compose $(docker compose version --short) is already installed"
  exit 0
fi
if [ ! -f /etc/apt/sources.list.d/docker.sources ] && [ ! -f /etc/apt/sources.list.d/docker.list ]; then
  echo "Docker is installed, but not from Docker's apt repository, so Mini Forge won't add the Compose plugin to it. Install docker-compose-plugin yourself and provision again." >&2
  exit 1
fi
DEBIAN_FRONTEND=noninteractive apt-get install -y -q docker-compose-plugin
docker compose version
