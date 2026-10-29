. /etc/os-release
if [ "${ID:-}" != "ubuntu" ]; then
  echo "Mini Forge supports Ubuntu; this server runs ${PRETTY_NAME:-an unknown OS}." >&2
  exit 1
fi
if [ "${VERSION_ID%%.*}" -lt 22 ]; then
  echo "Ubuntu ${VERSION_ID} is too old; use 22.04 or newer." >&2
  exit 1
fi
arch=$(uname -m)
case "$arch" in
  x86_64|aarch64) ;;
  *) echo "Unsupported CPU architecture: $arch" >&2; exit 1 ;;
esac
for tool in apt-get systemctl ss awk df; do
  command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 1; }
done
memory=$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo)
echo "${PRETTY_NAME} (${arch}), $(nproc) CPU, ${memory} MB RAM, $(df -h --output=avail / | tail -n 1 | tr -d ' ') disk free"
if [ "$memory" -lt 900 ]; then
  echo "WARNING: less than 1 GB of RAM; expect room for one small site."
fi
