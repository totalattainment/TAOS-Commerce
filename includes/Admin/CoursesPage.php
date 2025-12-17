<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_Admin_Courses_Page {
    private $gateway_registry;

    public function __construct($gateway_registry) {
        $this->gateway_registry = $gateway_registry;
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'taos-commerce'));
        }

        $action = $_GET['action'] ?? 'list';
        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;

        switch ($action) {
            case 'add':
            case 'edit':
                $this->render_form($course_id);
                break;
            case 'delete':
                $this->handle_delete($course_id);
                break;
            default:
                $this->render_list();
        }
    }

    private function render_list() {
        $courses = TAOS_Commerce_Course::get_all();
        ?>
        <div class="wrap taos-commerce-wrap">
            <h1 class="wp-heading-inline"><?php _e('Courses', 'taos-commerce'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=taos-commerce-courses&action=add')); ?>" class="page-title-action">
                <?php _e('Add New', 'taos-commerce'); ?>
            </a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Course saved successfully.', 'taos-commerce'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Course deleted successfully.', 'taos-commerce'); ?></p>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Course', 'taos-commerce'); ?></th>
                        <th><?php _e('Key', 'taos-commerce'); ?></th>
                        <th><?php _e('Price', 'taos-commerce'); ?></th>
                        <th><?php _e('Type', 'taos-commerce'); ?></th>
                        <th><?php _e('Gateways', 'taos-commerce'); ?></th>
                        <th><?php _e('Status', 'taos-commerce'); ?></th>
                        <th><?php _e('Actions', 'taos-commerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($courses)): ?>
                        <tr>
                            <td colspan="7"><?php _e('No courses found.', 'taos-commerce'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($courses as $course): 
                            $enabled_gateways = json_decode($course->enabled_gateways, true) ?: [];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($course->name); ?></strong>
                            </td>
                            <td><code><?php echo esc_html($course->course_key); ?></code></td>
                            <td>
                                <?php if ($course->payment_type === 'free'): ?>
                                    <?php _e('Free', 'taos-commerce'); ?>
                                <?php else: ?>
                                    <?php echo esc_html($course->currency . ' ' . number_format($course->price, 2)); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="taos-badge taos-badge-<?php echo esc_attr($course->payment_type); ?>">
                                    <?php echo esc_html(ucfirst($course->payment_type)); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo esc_html(implode(', ', $enabled_gateways) ?: '—'); ?>
                            </td>
                            <td>
                                <span class="taos-badge taos-badge-<?php echo esc_attr($course->status); ?>">
                                    <?php echo esc_html(ucfirst($course->status)); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=taos-commerce-courses&action=edit&course_id=' . $course->id)); ?>" class="button button-small">
                                    <?php _e('Edit', 'taos-commerce'); ?>
                                </a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=taos-commerce-courses&action=delete&course_id=' . $course->id), 'delete_course_' . $course->id)); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this course?', 'taos-commerce'); ?>');">
                                    <?php _e('Delete', 'taos-commerce'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_form($course_id = 0) {
        $save_error = $this->handle_save();

        $posted_data = null;
        if (is_wp_error($save_error) && !empty($_POST)) {
            $posted_data = wp_unslash($_POST);
        }

        $course = $course_id ? TAOS_Commerce_Course::get_by_id($course_id) : null;
        $entitlements = $course ? $course->get_entitlements() : [];
        $enabled_gateways = $course ? (json_decode($course->enabled_gateways, true) ?: []) : [];

        if ($posted_data) {
            $course = (object) array_merge(
                $course ? get_object_vars($course) : [],
                $posted_data
            );
            $entitlements = array_filter(array_map('trim', explode("\n", $posted_data['entitlements'] ?? '')));
            $enabled_gateways = isset($posted_data['enabled_gateways']) ? (array) $posted_data['enabled_gateways'] : $enabled_gateways;
        }
        $all_gateways = $this->gateway_registry->get_all();
        
        $is_edit = $course_id > 0;
        $page_title = $is_edit ? __('Edit Course', 'taos-commerce') : __('Add New Course', 'taos-commerce');
        ?>
        <div class="wrap taos-commerce-wrap">
            <h1><?php echo esc_html($page_title); ?></h1>

            <?php if (is_wp_error($save_error)): ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($save_error->get_error_message()); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('taos_commerce_save_course', 'taos_commerce_nonce'); ?>
                <input type="hidden" name="course_id" value="<?php echo esc_attr($course_id); ?>">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="course_key"><?php _e('Course Key', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="course_key" name="course_key" 
                                   value="<?php echo esc_attr($course->course_key ?? ''); ?>"
                                   class="regular-text" 
                                   <?php echo $is_edit ? 'readonly' : 'required'; ?>>
                            <p class="description"><?php _e('Unique identifier (e.g., level_1, premium_bundle). Cannot be changed after creation.', 'taos-commerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="name"><?php _e('Course Name', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="name" name="name" 
                                   value="<?php echo esc_attr($course->name ?? ''); ?>"
                                   class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="description"><?php _e('Description', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <textarea id="description" name="description" 
                                      rows="3" class="large-text"><?php echo esc_textarea($course->description ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="payment_type"><?php _e('Payment Type', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <select id="payment_type" name="payment_type">
                                <option value="paid" <?php selected($course->payment_type ?? 'paid', 'paid'); ?>><?php _e('Paid', 'taos-commerce'); ?></option>
                                <option value="free" <?php selected($course->payment_type ?? '', 'free'); ?>><?php _e('Free', 'taos-commerce'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="taos-price-row">
                        <th scope="row">
                            <label for="price"><?php _e('Price', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="price" name="price" 
                                   value="<?php echo esc_attr($course->price ?? '0'); ?>"
                                   step="0.01" min="0" class="small-text">
                            <select id="currency" name="currency">
                                <option value="GBP" <?php selected($course->currency ?? 'GBP', 'GBP'); ?>>GBP (£)</option>
                                <option value="USD" <?php selected($course->currency ?? '', 'USD'); ?>>USD ($)</option>
                                <option value="EUR" <?php selected($course->currency ?? '', 'EUR'); ?>>EUR (€)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Enabled Gateways', 'taos-commerce'); ?></th>
                        <td>
                            <?php foreach ($all_gateways as $gateway): ?>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" 
                                       name="enabled_gateways[]" 
                                       value="<?php echo esc_attr($gateway->get_id()); ?>"
                                       <?php checked(in_array($gateway->get_id(), $enabled_gateways)); ?>>
                                <?php echo esc_html($gateway->get_name()); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description"><?php _e('Select which payment gateways can be used for this course.', 'taos-commerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="entitlements"><?php _e('Entitlements', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <textarea id="entitlements" name="entitlements" 
                                      rows="3" class="large-text"><?php echo esc_textarea(implode("\n", $entitlements)); ?></textarea>
                            <p class="description"><?php _e('One entitlement slug per line. These are granted to users upon purchase.', 'taos-commerce'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="status"><?php _e('Status', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <select id="status" name="status">
                                <option value="active" <?php selected($course->status ?? 'active', 'active'); ?>><?php _e('Active', 'taos-commerce'); ?></option>
                                <option value="inactive" <?php selected($course->status ?? '', 'inactive'); ?>><?php _e('Inactive', 'taos-commerce'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="save_course" class="button button-primary">
                        <?php echo $is_edit ? __('Update Course', 'taos-commerce') : __('Add Course', 'taos-commerce'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=taos-commerce-courses')); ?>" class="button">
                        <?php _e('Cancel', 'taos-commerce'); ?>
                    </a>
                </p>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function togglePriceRow() {
                if ($('#payment_type').val() === 'free') {
                    $('.taos-price-row').hide();
                } else {
                    $('.taos-price-row').show();
                }
            }
            togglePriceRow();
            $('#payment_type').on('change', togglePriceRow);
        });
        </script>
        <?php
    }

    private function handle_save() {
        if (!isset($_POST['save_course'])) {
            return null;
        }

        if (!wp_verify_nonce($_POST['taos_commerce_nonce'] ?? '', 'taos_commerce_save_course')) {
            wp_die(__('Security check failed.', 'taos-commerce'));
        }

        $course_id = intval($_POST['course_id'] ?? 0);
        $entitlements_text = $_POST['entitlements'] ?? '';
        $entitlements = array_filter(array_map('trim', explode("\n", $entitlements_text)));

        $data = [
            'course_key' => sanitize_key($_POST['course_key']),
            'name' => sanitize_text_field($_POST['name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'payment_type' => sanitize_text_field($_POST['payment_type']),
            'price' => floatval($_POST['price']),
            'currency' => sanitize_text_field($_POST['currency']),
            'enabled_gateways' => $_POST['enabled_gateways'] ?? [],
            'status' => sanitize_text_field($_POST['status']),
            'entitlements' => $entitlements
        ];

        if (empty($data['course_key']) || empty($data['name'])) {
            return new WP_Error('taos_course_validation', __('Course key and name are required.', 'taos-commerce'));
        }

        if ($data['payment_type'] === 'free') {
            $data['price'] = 0;
        }

        if (!in_array($data['course_key'], $data['entitlements'], true)) {
            $data['entitlements'][] = $data['course_key'];
        }

        if ($course_id) {
            $existing = TAOS_Commerce_Course::get_by_id($course_id);
            if (!$existing) {
                return new WP_Error('taos_course_missing', __('Course not found.', 'taos-commerce'));
            }

            $result = TAOS_Commerce_Course::update($course_id, $data);
        } else {
            if (TAOS_Commerce_Course::get_by_key($data['course_key'])) {
                return new WP_Error('taos_course_exists', __('A course with this key already exists.', 'taos-commerce'));
            }

            $result = TAOS_Commerce_Course::create($data);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        wp_redirect(admin_url('admin.php?page=taos-commerce-courses&saved=1'));
        exit;
    }

    private function handle_delete($course_id) {
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_course_' . $course_id)) {
            wp_die(__('Security check failed.', 'taos-commerce'));
        }

        TAOS_Commerce_Course::delete($course_id);

        wp_redirect(admin_url('admin.php?page=taos-commerce-courses&deleted=1'));
        exit;
    }
}
