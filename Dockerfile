FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends age bzip2 ca-certificates curl default-mysql-client \
    && docker-php-ext-install pdo_mysql

RUN curl -LJ -o /tmp/s5cmd.tar.gz https://github.com/peak/s5cmd/releases/download/v2.2.2/s5cmd_2.2.2_Linux-64bit.tar.gz \
    && mkdir /tmp/s5cmd \
    && tar -xvzf /tmp/s5cmd.tar.gz -C /tmp/s5cmd \
    && mv /tmp/s5cmd/s5cmd /usr/bin \
    && chmod +x /usr/bin/s5cmd \
    && rm -rf /tmp/s5cmd*

RUN mkdir -p /app
WORKDIR /app

COPY backup.php /app/backup.php

ENTRYPOINT ["php", "/app/backup.php"]
