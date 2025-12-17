<?php
/**
 * Plugin Name: TA-OS Commerce
 * Plugin URI: https://totalattainment.co.uk
 * Description: Modular payments system for TA-OS. Gateway-agnostic with PayPal support. Admin-configurable courses, prices, and entitlements.
 * Version: 1.0.1
 * Author: Total Attainment
 * Author URI: https://totalattainment.co.uk
 * Text Domain: taos-commerce
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TAOS_COMMERCE_VERSION', '1.0.1');
define('TAOS_COMMERCE_PATH', plugin_dir_path(__FILE__));
define('TAOS_COMMERCE_URL', plugin_dir_url(__FILE__));

class TAOS_Commerce {
    private static $instance = null;
    private $gateway_registry = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once TAOS_COMMERCE_PATH . 'includes/Interfaces/GatewayInterface.php';
        require_once TAOS_COMMERCE_PATH . 'includes/GatewayRegistry.php';
        require_once TAOS_COMMERCE_PATH . 'includes/Models/Course.php';
        require_once TAOS_COMMERCE_PATH . 'includes/Models/Order.php';
        require_once TAOS_COMMERCE_PATH . 'includes/Admin/PaymentsPage.php';
        require_once TAOS_COMMERCE_PATH . 'includes/Admin/CoursesPage.php';
        require_once TAOS_COMMERCE_PATH . 'includes/Admin/OrdersPage.php';

        $this->gateway_registry = new TAOS_Gateway_Registry();

        require_once TAOS_COMMERCE_PATH . 'gateways/paypal/PayPalGateway.php';
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'check_upgrade'], 5);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('init', [$this, 'register_gateways']);
    }

    public function check_upgrade() {
        $installed_version = get_option('taos_commerce_version', '0.0.0');
        
        if (version_compare($installed_version, TAOS_COMMERCE_VERSION, '<')) {
            $this->run_upgrade($installed_version);
            update_option('taos_commerce_version', TAOS_COMMERCE_VERSION);
        }
    }

    private function run_upgrade($from_version) {
        $this->create_tables();
        
        do_action('taos_commerce_upgraded', $from_version, TAOS_COMMERCE_VERSION);
    }

    public function activate() {
        $this->create_tables();
        $this->set_default_options();
        update_option('taos_commerce_version', TAOS_COMMERCE_VERSION);

        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $courses_table = $wpdb->prefix . 'taos_commerce_courses';
        $entitlements_table = $wpdb->prefix . 'taos_commerce_course_entitlements';
        $orders_table = $wpdb->prefix . 'taos_commerce_orders';

        $sql = "CREATE TABLE $courses_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            course_key varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'GBP',
            payment_type varchar(20) NOT NULL DEFAULT 'paid',
            enabled_gateways text,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY course_key (course_key)
        ) $charset_collate;

        CREATE TABLE $entitlements_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            course_id bigint(20) unsigned NOT NULL,
            entitlement_slug varchar(100) NOT NULL,
            PRIMARY KEY (id),
            KEY course_id (course_id)
        ) $charset_collate;

        CREATE TABLE $orders_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            course_id bigint(20) unsigned NOT NULL,
            gateway varchar(50) NOT NULL,
            transaction_id varchar(255),
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'GBP',
            status varchar(20) NOT NULL DEFAULT 'pending',
            idempotency_key varchar(255),
            gateway_data text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY course_id (course_id),
            UNIQUE KEY idempotency_key (idempotency_key)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function set_default_options() {
        if (get_option('taos_commerce_settings') === false) {
            update_option('taos_commerce_settings', [
                'currency' => 'GBP',
                'sandbox_mode' => true
            ]);
        }

        if (get_option('taos_commerce_gateways') === false) {
            update_option('taos_commerce_gateways', [
                'paypal' => [
                    'enabled' => false,
                    'sandbox' => true,
                    'client_id' => '',
                    'client_secret' => ''
                ]
            ]);
        }
    }

    public function register_admin_menu() {
        add_menu_page(
            __('Commerce', 'taos-commerce'),
            __('Commerce', 'taos-commerce'),
            'manage_options',
            'taos-commerce',
            [$this, 'render_payments_page'],
            'dashicons-cart',
            57
        );

        add_submenu_page(
            'taos-commerce',
            __('Payments', 'taos-commerce'),
            __('Payments', 'taos-commerce'),
            'manage_options',
            'taos-commerce',
            [$this, 'render_payments_page']
        );

        add_submenu_page(
            'taos-commerce',
            __('Courses', 'taos-commerce'),
            __('Courses', 'taos-commerce'),
            'manage_options',
            'taos-commerce-courses',
            [$this, 'render_courses_page']
        );

        add_submenu_page(
            'taos-commerce',
            __('Orders', 'taos-commerce'),
            __('Orders', 'taos-commerce'),
            'manage_options',
            'taos-commerce-orders',
            [$this, 'render_orders_page']
        );
    }

    public function render_payments_page() {
        $page = new TAOS_Admin_Payments_Page($this->gateway_registry);
        $page->render();
    }

    public function render_courses_page() {
        $page = new TAOS_Admin_Courses_Page($this->gateway_registry);
        $page->render();
    }

    public function render_orders_page() {
        $page = new TAOS_Admin_Orders_Page();
        $page->render();
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'taos-commerce') === false) {
            return;
        }

        wp_enqueue_style(
            'taos-commerce-admin',
            TAOS_COMMERCE_URL . 'assets/admin.css',
            [],
            TAOS_COMMERCE_VERSION
        );
    }

    public function register_rest_routes() {
        register_rest_route('taos-commerce/v1', '/paypal/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_paypal_webhook'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('taos-commerce/v1', '/create-order', [
            'methods' => 'POST',
            'callback' => [$this, 'create_checkout_order'],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ]);

        register_rest_route('taos-commerce/v1', '/capture-order', [
            'methods' => 'POST',
            'callback' => [$this, 'capture_checkout_order'],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ]);
    }

    public function register_gateways() {
        $paypal = new TAOS_PayPal_Gateway();
        $this->gateway_registry->register($paypal);

        do_action('taos_commerce_register_gateways', $this->gateway_registry);
    }

    public function handle_paypal_webhook(\WP_REST_Request $request) {
        $gateway = $this->gateway_registry->get('paypal');
        if (!$gateway) {
            return new \WP_Error('gateway_not_found', 'PayPal gateway not registered', ['status' => 500]);
        }

        return $gateway->handle_webhook($request);
    }

    public function create_checkout_order(\WP_REST_Request $request) {
        $course_key = sanitize_text_field($request->get_param('course_key'));
        $gateway_id = sanitize_text_field($request->get_param('gateway'));

        if (empty($course_key) || empty($gateway_id)) {
            return new \WP_Error('missing_params', 'Missing required parameters', ['status' => 400]);
        }

        $course = TAOS_Commerce_Course::get_by_key($course_key);
        if (!$course || $course->status !== 'active') {
            return new \WP_Error('course_not_found', 'Course not found or inactive', ['status' => 404]);
        }

        $gateway = $this->gateway_registry->get($gateway_id);
        if (!$gateway || !$gateway->is_enabled()) {
            return new \WP_Error('gateway_unavailable', 'Payment gateway not available', ['status' => 400]);
        }

        $enabled_gateways = json_decode($course->enabled_gateways, true) ?: [];
        if (!in_array($gateway_id, $enabled_gateways)) {
            return new \WP_Error('gateway_not_allowed', 'Gateway not enabled for this course', ['status' => 400]);
        }

        return $gateway->create_order($course, get_current_user_id());
    }

    public function capture_checkout_order(\WP_REST_Request $request) {
        $paypal_order_id = sanitize_text_field($request->get_param('paypal_order_id'));
        $order_id = intval($request->get_param('order_id'));

        if (empty($paypal_order_id) || empty($order_id)) {
            return new \WP_Error('missing_params', 'Missing required parameters', ['status' => 400]);
        }

        $order = TAOS_Commerce_Order::get_by_id($order_id);
        if (!$order || $order->user_id !== get_current_user_id()) {
            return new \WP_Error('order_not_found', 'Order not found', ['status' => 404]);
        }

        if ($order->status === TAOS_Commerce_Order::STATUS_COMPLETED) {
            return ['success' => true, 'message' => 'Order already completed'];
        }

        $gateway = $this->gateway_registry->get('paypal');
        if (!$gateway) {
            return new \WP_Error('gateway_not_found', 'Gateway not found', ['status' => 500]);
        }

        $result = $gateway->capture_order($paypal_order_id);
        if (is_wp_error($result)) {
            return $result;
        }

        TAOS_Commerce_Order::complete_order($order_id, $paypal_order_id, $result);

        return ['success' => true, 'message' => 'Payment completed successfully'];
    }

    public function get_gateway_registry() {
        return $this->gateway_registry;
    }

    public static function grant_entitlement($user_id, $entitlement_slug, $source = 'purchase') {
        $current = get_user_meta($user_id, 'ta_courses', true);
        if (!is_array($current)) {
            $current = [];
        }

        if (!in_array($entitlement_slug, $current)) {
            $current[] = $entitlement_slug;
            update_user_meta($user_id, 'ta_courses', $current);
        }

        do_action('taos_entitlement_granted', $user_id, $entitlement_slug, $source);

        return true;
    }
}

function taos_commerce() {
    return TAOS_Commerce::instance();
}

function taos_grant_entitlement($user_id, $entitlement_slug, $source = 'purchase') {
    return TAOS_Commerce::grant_entitlement($user_id, $entitlement_slug, $source);
}

function taos_commerce_get_purchase_button($course_key, $button_text = 'Buy Now') {
    $course = TAOS_Commerce_Course::get_by_key($course_key);
    if (!$course || $course->status !== 'active') {
        return '';
    }

    if ($course->payment_type === 'free') {
        return sprintf(
            '<button class="taos-purchase-btn taos-free-btn" data-course="%s">%s</button>',
            esc_attr($course_key),
            esc_html__('Enroll Free', 'taos-commerce')
        );
    }

    $registry = taos_commerce()->get_gateway_registry();
    $enabled_gateways = json_decode($course->enabled_gateways, true) ?: [];

    $buttons = '';
    foreach ($enabled_gateways as $gateway_id) {
        $gateway = $registry->get($gateway_id);
        if ($gateway && $gateway->is_enabled()) {
            $buttons .= $gateway->render_button($course);
        }
    }

    return $buttons;
}

add_action('plugins_loaded', function() {
    taos_commerce();
});

add_action('admin_notices', function() {
    if (!class_exists('TA_Student_Dashboard') && !is_plugin_active('ta-student-dashboard/ta-student-dashboard.php')) {
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '<strong>TA-OS Commerce:</strong> For full functionality, please also install and activate the TA-OS Student Dashboard plugin.';
        echo '</p></div>';
    }
});
