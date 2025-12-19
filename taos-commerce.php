<?php
/**
 * Plugin Name: TA-OS Commerce
 * Plugin URI: https://totalattainment.co.uk
 * Description: Modular payments system for TA-OS. Gateway-agnostic with PayPal support. Admin-configurable courses, prices, and entitlements.
 * Version: 1.2.3
 * Author: Total Attainment
 * Author URI: https://totalattainment.co.uk
 * Text Domain: taos-commerce
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TAOS_COMMERCE_VERSION', '1.2.3');
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
        $this->check_upgrade();
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
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('init', [$this, 'register_gateways']);
        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('init', [$this, 'register_shortcodes']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('template_include', [$this, 'maybe_load_checkout_template']);
        add_action('template_redirect', [$this, 'handle_checkout_actions']);
    }

    public function check_upgrade() {
        $installed_version = get_option('taos_commerce_version', '0.0.0');
        
        if (version_compare($installed_version, TAOS_COMMERCE_VERSION, '<')) {
            $this->run_upgrade($installed_version);
            update_option('taos_commerce_version', TAOS_COMMERCE_VERSION);
        }

        $this->ensure_tables_exist();
    }

    private function run_upgrade($from_version) {
        $this->create_tables();
        flush_rewrite_rules();
        
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
            course_id bigint(20) unsigned NOT NULL DEFAULT 0,
            course_key varchar(100) NOT NULL DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            description text,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'GBP',
            payment_type varchar(20) NOT NULL DEFAULT 'paid',
            enabled_gateways text,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY course_id (course_id),
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

    private function ensure_tables_exist() {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'taos_commerce_courses',
            $wpdb->prefix . 'taos_commerce_course_entitlements',
            $wpdb->prefix . 'taos_commerce_orders'
        ];

        $missing = [];
        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found !== $table) {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            taos_commerce_log('Missing commerce tables; attempting to recreate.', ['tables' => $missing]);
            $this->create_tables();
        }
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

    public function render_checkout_shortcode($atts = []) {
        $atts = shortcode_atts([
            'course_id' => '',
            'course' => ''
        ], $atts);

        $identifier = $atts['course_id'] ?: ($atts['course'] ?: ($_GET['course_id'] ?? ($_GET['course'] ?? '')));
        $course_identifier = is_numeric($identifier)
            ? intval($identifier)
            : sanitize_text_field($identifier);

        ob_start();
        echo $this->get_checkout_markup($course_identifier);
        return ob_get_clean();
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
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('taos-commerce/v1', '/capture-order', [
            'methods' => 'POST',
            'callback' => [$this, 'capture_checkout_order'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function register_gateways() {
        $paypal = new TAOS_PayPal_Gateway();
        $this->gateway_registry->register($paypal);

        do_action('taos_commerce_register_gateways', $this->gateway_registry);
    }

    public function register_rewrite_rules() {
        add_rewrite_rule('^checkout/?$', 'index.php?taos_commerce_checkout=1', 'top');
    }

    public function register_query_vars($vars) {
        $vars[] = 'taos_commerce_checkout';
        $vars[] = 'course';
        $vars[] = 'course_id';
        $vars[] = 'taos_commerce_action';
        return $vars;
    }

    public function register_shortcodes() {
        add_shortcode('taos_commerce_checkout', [$this, 'render_checkout_shortcode']);
    }

    public function maybe_load_checkout_template($template) {
        $is_checkout = get_query_var('taos_commerce_checkout');
        if (!$is_checkout) {
            return $template;
        }

        $course_identifier = get_query_var('course_id');

        if (!$course_identifier) {
            $course_identifier = get_query_var('course');
        }

        $course_identifier = is_numeric($course_identifier)
            ? intval($course_identifier)
            : sanitize_text_field($course_identifier);

        $course = $course_identifier ? TAOS_Commerce_Course::resolve_course($course_identifier) : null;

        if (!$course) {
            status_header(404);
            taos_commerce_log('Checkout course not found', ['course_identifier' => $course_identifier]);
        }

        $GLOBALS['taos_commerce_checkout_course'] = $course;
        $GLOBALS['taos_commerce_checkout_course_id'] = $course_identifier;

        $template_path = TAOS_COMMERCE_PATH . 'templates/checkout.php';
        if (file_exists($template_path)) {
            return $template_path;
        }

        return $template;
    }

    public function handle_checkout_actions() {
        $action = get_query_var('taos_commerce_action');

        if (empty($action)) {
            return;
        }

        if (!in_array($action, ['create_order', 'capture_order'], true)) {
            return;
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['message' => 'Invalid request method.'], 405);
        }

        $payload = $this->get_checkout_payload();

        if ($action === 'create_order') {
            $result = $this->process_checkout_order($payload);
        } else {
            $result = $this->process_checkout_capture($payload);
        }

        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = 400;
            if (is_array($error_data) && isset($error_data['status'])) {
                $status = $error_data['status'];
            } elseif (is_int($error_data)) {
                $status = $error_data;
            }

            wp_send_json_error(['message' => $result->get_error_message()], $status);
        }

        wp_send_json($result);
    }

    public function handle_paypal_webhook(\WP_REST_Request $request) {
        $gateway = $this->gateway_registry->get('paypal');
        if (!$gateway) {
            return new \WP_Error('gateway_not_found', 'PayPal gateway not registered', ['status' => 500]);
        }

        return $gateway->handle_webhook($request);
    }

    public function create_checkout_order(\WP_REST_Request $request) {
        $user_id = $this->resolve_checkout_user_id();
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $course_identifier = $request->get_param('course_id');
        if (!$course_identifier) {
            $course_identifier = $request->get_param('course');
        }

        if (!$course_identifier) {
            $course_identifier = $request->get_param('course_key');
        }

        $gateway_id = sanitize_text_field($request->get_param('gateway'));

        return $this->create_order_for_course($course_identifier, $gateway_id, $user_id);
    }

    private function process_checkout_order(array $payload) {
        $user_id = $this->resolve_checkout_user_id();
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $course_identifier = $payload['course_id'] ?? ($payload['course'] ?? ($payload['course_key'] ?? ''));
        $gateway_id = sanitize_text_field($payload['gateway'] ?? '');

        return $this->create_order_for_course($course_identifier, $gateway_id, $user_id);
    }

    private function create_order_for_course($course_identifier, $gateway_id, $user_id) {
        if (empty($course_identifier) || empty($gateway_id)) {
            return new \WP_Error('missing_params', 'Missing required parameters', ['status' => 400]);
        }

        $course_lookup = is_numeric($course_identifier) ? intval($course_identifier) : sanitize_text_field($course_identifier);

        $course = TAOS_Commerce_Course::resolve_course($course_lookup);
        if (!$course || $course->status !== 'active') {
            taos_commerce_log('Checkout attempt for missing/inactive course', ['course_identifier' => $course_lookup]);
            return new \WP_Error('course_not_found', 'Course not found or inactive', ['status' => 404]);
        }

        $taos_course = $course->get_taos_course_data();
        if (!$taos_course || !TAOS_Commerce_Course::is_course_purchasable($taos_course)) {
            taos_commerce_log('Checkout attempt for unavailable TAOS course', ['course_id' => $course->course_id]);
            return new \WP_Error('course_not_purchasable', 'Course cannot be purchased', ['status' => 400]);
        }

        if (!is_numeric($course->price) || $course->price < 0) {
            taos_commerce_log('Checkout attempt with invalid price', ['course_id' => $course->course_id, 'price' => $course->price]);
            return new \WP_Error('invalid_price', 'Invalid course price', ['status' => 400]);
        }

        if (empty($course->currency)) {
            taos_commerce_log('Checkout attempt with missing currency', ['course_id' => $course->course_id]);
            return new \WP_Error('invalid_currency', 'Invalid currency', ['status' => 400]);
        }

        $gateway = $this->gateway_registry->get($gateway_id);
        if (!$gateway || !$gateway->is_enabled()) {
            return new \WP_Error('gateway_unavailable', 'Payment gateway not available', ['status' => 400]);
        }

        $gateway_settings = method_exists($gateway, 'get_settings') ? $gateway->get_settings() : [];

        if (method_exists($gateway, 'validate_settings') && !$gateway->validate_settings($gateway_settings)) {
            taos_commerce_log('Gateway validation failed during checkout', ['gateway' => $gateway_id]);
            return new \WP_Error('gateway_invalid', 'Payment gateway is not configured correctly', ['status' => 400]);
        }

        $enabled_gateways = json_decode($course->enabled_gateways, true) ?: [];
        if (!in_array($gateway_id, $enabled_gateways)) {
            return new \WP_Error('gateway_not_allowed', 'Gateway not enabled for this course', ['status' => 400]);
        }

        $result = $gateway->create_order($course, $user_id);

        if (is_wp_error($result)) {
            taos_commerce_log('Gateway returned error during order creation', ['gateway' => $gateway_id, 'error' => $result->get_error_message()]);
            return $result;
        }

        if (isset($result['error'])) {
            taos_commerce_log('Gateway failed to create order', ['gateway' => $gateway_id, 'message' => $result['error']]);
            return new \WP_Error('order_create_failed', $result['error'], ['status' => 400]);
        }

        return $result;
    }

    public function capture_checkout_order(\WP_REST_Request $request) {
        $paypal_order_id = sanitize_text_field($request->get_param('paypal_order_id'));
        $order_id = intval($request->get_param('order_id'));

        $user_id = $this->resolve_checkout_user_id();
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return $this->capture_order_for_user($paypal_order_id, $order_id, $user_id);
    }

    private function process_checkout_capture(array $payload) {
        $paypal_order_id = sanitize_text_field($payload['paypal_order_id'] ?? '');
        $order_id = intval($payload['order_id'] ?? 0);

        $user_id = $this->resolve_checkout_user_id();
        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return $this->capture_order_for_user($paypal_order_id, $order_id, $user_id);
    }

    private function capture_order_for_user($paypal_order_id, $order_id, $user_id) {
        if (empty($paypal_order_id) || empty($order_id)) {
            return new \WP_Error('missing_params', 'Missing required parameters', ['status' => 400]);
        }

        $order = TAOS_Commerce_Order::get_by_id($order_id);
        if (!$order || $order->user_id !== $user_id) {
            return new \WP_Error('order_not_found', 'Order not found', ['status' => 404]);
        }

        if (!empty($order->transaction_id) && $order->transaction_id !== $paypal_order_id) {
            taos_commerce_log('PayPal order ID mismatch during capture', [
                'expected' => $order->transaction_id,
                'received' => $paypal_order_id,
                'order_id' => $order_id
            ]);
            return new \WP_Error('transaction_mismatch', 'Payment details did not match the order', ['status' => 400]);
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
            taos_commerce_log('PayPal capture failed', ['order_id' => $order_id, 'error' => $result->get_error_message()]);
            return $result;
        }

        TAOS_Commerce_Order::complete_order($order_id, $paypal_order_id, $result);

        taos_commerce_log('PayPal payment captured and order completed', ['order_id' => $order_id]);

        return ['success' => true, 'message' => 'Payment completed successfully'];
    }

    private function resolve_checkout_user_id() {
        $user_id = get_current_user_id();

        if (is_user_logged_in() && $user_id === 0) {
            taos_commerce_log('Logged-in user resolved to 0 during checkout', [
                'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            return new \WP_Error('invalid_user_context', 'Unable to resolve logged-in user', ['status' => 500]);
        }

        return $user_id;
    }

    private function get_checkout_payload() {
        $payload = [];

        if (!empty($_POST)) {
            $payload = wp_unslash($_POST);
        }

        $raw_input = file_get_contents('php://input');
        if (!empty($raw_input)) {
            $decoded = json_decode($raw_input, true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        return $payload;
    }

    private function get_checkout_markup($course_identifier) {
        $course = $course_identifier ? TAOS_Commerce_Course::resolve_course($course_identifier) : null;

        if (!$course) {
            taos_commerce_log('Checkout attempted without valid course', ['course_identifier' => $course_identifier]);
            return '<div class="taos-checkout-error">' . esc_html__('Course not found.', 'taos-commerce') . '</div>';
        }

        $taos_course = $course->get_taos_course_data();

        if (!$taos_course) {
            taos_commerce_log('TAOS course missing for checkout', ['course_id' => $course->course_id]);
            return '<div class="taos-checkout-error">' . esc_html__('Course not found.', 'taos-commerce') . '</div>';
        }

        if (!$course->is_available()) {
            taos_commerce_log('Inactive course requested at checkout', ['course_id' => $course->course_id]);
            return '<div class="taos-checkout-error">' . esc_html__('This course is currently unavailable.', 'taos-commerce') . '</div>';
        }

        $price_display = $course->payment_type === 'free'
            ? esc_html__('Free', 'taos-commerce')
            : esc_html($course->currency . ' ' . number_format($course->price, 2));

        $button = taos_commerce_get_purchase_button($course->course_id);

        if (empty($button)) {
            taos_commerce_log('Checkout button could not be rendered', ['course_id' => $course->course_id]);
            $button = '<div class="taos-checkout-error">' . esc_html__('Checkout is unavailable for this course.', 'taos-commerce') . '</div>';
        }

        $course_title = $course->get_title();
        $course_code = $course->get_course_code();

        ob_start();
        ?>
        <div class="taos-checkout-wrapper">
            <h1 class="taos-checkout-title"><?php esc_html_e('Checkout', 'taos-commerce'); ?></h1>
            <div class="taos-checkout-course">
                <h2><?php echo esc_html($course_title); ?></h2>
                <?php if (!empty($course_code)): ?>
                    <p class="taos-checkout-description"><strong><?php esc_html_e('Course Code:', 'taos-commerce'); ?></strong> <?php echo esc_html($course_code); ?></p>
                <?php endif; ?>
                <p class="taos-checkout-price">
                    <strong><?php esc_html_e('Price:', 'taos-commerce'); ?></strong> <?php echo $price_display; ?>
                </p>
            </div>
            <div class="taos-checkout-actions">
                <?php echo $button; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function get_gateway_registry() {
        return $this->gateway_registry;
    }

    public static function grant_entitlement($user_id, $course_id, $source = 'purchase') {
        $numeric_course_id = is_numeric($course_id) ? intval($course_id) : 0;

        if (empty($user_id)) {
            return false;
        }

        if ($numeric_course_id > 0) {
            $meta_key = 'ta_course_ids';
            $current = get_user_meta($user_id, $meta_key, true);
            if (!is_array($current)) {
                $current = [];
            }

            if (!in_array($numeric_course_id, $current, true)) {
                $current[] = $numeric_course_id;
                update_user_meta($user_id, $meta_key, $current);
            }

            $course = TAOS_Commerce_Course::get_by_course_id($numeric_course_id);
            $course_slug = $course ? $course->get_slug() : '';
            if (!empty($course_slug)) {
                $legacy_entitlements = get_user_meta($user_id, 'ta_courses', true);
                if (!is_array($legacy_entitlements)) {
                    $legacy_entitlements = [];
                }

                if (!in_array($course_slug, $legacy_entitlements, true)) {
                    $legacy_entitlements[] = $course_slug;
                    update_user_meta($user_id, 'ta_courses', $legacy_entitlements);
                }
            }

            do_action('taos_entitlement_granted', $user_id, $numeric_course_id, $source);

            return true;
        }

        $legacy_slug = sanitize_key((string) $course_id);
        if (empty($legacy_slug)) {
            return false;
        }

        $legacy_entitlements = get_user_meta($user_id, 'ta_courses', true);
        if (!is_array($legacy_entitlements)) {
            $legacy_entitlements = [];
        }

        if (!in_array($legacy_slug, $legacy_entitlements, true)) {
            $legacy_entitlements[] = $legacy_slug;
            update_user_meta($user_id, 'ta_courses', $legacy_entitlements);
        }

        do_action('taos_entitlement_granted', $user_id, $legacy_slug, $source);

        return true;
    }
}

function taos_commerce() {
    return TAOS_Commerce::instance();
}

function taos_commerce_activate() {
    TAOS_Commerce::instance()->activate();
}

function taos_commerce_deactivate() {
    TAOS_Commerce::instance()->deactivate();
}

register_activation_hook(__FILE__, 'taos_commerce_activate');
register_deactivation_hook(__FILE__, 'taos_commerce_deactivate');

function taos_commerce_log($message, $context = []) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }

    if (!is_string($message)) {
        $message = wp_json_encode($message);
    }

    if (!empty($context)) {
        $message .= ' ' . wp_json_encode($context);
    }

    error_log('[TAOS Commerce] ' . $message);
}

function taos_grant_entitlement($user_id, $course_id, $source = 'purchase') {
    return TAOS_Commerce::grant_entitlement($user_id, $course_id, $source);
}

function taos_commerce_get_purchase_button($course_identifier, $button_text = 'Buy Now') {
    $course = TAOS_Commerce_Course::resolve_course($course_identifier);
    if (!$course || !$course->is_available()) {
        return '';
    }

    if ($course->payment_type === 'free') {
        return sprintf(
            '<button class="taos-purchase-btn taos-free-btn" data-course-id="%s">%s</button>',
            esc_attr($course->course_id),
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
