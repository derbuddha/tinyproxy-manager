#!/bin/bash
# Fix permissions on volume-mounted files so www-data (PHP-FPM) can write
chown www-data:www-data /app/blocked-domains.txt 2>/dev/null || chmod 666 /app/blocked-domains.txt 2>/dev/null
chown www-data:www-data /app/allowed-domains.txt 2>/dev/null || chmod 666 /app/allowed-domains.txt 2>/dev/null
chown www-data:www-data /app/upstream.conf 2>/dev/null || chmod 666 /app/upstream.conf 2>/dev/null
chown www-data:www-data /app/tinyproxy.conf 2>/dev/null || chmod 666 /app/tinyproxy.conf 2>/dev/null
chown www-data:www-data /app/allowed-containers.txt 2>/dev/null || chmod 666 /app/allowed-containers.txt 2>/dev/null
chown www-data:www-data /app/blocked-containers.txt 2>/dev/null || chmod 666 /app/blocked-containers.txt 2>/dev/null
chown www-data:www-data /app/proxy-config.json 2>/dev/null || chmod 666 /app/proxy-config.json 2>/dev/null
chown www-data:www-data /app/traffic-history.json 2>/dev/null || chmod 666 /app/traffic-history.json 2>/dev/null
chown www-data:www-data /app/traffic-noise-filters.txt 2>/dev/null || chmod 666 /app/traffic-noise-filters.txt 2>/dev/null
touch /app/traffic-history.lock 2>/dev/null
chmod 666 /app/traffic-history.lock 2>/dev/null

# Allow www-data to use Docker socket for container restarts
if [ -S /var/run/docker.sock ]; then
    DOCKER_GID=$(stat -c '%g' /var/run/docker.sock)
    groupadd -g "$DOCKER_GID" dockerhost 2>/dev/null || true
    usermod -aG "$DOCKER_GID" www-data 2>/dev/null || true
    chmod 666 /var/run/docker.sock 2>/dev/null || true
fi

# Start PHP-FPM in the background
php-fpm -D

# Background traffic history logger - archives parsed log entries into traffic-history.json
# (capped at 10000), independent of whether the web GUI is open
php /var/www/html/traffic-logger.php >> /var/log/traffic-logger.log 2>&1 &

# Start Nginx in foreground
exec nginx -g 'daemon off;'
