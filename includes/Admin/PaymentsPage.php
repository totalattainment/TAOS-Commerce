<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_Admin_Payments_Page {
    private $gateway_registry;

    public function __construct($gateway_registry) {
        $this->gateway_registry = $gateway_registry;
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'taos-commerce'));
        }

        $this->handle_save();

        $gateways = $this->gateway_registry->get_all();
        $settings = get_option('taos_commerce_gateways', []);
        ?>
        <div class="wrap taos-commerce-wrap">
            <h1><?php _e('Payment Gateways', 'taos-commerce'); ?></h1>
            <p class="description"><?php _e('Enable and configure payment gateways for your courses.', 'taos-commerce'); ?></p>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Settings saved successfully.', 'taos-commerce'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('taos_commerce_save_gateways', 'taos_commerce_nonce'); ?>

                <table class="wp-list-table widefat fixed striped taos-gateways-table">
                    <thead>
                        <tr>
                            <th class="column-enabled"><?php _e('Enabled', 'taos-commerce'); ?></th>
                            <th class="column-gateway"><?php _e('Gateway', 'taos-commerce'); ?></th>
                            <th class="column-description"><?php _e('Description', 'taos-commerce'); ?></th>
                            <th class="column-actions"><?php _e('Actions', 'taos-commerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gateways as $gateway): 
                            $id = $gateway->get_id();
                            $gateway_settings = $settings[$id] ?? [];
                            $is_enabled = !empty($gateway_settings['enabled']);
                        ?>
                        <tr>
                            <td class="column-enabled">
                                <label class="taos-toggle">
                                    <input type="checkbox" 
                                           name="gateways[<?php echo esc_attr($id); ?>][enabled]" 
                                           value="1" 
                                           <?php checked($is_enabled); ?>>
                                    <span class="taos-toggle-slider"></span>
                                </label>
                            </td>
                            <td class="column-gateway">
                                <strong><?php echo esc_html($gateway->get_name()); ?></strong>
                            </td>
                            <td class="column-description">
                                <?php echo esc_html($gateway->get_description()); ?>
                            </td>
                            <td class="column-actions">
                                <a href="#gateway-<?php echo esc_attr($id); ?>" class="button taos-toggle-settings">
                                    <?php _e('Configure', 'taos-commerce'); ?>
                                </a>
                            </td>
                        </tr>
                        <tr class="taos-gateway-settings" id="gateway-<?php echo esc_attr($id); ?>">
                            <td colspan="4">
                                <div class="taos-settings-panel">
                                    <h3><?php echo esc_html($gateway->get_name()); ?> <?php _e('Settings', 'taos-commerce'); ?></h3>
                                    <?php $this->render_gateway_settings($gateway, $gateway_settings); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" name="save_gateways" class="button button-primary">
                        <?php _e('Save Changes', 'taos-commerce'); ?>
                    </button>
                </p>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.taos-toggle-settings').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                $(target).toggle();
            });

            $('.taos-gateway-settings').hide();
        });
        </script>
        <?php
    }

    private function render_gateway_settings($gateway, $current_settings) {
        $fields = $gateway->get_settings_fields();
        
        foreach ($fields as $field) {
            $id = $gateway->get_id();
            $name = "gateways[{$id}][{$field['id']}]";
            $value = $current_settings[$field['id']] ?? ($field['default'] ?? '');
            ?>
            <div class="taos-field">
                <label for="<?php echo esc_attr($name); ?>">
                    <?php echo esc_html($field['label']); ?>
                </label>
                <?php if (!empty($field['description'])): ?>
                    <p class="description"><?php echo esc_html($field['description']); ?></p>
                <?php endif; ?>

                <?php switch ($field['type']):
                    case 'text':
                    case 'password': ?>
                        <input type="<?php echo esc_attr($field['type']); ?>" 
                               id="<?php echo esc_attr($name); ?>"
                               name="<?php echo esc_attr($name); ?>" 
                               value="<?php echo esc_attr($value); ?>"
                               class="regular-text">
                        <?php break;

                    case 'checkbox': ?>
                        <label class="taos-toggle">
                            <input type="checkbox" 
                                   id="<?php echo esc_attr($name); ?>"
                                   name="<?php echo esc_attr($name); ?>" 
                                   value="1" 
                                   <?php checked($value); ?>>
                            <span class="taos-toggle-slider"></span>
                        </label>
                        <?php break;

                    case 'select': ?>
                        <select id="<?php echo esc_attr($name); ?>" 
                                name="<?php echo esc_attr($name); ?>">
                            <?php foreach ($field['options'] as $opt_value => $opt_label): ?>
                                <option value="<?php echo esc_attr($opt_value); ?>" 
                                        <?php selected($value, $opt_value); ?>>
                                    <?php echo esc_html($opt_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php break;
                endswitch; ?>
            </div>
            <?php
        }
    }

    private function handle_save() {
        if (!isset($_POST['save_gateways'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['taos_commerce_nonce'] ?? '', 'taos_commerce_save_gateways')) {
            wp_die(__('Security check failed.', 'taos-commerce'));
        }

        $gateways_data = $_POST['gateways'] ?? [];
        $sanitized = [];

        foreach ($gateways_data as $gateway_id => $gateway_settings) {
            $gateway_id = sanitize_key($gateway_id);
            $sanitized[$gateway_id] = [];

            foreach ($gateway_settings as $key => $value) {
                $key = sanitize_key($key);
                if ($key === 'enabled' || $key === 'sandbox') {
                    $sanitized[$gateway_id][$key] = !empty($value);
                } else {
                    $sanitized[$gateway_id][$key] = sanitize_text_field($value);
                }
            }

            if (!isset($sanitized[$gateway_id]['enabled'])) {
                $sanitized[$gateway_id]['enabled'] = false;
            }
        }

        update_option('taos_commerce_gateways', $sanitized);

        wp_redirect(add_query_arg('saved', '1', wp_get_referer()));
        exit;
    }
}
