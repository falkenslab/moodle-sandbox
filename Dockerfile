# La imagen del sandbox: la de Moodle HQ con el código de Moodle dentro (y sus dependencias
# de Composer), en vez de un bind-mount de src/. En Windows y macOS, leer los ~58000 ficheros
# PHP de Moodle a través de Docker Desktop hacía cada página varias veces más lenta; dentro de
# la imagen, el código es inmutable y el opcache lo compila una vez (php/zz-sandbox.ini).
#
# El sitio no vive aquí: la base de datos y moodledata siguen en sus volúmenes, y config.php
# lee la contraseña de la base de datos y la URL del entorno. Cambiar de versión de Moodle es
# construir otra imagen (MOODLE_BRANCH) sin tocar los datos.
FROM moodlehq/moodle-php-apache:8.3-bullseye

ARG MOODLE_BRANCH=MOODLE_502_STABLE

# Un clon superficial de una rama; sin .git, que Moodle no necesita. Desde /: la imagen base
# trabaja en /var/www/html, que se borra para clonar ahí.
WORKDIR /
RUN rm -rf /var/www/html \
    && git clone --depth 1 --branch "${MOODLE_BRANCH}" https://github.com/moodle/moodle.git /var/www/html \
    && rm -rf /var/www/html/.git

WORKDIR /var/www/html

# Con las dependencias de desarrollo: las siembras usan los generadores de datos de Moodle.
RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress

COPY docker/config.php /var/www/html/config.php
COPY php/zz-sandbox.ini /usr/local/etc/php/conf.d/zz-sandbox.ini
