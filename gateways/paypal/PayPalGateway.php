<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_PayPal_Gateway implements TAOS_Gateway_Interface {
    private $settings;

    public function __construct() {
        $all_settings = get_option('taos_commerce_gateways', []);
        $this->settings = $all_settings['paypal'] ?? [];
    }

    public function get_id(): string {
        return 'paypal';
    }

    public function get_name(): string {
        return 'PayPal';
    }

    public function get_description(): string {
        return 'Accept payments via PayPal. Supports PayPal balance, cards, and Pay Later.';
    }

    public function get_settings(): array {
        return $this->settings;
    }

    public function get_icon(): string {
        return 'dashicons-money-alt';
    }

    public function is_enabled(): bool {
        return !empty($this->settings['enabled']);
    }

    public function is_sandbox(): bool {
        return !empty($this->settings['sandbox']);
    }

    public function get_client_id(): string {
        return $this->settings['client_id'] ?? '';
    }

    public function get_client_secret(): string {
        return $this->settings['client_secret'] ?? '';
    }

    public function get_settings_fields(): array {
        return [
            [
                'id' => 'sandbox',
                'label' => 'Sandbox Mode',
                'description' => 'Enable sandbox mode for testing.',
                'type' => 'checkbox',
                'default' => true
            ],
            [
                'id' => 'client_id',
                'label' => 'Client ID',
                'description' => 'Your PayPal REST API Client ID.',
                'type' => 'text',
                'default' => ''
            ],
            [
                'id' => 'client_secret',
                'label' => 'Client Secret',
                'description' => 'Your PayPal REST API Client Secret.',
                'type' => 'password',
                'default' => ''
            ]
        ];
    }

    public function validate_settings(array $settings): bool {
        return !empty($settings['client_id']) && !empty($settings['client_secret']);
    }

    public function render_button($course): string {
        if (!$this->is_enabled() || !$this->validate_settings($this->settings)) {
            return '';
        }

        $sdk_url = $this->is_sandbox()
            ? 'https://www.sandbox.paypal.com/sdk/js'
            : 'https://www.paypal.com/sdk/js';

        $sdk_url .= '?client-id=' . urlencode($this->get_client_id());
        $sdk_url .= '&currency=' . urlencode($course->currency);

        $container_id = 'paypal-button-' . $course->course_id;
        $checkout_base = home_url('/checkout/');
        $create_endpoint = esc_url_raw(add_query_arg('taos_commerce_action', 'create_order', $checkout_base));
        $capture_endpoint = esc_url_raw(add_query_arg('taos_commerce_action', 'capture_order', $checkout_base));
        $success_redirect = esc_url_raw(home_url('/dashboard/?payment=success'));

        return sprintf(
            '<div id="%s" class="taos-paypal-button" data-course-id="%s" data-amount="%s" data-currency="%s"></div>
            <script src="%s"></script>
            <script>
            (function() {
                var taosOrderId = null;
                paypal.Buttons({
                    createOrder: function(data, actions) {
                        return fetch("%s", {
                            method: "POST",
                            credentials: "same-origin",
                            headers: {"Content-Type": "application/json"},
                            body: JSON.stringify({
                                course_id: "%s",
                                gateway: "paypal",
                                amount: "%s",
                                currency: "%s"
                            })
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            if (!data || data.error || data.code) {
                                throw new Error(data.message || data.error || "Failed to create PayPal order");
                            }
                            taosOrderId = data.order_id;
                            return data.paypal_order_id;
                        });
                    },
                    onApprove: function(data, actions) {
                        return fetch("%s", {
                            method: "POST",
                            credentials: "same-origin",
                            headers: {"Content-Type": "application/json"},
                            body: JSON.stringify({
                                paypal_order_id: data.orderID,
                                order_id: taosOrderId
                            })
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(response) {
                            if (response && response.success) {
                                window.location.href = "%s";
                                return;
                            }

                            throw new Error(response && response.message ? response.message : "Payment capture failed");
                        })
                        .catch(function(err) {
                            console.error("PayPal capture failed", err);
                            alert("Payment failed. Please contact support or try again.");
                        });
                    },
                    onError: function(err) {
                        console.error("PayPal Error:", err);
                        alert("Payment failed. Please try again.");
                    }
                }).render("#%s");
            })();
            </script>',
            esc_attr($container_id),
            esc_attr($course->course_id),
            esc_attr($course->price),
            esc_attr($course->currency),
            esc_url($sdk_url),
            esc_js($create_endpoint),
            esc_js($course->course_id),
            esc_js($course->price),
            esc_js($course->currency),
            esc_js($capture_endpoint),
            esc_js($success_redirect),
            esc_js($container_id)
        );
    }

    public function create_order($course, $user_id): array {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return ['error' => 'Failed to authenticate with PayPal'];
        }

        $order_id = TAOS_Commerce_Order::create([
            'user_id' => $user_id,
            'course_id' => $course->course_id,
            'gateway' => 'paypal',
            'amount' => $course->price,
            'currency' => $course->currency,
            'status' => TAOS_Commerce_Order::STATUS_PENDING
        ]);

        if (is_wp_error($order_id)) {
            return ['error' => $order_id->get_error_message()];
        }

        if (!$order_id) {
            return ['error' => 'Failed to create order'];
        }

        $api_url = $this->is_sandbox()
            ? 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
            : 'https://api-m.paypal.com/v2/checkout/orders';

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ],
            'body' => json_encode([
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => (string)$order_id,
                        'description' => $course->get_title(),
                        'amount' => [
                            'currency_code' => $course->currency,
                            'value' => number_format($course->price, 2, '.', '')
                        ]
                    ]
                ],
                'application_context' => [
                    'brand_name' => get_bloginfo('name'),
                    'landing_page' => 'NO_PREFERENCE',
                    'user_action' => 'PAY_NOW',
                    'return_url' => home_url('/dashboard/?payment=success'),
                    'cancel_url' => home_url('/dashboard/?payment=cancelled')
                ]
            ])
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['id'])) {
            return ['error' => 'Failed to create PayPal order'];
        }

        TAOS_Commerce_Order::update_status($order_id, TAOS_Commerce_Order::STATUS_PROCESSING, $body['id']);

        return [
            'success' => true,
            'order_id' => $order_id,
            'paypal_order_id' => $body['id']
        ];
    }

    public function capture_order($paypal_order_id) {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return new \WP_Error('auth_failed', 'Failed to authenticate with PayPal');
        }

        $api_url = $this->is_sandbox()
            ? "https://api-m.sandbox.paypal.com/v2/checkout/orders/{$paypal_order_id}/capture"
            : "https://api-m.paypal.com/v2/checkout/orders/{$paypal_order_id}/capture";

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ],
            'body' => '{}'
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 201 && $status_code !== 200) {
            return new \WP_Error('capture_failed', $body['message'] ?? 'Payment capture failed');
        }

        if (($body['status'] ?? '') !== 'COMPLETED') {
            return new \WP_Error('not_completed', 'Payment not completed');
        }

        return $body;
    }

    public function handle_webhook(\WP_REST_Request $request) {
        $body = $request->get_body();
        $event = json_decode($body, true);

        if (!$event || empty($event['event_type'])) {
            taos_commerce_log('Invalid PayPal webhook received');
            return new \WP_REST_Response(['error' => 'Invalid webhook payload'], 400);
        }

        if ($event['event_type'] === 'CHECKOUT.ORDER.APPROVED' || $event['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
            $resource = $event['resource'] ?? [];
            $paypal_order_id = $resource['id'] ?? '';

            if (!$paypal_order_id && isset($resource['supplementary_data']['related_ids']['order_id'])) {
                $paypal_order_id = $resource['supplementary_data']['related_ids']['order_id'];
            }

            if ($paypal_order_id) {
                $order = TAOS_Commerce_Order::get_by_transaction_id($paypal_order_id);
                if ($order && $order->status !== TAOS_Commerce_Order::STATUS_COMPLETED) {
                    TAOS_Commerce_Order::complete_order($order->id, $paypal_order_id, $event);
                    taos_commerce_log('PayPal webhook completed order', ['order_id' => $order->id]);
                }
            }
        }

        return new \WP_REST_Response(['success' => true], 200);
    }

    private function get_access_token() {
        $api_url = $this->is_sandbox()
            ? 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
            : 'https://api-m.paypal.com/v1/oauth2/token';

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->get_client_id() . ':' . $this->get_client_secret())
            ],
            'body' => 'grant_type=client_credentials'
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['access_token'] ?? null;
    }
}
