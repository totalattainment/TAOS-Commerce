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
                <?php _e('Link TAOS Course', 'taos-commerce'); ?>
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
                        <th><?php _e('Course ID', 'taos-commerce'); ?></th>
                        <th><?php _e('Course Code', 'taos-commerce'); ?></th>
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
                            $taos_course = $course->get_taos_course_data();
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($course->get_title()); ?></strong>
                            </td>
                            <td><code><?php echo esc_html($course->course_id); ?></code></td>
                            <td><?php echo esc_html($course->get_course_code() ?: '—'); ?></td>
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
                                <?php if (!$taos_course || !TAOS_Commerce_Course::is_course_purchasable($taos_course)): ?>
                                    <br><small><?php _e('Not purchasable in TAOS', 'taos-commerce'); ?></small>
                                <?php endif; ?>
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
        $selected_course_id = $course ? $course->course_id : 0;

        if ($posted_data) {
            $selected_course_id = intval($posted_data['taos_course_id'] ?? $selected_course_id);
            $course = (object) array_merge(
                $course ? get_object_vars($course) : [],
                [
                    'payment_type' => $posted_data['payment_type'] ?? ($course->payment_type ?? 'paid'),
                    'price' => $posted_data['price'] ?? ($course->price ?? 0),
                    'currency' => $posted_data['currency'] ?? ($course->currency ?? 'GBP'),
                    'status' => $posted_data['status'] ?? ($course->status ?? 'active')
                ]
            );
            $entitlements = array_filter(array_map('trim', explode("\n", $posted_data['entitlements'] ?? '')));
            $enabled_gateways = isset($posted_data['enabled_gateways']) ? (array) $posted_data['enabled_gateways'] : $enabled_gateways;
        }

        if (!$course) {
            $course = (object) [
                'payment_type' => 'paid',
                'price' => 0,
                'currency' => 'GBP',
                'status' => 'active'
            ];
        }
        $all_gateways = $this->gateway_registry->get_all();
        $course_options = TAOS_Commerce_Course::get_taos_courses();
        $selected_course = $selected_course_id ? TAOS_Commerce_Course::get_taos_course($selected_course_id) : null;

        $is_edit = $course_id > 0;
        $page_title = $is_edit ? __('Edit Linked Course', 'taos-commerce') : __('Link TAOS Course', 'taos-commerce');
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
                <input type="hidden" name="product_id" value="<?php echo esc_attr($course_id); ?>">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="taos_course_id"><?php _e('TAOS Course', 'taos-commerce'); ?></label>
                        </th>
                        <td>
                            <select id="taos_course_id" name="taos_course_id" class="regular-text" <?php echo empty($course_options) ? 'disabled' : ''; ?>>
                                <option value=""><?php _e('Select a course', 'taos-commerce'); ?></option>
                                <?php foreach ($course_options as $option): ?>
                                    <option value="<?php echo esc_attr($option['id']); ?>" <?php selected($selected_course_id, $option['id']); ?>>
                                        <?php echo esc_html($option['title']); ?><?php echo $option['course_code'] ? ' (' . esc_html($option['course_code']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($is_edit && $selected_course_id): ?>
                                <input type="hidden" name="taos_course_id" value="<?php echo esc_attr($selected_course_id); ?>">
                            <?php endif; ?>
                            <p class="description"><?php _e('Only published, purchasable courses with live commerce visibility are listed.', 'taos-commerce'); ?></p>
                            <?php if ($selected_course): ?>
                                <p class="description">
                                    <strong><?php _e('Course Code:', 'taos-commerce'); ?></strong> <?php echo esc_html($selected_course['course_code'] ?: __('N/A', 'taos-commerce')); ?><br>
                                    <strong><?php _e('Slug:', 'taos-commerce'); ?></strong> <?php echo esc_html($selected_course['slug']); ?>
                                </p>
                            <?php endif; ?>
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
                            <p class="description"><?php _e('Optional: one entitlement per line. Use TAOS course IDs when possible.', 'taos-commerce'); ?></p>
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
                        <?php echo $is_edit ? __('Update Listing', 'taos-commerce') : __('Save Listing', 'taos-commerce'); ?>
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

        $product_id = intval($_POST['product_id'] ?? 0);
        $taos_course_id = intval($_POST['taos_course_id'] ?? 0);
        $entitlements_text = $_POST['entitlements'] ?? '';
        $entitlements = array_filter(array_map('trim', explode("\n", $entitlements_text)));

        if (!$taos_course_id) {
            return new WP_Error('taos_course_validation', __('Please select a TAOS course to sell.', 'taos-commerce'));
        }

        $data = [
            'course_id' => $taos_course_id,
            'course_key' => $taos_course_id,
            'payment_type' => sanitize_text_field($_POST['payment_type']),
            'price' => floatval($_POST['price']),
            'currency' => sanitize_text_field($_POST['currency']),
            'enabled_gateways' => $_POST['enabled_gateways'] ?? [],
            'status' => sanitize_text_field($_POST['status']),
            'entitlements' => $entitlements
        ];

        if ($data['payment_type'] === 'free') {
            $data['price'] = 0;
        }

        if ($product_id) {
            $existing = TAOS_Commerce_Course::get_by_id($product_id);
            if (!$existing) {
                return new WP_Error('taos_course_missing', __('Course not found.', 'taos-commerce'));
            }

            $result = TAOS_Commerce_Course::update($product_id, $data);
        } else {
            if (TAOS_Commerce_Course::get_by_course_id($taos_course_id)) {
                return new WP_Error('taos_course_exists', __('This TAOS course is already linked in Commerce.', 'taos-commerce'));
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
