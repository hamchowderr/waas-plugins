#!/bin/sh
# Storefront setup steps, run by Coolify scheduled tasks as root in the wordpress container.
#   sh /opt/wpcli/x/setup.sh <step> [payments mode: store|gateway]
# Steps, in order: bootstrap install plugins configure pages product payments cron (check any time).
set -eu
X=/opt/wpcli/x
WP="sh $X/wp"
# The shop's compose sets these; the test storefront uses the defaults.
URL=${SITE_URL:-https://storefront-test.otakusolutions.io}
TITLE=${SITE_TITLE:-WaaS Storefront (test)}
case "${1:-}" in
  bootstrap)
    mkdir -p /opt/wpcli
    curl -fsSL -o /opt/wpcli/wp-cli.phar https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
    $WP --version ;;
  install)
    $WP core is-installed && { echo already installed; exit 0; }
    [ -n "${WP_ADMIN_PASSWORD:-}" ] || { echo WP_ADMIN_PASSWORD missing; exit 1; }
    $WP core install --url="$URL" --title="$TITLE" --admin_user=admin \
      --admin_password="$WP_ADMIN_PASSWORD" --admin_email=admin@otakusolutions.io --skip-email
    $WP option update blog_public 0
    $WP rewrite structure '/%postname%/' --hard ;;
  plugins)
    # wordpress.org plugins: install once, then just activate. Re-installing one that a newer release exists for
    # fails ("Destination folder already exists"); updating them is a separate step, done on purpose.
    # SHOP_PLUGINS (the shop's compose) adds the AnyPress store template's plugins, so the shop runs what it sells.
    for p in fluent-cart fluent-smtp ${SHOP_PLUGINS:-}; do
      $WP plugin is-installed $p || $WP plugin install $p
      $WP plugin activate $p
    done
    # FluentAuth's first run, as in the store template: its defaults, XML-RPC off.
    ! $WP plugin is-active fluent-security || $WP option get __fls_auth_settings >/dev/null 2>&1 ||
      $WP eval 'update_option("__fls_auth_settings", array_merge(\FluentAuth\App\Helpers\Helper::getAuthSettings(), ["disable_xmlrpc" => "yes"]));'
    $WP plugin install $X/waas-host.zip --activate --force
    [ ! -f $X/waas-payments.zip ] || $WP plugin install $X/waas-payments.zip --activate --force
    $WP plugin list ;;
  configure)
    [ -n "${WAAS_API_KEY:-}" ] && [ -n "${WAAS_API_SECRET:-}" ] || { echo WAAS_API_KEY/SECRET missing; exit 1; }
    $WP option update wpcs_credentials_api_base_url_setting "${WAAS_API_URL:-https://waas-api.otakusolutions.io}"
    $WP option update wpcs_credentials_api_key_setting "$WAAS_API_KEY" >/dev/null
    $WP option update wpcs_credentials_api_secret_setting "$WAAS_API_SECRET" >/dev/null
    echo api key and secret set
    $WP option update wpcs_notification_settings_send_tenant_ready_email on
    # Email (password resets, orders, store-ready notices) through the platform's relay, like a store's.
    if [ -n "${WAAS_MAIL_B64:-}" ]; then
      mkdir -p /var/www/html/wp-content/mu-plugins && cp $X/waas-mail.php /var/www/html/wp-content/mu-plugins/ && echo mail relay on
    fi
    $WP eval-file $X/check.php ;;
  pages)
    $WP eval 'if (!(new FluentCart\Api\StoreSettings())->get("checkout_page_id")) (new FluentCart\App\CPT\Pages())->createPages();' --user=admin
    $WP rewrite flush
    $WP post list --post_type=page --fields=ID,post_title ;;
  product)
    $WP eval-file $X/product.php --user=admin ;;
  payments)
    # Subscription mode: store (store-managed, the default) or gateway (Gateway managed: Stripe and PayPal bill).
    case "${2:-store}" in store|gateway) ;; *) echo "payments takes store or gateway"; exit 2 ;; esac
    $WP eval-file $X/payments.php "${2:-store}" --user=admin ;;
  cron)
    # WordPress no longer runs cron on page loads; the service's every-minute wp-cron task does.
    $WP config set DISABLE_WP_CRON true --raw --type=constant
    $WP config get DISABLE_WP_CRON ;;
  check)
    $WP eval-file $X/check.php ;;
  *) echo "unknown step"; exit 2 ;;
esac
