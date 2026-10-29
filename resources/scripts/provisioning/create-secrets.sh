# Passwords for the shared Postgres and Redis are generated on the server and
# never leave it. Existing ones are kept.
cd "$ROOT/infrastructure"
if [ -f .env ] && [ -f redis/users.acl ]; then
  echo "SKIPPED: database passwords already exist"
  exit 0
fi
umask 077
if [ ! -f .env ]; then
  {
    echo "POSTGRES_PASSWORD=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    echo "REDIS_PASSWORD=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  } > .env
fi
. ./.env
install -d -m 0700 -o 999 -g 999 redis
if [ ! -f redis/users.acl ]; then
  hash=$(printf '%s' "$REDIS_PASSWORD" | sha256sum | cut -d' ' -f1)
  echo "user default on #$hash ~* &* +@all" > redis/users.acl
  chown 999:999 redis/users.acl
  chmod 600 redis/users.acl
fi
echo "Generated passwords for Postgres and Redis in $ROOT/infrastructure/.env"
