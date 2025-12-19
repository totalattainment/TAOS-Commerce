<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_Commerce_Order {
    public $id;
    public $user_id;
    public $course_id;
    public $gateway;
    public $transaction_id;
    public $amount;
    public $currency;
    public $status;
    public $idempotency_key;
    public $gateway_data;
    public $created_at;
    public $updated_at;

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';

    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'taos_commerce_orders';
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

    public static function get_by_transaction_id($transaction_id) {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE transaction_id = %s",
            $transaction_id
        ));

        if (!$row) {
            return null;
        }

        return self::from_row($row);
    }

    public static function get_by_idempotency_key($key) {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE idempotency_key = %s",
            $key
        ));

        if (!$row) {
            return null;
        }

        return self::from_row($row);
    }

    public static function get_all($args = []) {
        global $wpdb;
        $table = self::get_table_name();

        $where = [];
        $values = [];

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }

        if (!empty($args['course_id'])) {
            $where[] = 'course_id = %d';
            $values[] = $args['course_id'];
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if (!empty($args['gateway'])) {
            $where[] = 'gateway = %s';
            $values[] = $args['gateway'];
        }

        $sql = "SELECT * FROM $table";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY created_at DESC';

        if (!empty($args['limit'])) {
            $sql .= ' LIMIT ' . intval($args['limit']);
        }

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        $rows = $wpdb->get_results($sql);
        return array_map([self::class, 'from_row'], $rows);
    }

    public static function create($data) {
        global $wpdb;
        $table = self::get_table_name();

        $idempotency_key = $data['idempotency_key'] ?? self::generate_idempotency_key(
            $data['user_id'],
            $data['course_id'],
            $data['gateway']
        );

        $existing = self::get_by_idempotency_key($idempotency_key);
        if ($existing) {
            return $existing->id;
        }

        $result = $wpdb->insert($table, [
            'user_id' => intval($data['user_id']),
            'course_id' => intval($data['course_id']),
            'gateway' => sanitize_text_field($data['gateway']),
            'transaction_id' => sanitize_text_field($data['transaction_id'] ?? ''),
            'amount' => floatval($data['amount']),
            'currency' => sanitize_text_field($data['currency'] ?? 'GBP'),
            'status' => sanitize_text_field($data['status'] ?? self::STATUS_PENDING),
            'idempotency_key' => $idempotency_key,
            'gateway_data' => wp_json_encode($data['gateway_data'] ?? [])
        ]);

        if (!$result) {
            self::log_db_error('create', $wpdb->last_error);
            return new \WP_Error('taos_order_create_failed', __('Failed to create order.', 'taos-commerce'));
        }

        return $wpdb->insert_id;
    }

    public static function update_status($id, $status, $transaction_id = null, $gateway_data = null) {
        global $wpdb;
        $table = self::get_table_name();

        $update = ['status' => $status];

        if ($transaction_id) {
            $update['transaction_id'] = $transaction_id;
        }

        if ($gateway_data) {
            $update['gateway_data'] = wp_json_encode($gateway_data);
        }

        $result = $wpdb->update($table, $update, ['id' => $id]);

        if ($result === false) {
            self::log_db_error('update_status', $wpdb->last_error);
        }

        return $result;
    }

    public static function complete_order($id, $transaction_id = null, $gateway_data = null) {
        $order = self::get_by_id($id);
        if (!$order) {
            return false;
        }

        if ($order->status === self::STATUS_COMPLETED) {
            return true;
        }

        self::update_status($id, self::STATUS_COMPLETED, $transaction_id, $gateway_data);
        if ($order->user_id === 0) {
            return true;
        }

        $course = TAOS_Commerce_Course::get_by_course_id($order->course_id);
        if ($course) {
            $entitlements = $course->get_entitlements();

            if (empty($entitlements)) {
                $entitlements = [$course->course_id];
            }

            foreach ($entitlements as $entitlement_slug) {
                taos_grant_entitlement($order->user_id, $entitlement_slug, 'purchase');
            }
        } else {
            taos_grant_entitlement($order->user_id, $order->course_id, 'purchase');
        }

        if (function_exists('taos_commerce_log')) {
            taos_commerce_log('Order completed', [
                'order_id' => $id,
                'course_id' => $order->course_id,
                'user_id' => $order->user_id
            ]);
        }

        do_action('taos_commerce_order_completed', $order, $course);

        return true;
    }

    private static function generate_idempotency_key($user_id, $course_id, $gateway) {
        return md5($user_id . '-' . $course_id . '-' . $gateway . '-' . date('Ymd'));
    }

    private static function from_row($row) {
        $order = new self();
        $order->id = (int)$row->id;
        $order->user_id = (int)$row->user_id;
        $order->course_id = (int)$row->course_id;
        $order->gateway = $row->gateway;
        $order->transaction_id = $row->transaction_id;
        $order->amount = (float)$row->amount;
        $order->currency = $row->currency;
        $order->status = $row->status;
        $order->idempotency_key = $row->idempotency_key;
        $order->gateway_data = $row->gateway_data;
        $order->created_at = $row->created_at;
        $order->updated_at = $row->updated_at;
        return $order;
    }

    private static function log_db_error($context, $error_message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[TAOS Commerce][Order %s] %s', $context, $error_message));
        }
    }
}
