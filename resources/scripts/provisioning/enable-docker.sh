if systemctl is-enabled --quiet docker && systemctl is-active --quiet docker; then
  echo "SKIPPED: Docker is enabled at boot and running"
  exit 0
fi
systemctl enable --now docker
echo "Docker is $(systemctl is-active docker) and enabled at boot"
