#!/bin/sh
# Runs before the web server, queue worker or scheduler starts.
set -e
cd /app

# storage/ is a volume; make sure Laravel's folders exist in it.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs

# Caches need the runtime .env (mounted by Mini Forge), so they are built
# here instead of in the image.
if [ -f .env ]; then
  php artisan optimize --quiet
  if php artisan list --raw 2> /dev/null | grep -q '^filament:optimize '; then
    php artisan filament:optimize --quiet
  fi
fi

exec "$@"
