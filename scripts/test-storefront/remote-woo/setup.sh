#!/bin/sh
# WooCommerce test storefront setup steps, run by Coolify scheduled tasks as root in the wordpress container.
#   sh /opt/wpcli/x/setup.sh <step>
# Steps, in order: bootstrap install plugins configure pages product payments cron (check any time).
#   sh /opt/wpcli/x/setup.sh renew <subscription id>   # make a subscription's renewal due now
#   sh /opt/wpcli/x/setup.sh watch                     # read-only lifecycle status of the latest subscriptions
set -eu
X=/opt/wpcli/x
WP="sh $X/wp"
URL=https://storefront-woo-test.191.101.15.236.sslip.io
case "${1:-}" in
  bootstrap)
    mkdir -p /opt/wpcli
    curl -fsSL -o /opt/wpcli/wp-cli.phar https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
    $WP --version ;;
  install)
    $WP core is-installed && { echo already installed; exit 0; }
    [ -n "${WP_ADMIN_PASSWORD:-}" ] || { echo WP_ADMIN_PASSWORD missing; exit 1; }
    $WP core install --url="$URL" --title="WaaS Storefront Woo (test)" --admin_user=admin \
      --admin_password="$WP_ADMIN_PASSWORD" --admin_email=admin@otakusolutions.io --skip-email
    $WP option update blog_public 0
    $WP rewrite structure '/%postname%/' --hard ;;
  plugins)
    # wordpress.org plugins: install once, then leave updates to wp-admin. Re-installing one that a newer release
    # exists for fails ("Destination folder already exists").
    for p in woocommerce subscriptions-for-woocommerce woocommerce-gateway-stripe fluent-smtp; do
      $WP plugin is-installed $p || $WP plugin install $p
      $WP plugin activate $p
    done
    $WP plugin install $X/waas-host.zip --activate --force
    # Test-only: lets Cash on delivery pay for a subscription (see waas-test-storefront-cod-shim.php).
    mkdir -p /var/www/html/wp-content/mu-plugins
    cp $X/waas-test-storefront-cod-shim.php /var/www/html/wp-content/mu-plugins/
    chown -R 33:33 /var/www/html/wp-content/mu-plugins
    $WP plugin list ;;
  configure)
    [ -n "${WAAS_API_KEY:-}" ] && [ -n "${WAAS_API_SECRET:-}" ] || { echo WAAS_API_KEY/SECRET missing; exit 1; }
    $WP option update wpcs_credentials_api_base_url_setting https://waas-api.otakusolutions.io
    $WP option update wpcs_credentials_api_key_setting "$WAAS_API_KEY" >/dev/null
    $WP option update wpcs_credentials_api_secret_setting "$WAAS_API_SECRET" >/dev/null
    echo api key and secret set
    $WP option update wpcs_notification_settings_send_tenant_ready_email on
    $WP eval-file $X/check.php
    $WP eval-file $X/roles.php ;;
  pages)
    $WP eval-file $X/store.php --user=admin
    $WP rewrite flush
    $WP post list --post_type=page --fields=ID,post_title ;;
  product)
    $WP eval-file $X/product.php --user=admin ;;
  payments)
    $WP eval-file $X/payments.php --user=admin ;;
  cron)
    # WordPress no longer runs cron on page loads; the service's every-minute wp-cron task does.
    $WP config set DISABLE_WP_CRON true --raw --type=constant
    $WP config get DISABLE_WP_CRON ;;
  renew)
    [ -n "${2:-}" ] || { echo "usage: renew <subscription id>"; exit 2; }
    $WP eval-file $X/renew.php "$2" --user=admin ;;
  check)
    $WP eval-file $X/check.php ;;
  watch)
    # Read-only: subscription status, next payment date, and waas-host's store meta for the latest subscriptions.
    $WP eval-file $X/watch.php ;;
  *) echo "unknown step"; exit 2 ;;
esac
