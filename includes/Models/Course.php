<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_Commerce_Course {
    public $id;
    public $course_key;
    public $name;
    public $description;
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

    public static function get_all($status = null) {
        global $wpdb;
        $table = self::get_table_name();

        if ($status) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY name ASC",
                $status
            ));
        } else {
            $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");
        }

        return array_map([self::class, 'from_row'], $rows);
    }

    public static function create($data) {
        global $wpdb;
        $table = self::get_table_name();

        $result = $wpdb->insert($table, [
            'course_key' => sanitize_key($data['course_key']),
            'name' => sanitize_text_field($data['name']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'price' => floatval($data['price'] ?? 0),
            'currency' => sanitize_text_field($data['currency'] ?? 'GBP'),
            'payment_type' => sanitize_text_field($data['payment_type'] ?? 'paid'),
            'enabled_gateways' => wp_json_encode($data['enabled_gateways'] ?? []),
            'status' => sanitize_text_field($data['status'] ?? 'active')
        ]);

        if (!$result) {
            self::log_db_error('create', $wpdb->last_error);
            return new \WP_Error('taos_course_create_failed', __('Failed to save course.', 'taos-commerce'));
        }

        $course_id = $wpdb->insert_id;

        if (!empty($data['entitlements'])) {
            self::update_entitlements($course_id, $data['entitlements']);
        }

        return $course_id;
    }

    public static function update($id, $data) {
        global $wpdb;
        $table = self::get_table_name();

        $update_data = [];

        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
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
                self::log_db_error('update', $wpdb->last_error);
                return new \WP_Error('taos_course_update_failed', __('Failed to update course.', 'taos-commerce'));
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
        $course->course_key = $row->course_key;
        $course->name = $row->name;
        $course->description = $row->description;
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
}
