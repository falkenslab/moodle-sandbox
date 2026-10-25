# The sandbox's image: Moodle HQ's, with Moodle's code inside (and its Composer dependencies),
# instead of a bind mount of src/. On Windows and macOS, reading Moodle's ~58000 PHP files through
# Docker Desktop made every page several times slower; inside the image the code is immutable
# and the opcache compiles it once (php/zz-sandbox.ini).
#
# The site doesn't live here: the database and moodledata stay in their volumes, and config.php
# reads the database password and the URL from the environment. Changing Moodle's version means
# building another image (MOODLE_VERSION) without touching the data.
FROM moodlehq/moodle-php-apache:8.3-bullseye

# A Moodle release (a tag of its repository), not a branch: so every build of the image gives the
# same Moodle, on any machine and at any time.
ARG MOODLE_VERSION=v5.2.4

# A shallow clone of that tag, without .git, which Moodle doesn't need. From /: the base image
# works in /var/www/html, which is deleted to clone there.
WORKDIR /
RUN rm -rf /var/www/html \
    && git clone --depth 1 --branch "${MOODLE_VERSION}" https://github.com/moodle/moodle.git /var/www/html \
    && rm -rf /var/www/html/.git

WORKDIR /var/www/html

# With the dev dependencies: the seeds use Moodle's data generators. Their versions are pinned by
# Moodle's composer.lock.
RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress

# Plugins that don't ship with Moodle (docker/plugins.txt), each in the release made for the
# image's Moodle series. After composer: changing the list doesn't redo that layer, the slowest.
COPY docker/plugins.txt docker/install-plugins.sh /tmp/plugins/
RUN bash /tmp/plugins/install-plugins.sh /tmp/plugins/plugins.txt "${MOODLE_VERSION}" && rm -rf /tmp/plugins

COPY docker/config.php /var/www/html/config.php
COPY php/zz-sandbox.ini /usr/local/etc/php/conf.d/zz-sandbox.ini

# Where the image comes from: "local" if it's built on the machine (npm run build, or up when it
# can't pull it), the repository's tag if the workflow publishes it (v5.2.4-3). `up` pulls the
# newest version of a published image, but doesn't overwrite one built on the machine.
ARG SANDBOX_BUILD=local

LABEL org.opencontainers.image.source="https://github.com/falkenslab/moodle-sandbox" \
      org.opencontainers.image.description="Moodle with its code inside, for falkenslab's test sandbox" \
      org.falkenslab.sandbox.build="${SANDBOX_BUILD}"
