FROM php:8.2-fpm

# Install Nginx, curl, and docker.io
RUN apt-get update && apt-get install -y \
    nginx \
    curl \
    docker.io \
    && rm -rf /var/lib/apt/lists/*

# Copy PHP files
COPY index.php /var/www/html/
COPY style.css /var/www/html/
COPY api.php /var/www/html/
COPY favicon.ico /var/www/html/

# Create Nginx configuration
RUN echo 'server {\n\
    listen 80 default_server;\n\
    listen [::]:80 default_server;\n\
    \n\
    root /var/www/html;\n\
    index index.php index.html;\n\
    \n\
    server_name _;\n\
    \n\
    location / {\n\
        try_files $uri $uri/ =404;\n\
    }\n\
    \n\
    location ~ \.php$ {\n\
        include fastcgi_params;\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_index index.php;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
    }\n\
    \n\
    location ~ /\.ht {\n\
        deny all;\n\
    }\n\
}\n' > /etc/nginx/sites-available/default

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html

# Copy and set entrypoint
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
