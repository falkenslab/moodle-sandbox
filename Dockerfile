# La imagen del sandbox: la de Moodle HQ con el código de Moodle dentro (y sus dependencias
# de Composer), en vez de un bind-mount de src/. En Windows y macOS, leer los ~58000 ficheros
# PHP de Moodle a través de Docker Desktop hacía cada página varias veces más lenta; dentro de
# la imagen, el código es inmutable y el opcache lo compila una vez (php/zz-sandbox.ini).
#
# El sitio no vive aquí: la base de datos y moodledata siguen en sus volúmenes, y config.php
# lee la contraseña de la base de datos y la URL del entorno. Cambiar de versión de Moodle es
# construir otra imagen (MOODLE_VERSION) sin tocar los datos.
FROM moodlehq/moodle-php-apache:8.3-bullseye

# Una versión publicada de Moodle (una etiqueta de su repositorio), no una rama: así cada
# construcción de la imagen da el mismo Moodle, en cualquier máquina y en cualquier momento.
ARG MOODLE_VERSION=v5.2.4

# Un clon superficial de esa etiqueta; sin .git, que Moodle no necesita. Desde /: la imagen
# base trabaja en /var/www/html, que se borra para clonar ahí.
WORKDIR /
RUN rm -rf /var/www/html \
    && git clone --depth 1 --branch "${MOODLE_VERSION}" https://github.com/moodle/moodle.git /var/www/html \
    && rm -rf /var/www/html/.git

WORKDIR /var/www/html

# Plugins que no vienen con Moodle, también en una versión fija. El sitio los tiene instalados:
# sin su código, Moodle falla en las páginas que recorren los módulos (el banco de preguntas,
# por ejemplo). mod_questionnaire: las encuestas tipo cuestionario.
ARG QUESTIONNAIRE_VERSION=v5.0.3
RUN git clone --depth 1 --branch "${QUESTIONNAIRE_VERSION}" https://github.com/PoetOS/moodle-mod_questionnaire.git public/mod/questionnaire \
    && rm -rf public/mod/questionnaire/.git

# Con las dependencias de desarrollo: las siembras usan los generadores de datos de Moodle. Las
# versiones las fija el composer.lock de Moodle.
RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress

COPY docker/config.php /var/www/html/config.php
COPY php/zz-sandbox.ini /usr/local/etc/php/conf.d/zz-sandbox.ini

LABEL org.opencontainers.image.source="https://github.com/falkenslab/moodle-sandbox" \
      org.opencontainers.image.description="Moodle con su código dentro, para el sandbox de pruebas de falkenslab"
