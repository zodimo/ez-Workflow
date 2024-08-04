ARG PHP_EXTENSIONS=""

FROM thecodingmachine/php:7.4-v4-fpm-node20

USER root

RUN apt-get update \
    && apt-get install -y iputils-ping telnet \
    # cleanup
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

USER docker

WORKDIR /workspace

