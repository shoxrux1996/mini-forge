# A disposable "VPS" for trying provisioning locally: Ubuntu 24.04 with
# systemd and sshd, and a `deploy` user with passwordless sudo.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends systemd systemd-sysv openssh-server sudo iproute2 \
 && rm -rf /var/lib/apt/lists/* \
 && systemctl enable ssh \
 && useradd -m -s /bin/bash deploy \
 && install -d -m 700 -o deploy -g deploy /home/deploy/.ssh \
 && echo 'deploy ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/deploy

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
