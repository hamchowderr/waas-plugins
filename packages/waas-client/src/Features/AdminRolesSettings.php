<?php

namespace WaaSClient\Features;

use WaaSClient\Core\HttpService;

class AdminRolesSettings
{
    const ROLES_FILE_DIR_PATH = WP_PLUGIN_DIR . '/waas-client-data/';
    const ROLES_FILE_PATH = WP_PLUGIN_DIR . '/waas-client-data/roles.json';

    private HttpService $host_http_service;

    public function __construct(HttpService $host_http_service)
    {
        $this->host_http_service = $host_http_service;

        add_action('admin_menu', [$this, 'add_roles_page'], 11);
        add_action('admin_enqueue_scripts', [$this, 'add_admin_settings_styles']);
        add_action('admin_post_waas_add_new_role', [$this, 'add_new_role'], 10, 3);
        add_action('admin_post_save_roles', [$this, 'save_roles'], 10, 3);
    }

    public function add_admin_settings_styles($hook)
    {
        if (strpos((string) $hook, 'waas-plugins-roles') === false) {
            return;
        }

        wp_enqueue_style('waas-admin-styles', WAAS_PLUGIN_DIR_URI . 'assets/style.css', [], WAAS_CLIENT_VERSION);
    }

    public function add_roles_page()
    {
        add_submenu_page(
            AdminTenantSettings::PAGE,
            __('Plan roles', WAAS_CLIENT_TEXTDOMAIN),
            __('Plan roles', WAAS_CLIENT_TEXTDOMAIN),
            'manage_options',
            'waas-plugins-roles',
            [$this, 'render_roles_page'],
            10
        );
    }

    public function render_roles_page()
    {
        $wp_plugins = get_plugins();
        if (isset($wp_plugins[PluginBootstrap::PLUGIN_NAME])) {
            unset($wp_plugins[PluginBootstrap::PLUGIN_NAME]);
        }

        $this->ensure_roles_file_exists();

        $roles = (array) json_decode(file_get_contents(self::ROLES_FILE_PATH), true);

        $user_info = wp_get_current_user();
        $validation_errors = get_transient('add_role_validation_error' . $user_info->ID);
        if ($validation_errors !== false) {
            delete_transient('add_role_validation_error' . $user_info->ID);
        }
        $saved = isset($_GET['waas-saved']);
?>
        <div class="wrap waas-client-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('Plan roles', WAAS_CLIENT_TEXTDOMAIN); ?></h1>
            <hr class="wp-header-end">

            <?php if ($validation_errors !== false) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(implode(' ', (array) $validation_errors)); ?></p></div>
            <?php elseif ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Roles saved.', WAAS_CLIENT_TEXTDOMAIN); ?></p></div>
            <?php endif; ?>

            <p class="waas-lead">
                <?php esc_html_e('A role is a set of plugins. Each plan product on the storefront picks a role, and websites on that plan get exactly those plugins turned on. Plugins left unticked are turned off on those websites.', WAAS_CLIENT_TEXTDOMAIN); ?>
            </p>

            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="waas-add-role">
                <?php wp_nonce_field('waas_add_new_role', 'add_new_role_nonce'); ?>
                <input type="hidden" name="action" value="waas_add_new_role">
                <label for="waas-new-role" class="screen-reader-text"><?php esc_html_e('New role name', WAAS_CLIENT_TEXTDOMAIN); ?></label>
                <input id="waas-new-role" name="role" type="text" class="regular-text" placeholder="<?php esc_attr_e('New role name, for example Pro', WAAS_CLIENT_TEXTDOMAIN); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Add role', WAAS_CLIENT_TEXTDOMAIN); ?></button>
            </form>

            <?php if (!$roles) : ?>
                <p><?php esc_html_e('No roles yet. Add one above, then pick its plugins.', WAAS_CLIENT_TEXTDOMAIN); ?></p>
            <?php else : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" id="manage_plugins_roles">
                    <?php wp_nonce_field('save_roles', 'save_roles_nonce'); ?>
                    <input type="hidden" name="action" value="save_roles">

                    <div class="waas-roles-scroll">
                        <table class="widefat striped waas-roles-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Plugin', WAAS_CLIENT_TEXTDOMAIN); ?></th>
                                    <?php foreach ($roles as $role_name => $role_data) : ?>
                                        <th scope="col" class="waas-role-col">
                                            <strong><?php echo esc_html($role_data['title'] ?? $role_name); ?></strong>
                                            <code><?php echo esc_html($role_name); ?></code>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($wp_plugins as $plugin_name => $plugin) : ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html($plugin['Name']); ?></th>
                                        <?php foreach ($roles as $role_name => $role_data) : ?>
                                            <td class="waas-role-col">
                                                <input type="checkbox"
                                                    name="roles[<?php echo esc_attr($role_name); ?>][<?php echo esc_attr($plugin_name); ?>]"
                                                    aria-label="<?php echo esc_attr(sprintf(
                                                        /* translators: 1: plugin name, 2: role name */
                                                        __('%1$s in %2$s', WAAS_CLIENT_TEXTDOMAIN),
                                                        $plugin['Name'],
                                                        $role_data['title'] ?? $role_name
                                                    )); ?>"
                                                    <?php checked(in_array($plugin_name, (array) ($role_data['plugins'] ?? []), true)); ?> />
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Delete role', WAAS_CLIENT_TEXTDOMAIN); ?></th>
                                    <?php foreach ($roles as $role_name => $role_data) : ?>
                                        <td class="waas-role-col">
                                            <label>
                                                <input type="checkbox" name="delete_roles[<?php echo esc_attr($role_name); ?>]" value="1" />
                                                <?php esc_html_e('Delete', WAAS_CLIENT_TEXTDOMAIN); ?>
                                            </label>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p class="description"><?php esc_html_e('Deleting a role leaves existing websites as they are. Plan products that use it need a new role.', WAAS_CLIENT_TEXTDOMAIN); ?></p>
                    <?php submit_button(__('Save roles', WAAS_CLIENT_TEXTDOMAIN)); ?>
                </form>
            <?php endif; ?>
        </div>
<?php
    }

    private function ensure_roles_file_exists(): void
    {
        if (!file_exists(self::ROLES_FILE_PATH)) {
            mkdir(self::ROLES_FILE_DIR_PATH, 0755, true);
        }

        if (!file_exists(self::ROLES_FILE_PATH)) {
            file_put_contents(self::ROLES_FILE_PATH, "{}");
        }
    }

    public function add_new_role()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['add_new_role_nonce'] ?? '', 'waas_add_new_role')) {
            wp_redirect(wp_get_referer());
            return;
        }

        $this->ensure_roles_file_exists();
        $data = json_decode(file_get_contents(self::ROLES_FILE_PATH), true);

        $new_role_name = sanitize_text_field($_POST['role']);

        if (strlen($new_role_name) === 0) {
            // Add Error
            $user_info = wp_get_current_user();
            set_transient('add_role_validation_error' . $user_info->ID, [__('Enter a name for the new role.', WAAS_CLIENT_TEXTDOMAIN)]);
            wp_redirect(wp_get_referer());
            die();
        }

        $role_slug = sanitize_title_with_dashes($new_role_name);
        $data[$role_slug] = [
            'title' => $new_role_name,
            'plugins' => []
        ];

        $encoded_data = json_encode($data);
        file_put_contents(self::ROLES_FILE_PATH, $encoded_data);

        $this->update_host_roles();

        wp_redirect(remove_query_arg('waas-saved', wp_get_referer()));
    }

    private function update_host_roles()
    {
        try {
            $this->host_http_service->get('/user-role-plan/update');
        } catch (\Exception $exception) {
        }
    }

    public function save_roles()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['save_roles_nonce'] ?? '', 'save_roles')) {
            wp_redirect(wp_get_referer());
            return;
        }

        $this->ensure_roles_file_exists();

        $data = (array) json_decode(file_get_contents(self::ROLES_FILE_PATH), true);

        // Only ticked boxes are posted: a role missing here has no plugins ticked.
        $roles_plugins = (array) ($_POST['roles'] ?? []);
        $deleted_roles = array_keys(array_filter((array) ($_POST['delete_roles'] ?? []), fn ($item) => $item === "1"));

        foreach (array_keys($data) as $role_slug) {
            if (in_array($role_slug, $deleted_roles, true)) {
                unset($data[$role_slug]);
                continue;
            }

            $activated_plugins_files = array_keys((array) ($roles_plugins[$role_slug] ?? []));
            $filtered_activated_plugins_files = array_values(array_filter($activated_plugins_files, function ($plugin_file) {
                return validate_file($plugin_file) === 0 && file_exists(WP_PLUGIN_DIR . '/' . $plugin_file);
            }));

            $data[$role_slug] = [
                'title' => $data[$role_slug]['title'] ?? $role_slug,
                'plugins' => $filtered_activated_plugins_files
            ];
        }

        $encoded_data = json_encode((object) $data);
        file_put_contents(self::ROLES_FILE_PATH, $encoded_data);

        $this->update_host_roles();

        wp_redirect(add_query_arg('waas-saved', '1', wp_get_referer()));
    }
}
