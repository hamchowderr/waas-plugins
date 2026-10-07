#!/bin/sh
# Test storefront setup steps, run by Coolify scheduled tasks as root in the wordpress container.
#   sh /opt/wpcli/x/setup.sh <step>
# Steps, in order: bootstrap install plugins configure pages product payments (check any time).
set -eu
X=/opt/wpcli/x
WP="sh $X/wp"
URL=https://storefront-test.191.101.15.236.sslip.io
case "${1:-}" in
  bootstrap)
    mkdir -p /opt/wpcli
    curl -fsSL -o /opt/wpcli/wp-cli.phar https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
    $WP --version ;;
  install)
    $WP core is-installed && { echo already installed; exit 0; }
    [ -n "${WP_ADMIN_PASSWORD:-}" ] || { echo WP_ADMIN_PASSWORD missing; exit 1; }
    $WP core install --url="$URL" --title="WaaS Storefront (test)" --admin_user=admin \
      --admin_password="$WP_ADMIN_PASSWORD" --admin_email=admin@otakusolutions.io --skip-email
    $WP option update blog_public 0
    $WP rewrite structure '/%postname%/' --hard ;;
  plugins)
    $WP plugin install fluent-cart --activate
    $WP plugin install $X/waas-host.zip --activate --force
    $WP plugin install $X/waas-payments.zip --activate --force
    $WP plugin list ;;
  configure)
    [ -n "${WAAS_API_KEY:-}" ] && [ -n "${WAAS_API_SECRET:-}" ] || { echo WAAS_API_KEY/SECRET missing; exit 1; }
    $WP option update wpcs_credentials_api_base_url_setting https://waas-api.otakusolutions.io
    $WP option update wpcs_credentials_api_key_setting "$WAAS_API_KEY" >/dev/null
    $WP option update wpcs_credentials_api_secret_setting "$WAAS_API_SECRET" >/dev/null
    echo api key and secret set
    $WP eval-file $X/check.php ;;
  pages)
    $WP eval 'if (!(new FluentCart\Api\StoreSettings())->get("checkout_page_id")) (new FluentCart\App\CPT\Pages())->createPages();' --user=admin
    $WP rewrite flush
    $WP post list --post_type=page --fields=ID,post_title ;;
  product)
    $WP eval-file $X/product.php --user=admin ;;
  payments)
    $WP eval-file $X/payments.php --user=admin ;;
  check)
    $WP eval-file $X/check.php ;;
  *) echo "unknown step"; exit 2 ;;
esac
