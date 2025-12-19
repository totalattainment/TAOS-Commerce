<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_Commerce_Course {
    public $id;
    public $course_id;
    public $course_key;
    public $price;
    public $currency;
    public $payment_type;
    public $enabled_gateways;
    public $status;
    public $created_at;
    public $updated_at;

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'taos_commerce_courses';
    }

    private static function get_entitlements_table() {
        global $wpdb;
        return $wpdb->prefix . 'taos_commerce_course_entitlements';
    }

    public static function get_by_id($id) {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ));

        if (!$row) {
            return null;
        }

        return self::from_row($row);
    }

    public static function get_by_course_id($course_id) {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE course_id = %d",
            $course_id
        ));

        if (!$row) {
            return null;
        }

        return self::from_row($row);
    }

    public static function get_by_key($course_key) {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE course_key = %s",
            $course_key
        ));

        if (!$row) {
            return null;
        }

        return self::from_row($row);
    }

    public static function resolve_course($identifier) {
        if (is_numeric($identifier)) {
            $id = intval($identifier);
            $by_course_id = self::get_by_course_id($id);
            if ($by_course_id) {
                return $by_course_id;
            }

            $by_id = self::get_by_id($id);
            if ($by_id) {
                return $by_id;
            }
        }

        if (is_string($identifier)) {
            return self::get_by_key(sanitize_key($identifier));
        }

        return null;
    }

    public static function get_all($status = null) {
        global $wpdb;
        $table = self::get_table_name();

        if ($status) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY course_id ASC",
                $status
            ));
        } else {
            $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY course_id ASC");
        }

        return array_map([self::class, 'from_row'], $rows);
    }

    public static function create($data) {
        global $wpdb;
        $table = self::get_table_name();

        $course_id = intval($data['course_id'] ?? 0);
        $taos_course = self::get_taos_course($course_id);

        if (!$course_id || !$taos_course) {
            return new \WP_Error('taos_course_missing', __('A valid TAOS course is required.', 'taos-commerce'));
        }

        if (!self::is_course_purchasable($taos_course)) {
            return new \WP_Error('taos_course_unavailable', __('Selected course cannot be sold.', 'taos-commerce'));
        }

        if (self::get_by_course_id($course_id)) {
            return new \WP_Error('taos_course_exists', __('This TAOS course is already linked.', 'taos-commerce'));
        }

        $result = $wpdb->insert($table, [
            'course_id' => $course_id,
            'course_key' => sanitize_key($data['course_key'] ?? $course_id),
            'name' => '',
            'description' => '',
            'price' => floatval($data['price'] ?? 0),
            'currency' => sanitize_text_field($data['currency'] ?? 'GBP'),
            'payment_type' => sanitize_text_field($data['payment_type'] ?? 'paid'),
            'enabled_gateways' => wp_json_encode($data['enabled_gateways'] ?? []),
            'status' => sanitize_text_field($data['status'] ?? 'active')
        ]);

        if (!$result) {
            $db_error = wp_strip_all_tags($wpdb->last_error);
            self::log_db_error('create', $db_error);

            $message = $db_error
                ? sprintf(__('Failed to save course: %s', 'taos-commerce'), $db_error)
                : __('Failed to save course.', 'taos-commerce');

            return new \WP_Error('taos_course_create_failed', $message);
        }

        $product_id = $wpdb->insert_id;

        if (!empty($data['entitlements'])) {
            self::update_entitlements($product_id, $data['entitlements']);
        }

        return $product_id;
    }

    public static function update($id, $data) {
        global $wpdb;
        $table = self::get_table_name();

        $update_data = [];

        if (isset($data['course_id'])) {
            $course_id = intval($data['course_id']);
            $taos_course = self::get_taos_course($course_id);

            if (!$course_id || !$taos_course) {
                return new \WP_Error('taos_course_missing', __('A valid TAOS course is required.', 'taos-commerce'));
            }

            if (!self::is_course_purchasable($taos_course)) {
                return new \WP_Error('taos_course_unavailable', __('Selected course cannot be sold.', 'taos-commerce'));
            }

            $existing_link = self::get_by_course_id($course_id);
            if ($existing_link && intval($existing_link->id) !== intval($id)) {
                return new \WP_Error('taos_course_exists', __('This TAOS course is already linked.', 'taos-commerce'));
            }

            $update_data['course_id'] = $course_id;
            $update_data['course_key'] = sanitize_key($data['course_key'] ?? $course_id);
        }
        if (isset($data['price'])) {
            $update_data['price'] = floatval($data['price']);
        }
        if (isset($data['currency'])) {
            $update_data['currency'] = sanitize_text_field($data['currency']);
        }
        if (isset($data['payment_type'])) {
            $update_data['payment_type'] = sanitize_text_field($data['payment_type']);
        }
        if (isset($data['enabled_gateways'])) {
            $update_data['enabled_gateways'] = wp_json_encode($data['enabled_gateways']);
        }
        if (isset($data['status'])) {
            $update_data['status'] = sanitize_text_field($data['status']);
        }

        if (!empty($update_data)) {
            $result = $wpdb->update($table, $update_data, ['id' => $id]);

            if ($result === false) {
                $db_error = wp_strip_all_tags($wpdb->last_error);
                self::log_db_error('update', $db_error);

                $message = $db_error
                    ? sprintf(__('Failed to update course: %s', 'taos-commerce'), $db_error)
                    : __('Failed to update course.', 'taos-commerce');

                return new \WP_Error('taos_course_update_failed', $message);
            }
        }

        if (isset($data['entitlements'])) {
            self::update_entitlements($id, $data['entitlements']);
        }

        return true;
    }

    public static function delete($id) {
        global $wpdb;
        $table = self::get_table_name();
        $entitlements_table = self::get_entitlements_table();

        $wpdb->delete($entitlements_table, ['course_id' => $id]);
        return $wpdb->delete($table, ['id' => $id]);
    }

    public function get_entitlements() {
        global $wpdb;
        $table = self::get_entitlements_table();

        return $wpdb->get_col($wpdb->prepare(
            "SELECT entitlement_slug FROM $table WHERE course_id = %d",
            $this->id
        ));
    }

    private static function update_entitlements($course_id, $entitlements) {
        global $wpdb;
        $table = self::get_entitlements_table();

        $wpdb->delete($table, ['course_id' => $course_id]);

        $sanitized = array_unique(array_filter(array_map('sanitize_key', (array) $entitlements)));

        foreach ($sanitized as $slug) {
            $wpdb->insert($table, [
                'course_id' => $course_id,
                'entitlement_slug' => sanitize_key($slug)
            ]);
        }
    }

    private static function from_row($row) {
        $course = new self();
        $course->id = (int)$row->id;
        $course->course_id = (int)$row->course_id;
        $course->course_key = $row->course_key;
        $course->price = (float)$row->price;
        $course->currency = $row->currency;
        $course->payment_type = $row->payment_type;
        $course->enabled_gateways = $row->enabled_gateways;
        $course->status = $row->status;
        $course->created_at = $row->created_at;
        $course->updated_at = $row->updated_at;
        return $course;
    }

    private static function log_db_error($context, $error_message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[TAOS Commerce][Course %s] %s', $context, $error_message));
        }
    }

    public function get_taos_course_data() {
        return self::get_taos_course($this->course_id);
    }

    public function get_title() {
        $course = $this->get_taos_course_data();
        return $course['title'] ?? '';
    }

    public function get_slug() {
        $course = $this->get_taos_course_data();
        return $course['slug'] ?? '';
    }

    public function get_course_code() {
        $course = $this->get_taos_course_data();
        return $course['course_code'] ?? '';
    }

    public function is_available() {
        return self::is_course_purchasable($this->get_taos_course_data()) && $this->status === 'active';
    }

    public static function get_taos_courses() {
        $courses = get_posts([
            'post_type' => 'ta_course',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ]);

        $mapped = [];
        foreach ($courses as $course) {
            $prepared = self::prepare_taos_course_data($course);
            if ($prepared && self::is_course_purchasable($prepared)) {
                $mapped[] = $prepared;
            }
        }

        return apply_filters('taos_commerce_course_selector_options', $mapped);
    }

    public static function get_taos_course($course_id) {
        $course = get_post($course_id);
        if (!$course) {
            return null;
        }

        return self::prepare_taos_course_data($course);
    }

    public static function is_course_purchasable($course) {
        if (!$course) {
            return false;
        }

        if (($course['commerce_visibility'] ?? '') !== 'live') {
            return false;
        }

        if (empty($course['purchasable'])) {
            return false;
        }

        return ($course['status'] ?? '') === 'publish';
    }

    private static function prepare_taos_course_data($course) {
        if (!$course) {
            return null;
        }

        $course_id = is_object($course) ? $course->ID : intval($course);
        $post = is_object($course) ? $course : get_post($course_id);

        if (!$post || $post->post_type !== 'ta_course') {
            return null;
        }

        $purchasable_raw = get_post_meta($post->ID, 'purchasable', true);
        $purchasable = filter_var($purchasable_raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $data = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'course_code' => get_post_meta($post->ID, 'course_code', true),
            'purchasable' => $purchasable === null ? false : $purchasable,
            'commerce_visibility' => get_post_meta($post->ID, 'commerce_visibility', true) ?: 'hidden',
            'status' => $post->post_status
        ];

        return apply_filters('taos_commerce_course_data', $data, $post);
    }
}
