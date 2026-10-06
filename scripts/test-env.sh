#!/usr/bin/env bash
# Throwaway WordPress for testing the plugins in a Claude Code cloud session
# (or any Linux box with Docker). Not for production.
#
#   scripts/test-env.sh up      # start MariaDB + WordPress at http://127.0.0.1 (admin / admin)
#   scripts/test-env.sh wp ...  # run WP-CLI, e.g. scripts/test-env.sh wp plugin list
#   scripts/test-env.sh down    # remove the containers and volumes
#
# Mounts packages/waas-host from this checkout, plus FluentCart, WooCommerce
# and Subscriptions for WooCommerce from wordpress.org. Images come from
# mirror.gcr.io because Docker Hub rate-limits shared addresses. When
# HTTPS_PROXY is set (the cloud agent proxy), WordPress sends outbound HTTP
# through it and trusts its CA, so the plugin can reach the Postman mock.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=${WAAS_TEST_ENV_DIR:-$ROOT/.test-env}
PLUGINS="fluent-cart woocommerce subscriptions-for-woocommerce"
WP_IMAGE=mirror.gcr.io/library/wordpress:php8.3-apache
CLI_IMAGE=mirror.gcr.io/library/wordpress:cli
DB_IMAGE=mirror.gcr.io/library/mariadb:11
CA_BUNDLE=${AGENT_PROXY_CA:-/root/.ccr/ca-bundle.crt}
DB_ENV=(-e WORDPRESS_DB_HOST=127.0.0.1:3306 -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp)

proxy_port() {
    # Port of HTTPS_PROXY (it changes when the container restarts); empty if unset.
    [ -n "${HTTPS_PROXY:-}" ] && echo "$HTTPS_PROXY" | sed -E 's#.*:([0-9]+)/?$#\1#' || true
}

wp_cli() {
    docker run --rm --network host --volumes-from waas-wp --user 33:33 \
        -e AGENT_PROXY_PORT="$(proxy_port)" "${DB_ENV[@]}" "$CLI_IMAGE" wp "$@"
}

up() {
    docker info >/dev/null 2>&1 || { (dockerd >/tmp/dockerd.log 2>&1 &); for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 1; done; }

    mkdir -p "$WORK/plugins" "$WORK/mu-plugins"
    for p in $PLUGINS; do
        [ -d "$WORK/plugins/$p" ] && continue
        curl -sSfL -o "$WORK/$p.zip" "https://downloads.wordpress.org/plugin/$p.zip"
        unzip -qo "$WORK/$p.zip" -d "$WORK/plugins"
    done

    local ca_mount=() 
    if [ -n "$(proxy_port)" ] && [ -f "$CA_BUNDLE" ]; then
        ca_mount=(-v "$CA_BUNDLE:/etc/agent-proxy-ca.crt:ro")
        cat >"$WORK/mu-plugins/agent-proxy.php" <<'PHP'
<?php
// Test environment only: outbound HTTP through the agent proxy, trusting its CA.
if (getenv('AGENT_PROXY_PORT')) {
    define('WP_PROXY_HOST', '127.0.0.1');
    define('WP_PROXY_PORT', getenv('AGENT_PROXY_PORT'));
    define('WP_PROXY_BYPASS_HOSTS', 'localhost,127.0.0.1');
    add_filter('http_request_args', function ($args) {
        $args['sslcertificates'] = '/etc/agent-proxy-ca.crt';
        return $args;
    });
}
PHP
    fi

    (cd "$ROOT/packages/waas-host" && composer dump-autoload -q)

    docker rm -f waas-db waas-wp >/dev/null 2>&1 || true
    docker run -d --name waas-db --network host -v waas-db-data:/var/lib/mysql \
        -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
        "$DB_IMAGE" >/dev/null

    local plugin_mounts=()
    for p in $PLUGINS; do plugin_mounts+=(-v "$WORK/plugins/$p:/var/www/html/wp-content/plugins/$p"); done
    docker run -d --name waas-wp --network host -e AGENT_PROXY_PORT="$(proxy_port)" -e WORDPRESS_DEBUG=1 "${DB_ENV[@]}" \
        -v waas-wp-html:/var/www/html \
        -v "$ROOT/packages/waas-host:/var/www/html/wp-content/plugins/waas-host" \
        -v "$WORK/mu-plugins:/var/www/html/wp-content/mu-plugins" \
        "${plugin_mounts[@]}" "${ca_mount[@]}" "$WP_IMAGE" >/dev/null

    for _ in $(seq 1 60); do
        wp_cli db check >/dev/null 2>&1 && break
        sleep 2
    done
    if ! wp_cli core is-installed >/dev/null 2>&1; then
        wp_cli core install --url=http://127.0.0.1 --title="WaaS Storefront (test)" \
            --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
    fi
    wp_cli plugin activate $PLUGINS waas-host
    echo "WordPress: http://127.0.0.1/wp-admin (admin / admin)"
}

down() {
    docker rm -f waas-wp waas-db >/dev/null 2>&1 || true
    docker volume rm waas-wp-html waas-db-data >/dev/null 2>&1 || true
}

case "${1:-}" in
    up) up ;;
    down) down ;;
    wp) shift; wp_cli "$@" ;;
    *) sed -n '2,8p' "$0"; exit 1 ;;
esac
