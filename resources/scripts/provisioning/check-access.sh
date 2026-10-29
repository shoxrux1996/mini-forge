# Runs through `sudo -n` for non-root users, so a password prompt fails here.
echo "Commands run as $(id -un) on $(hostname)"
