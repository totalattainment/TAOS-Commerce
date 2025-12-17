<?php

if (!defined('ABSPATH')) {
    exit;
}

interface TAOS_Gateway_Interface {
    public function get_id(): string;

    public function get_name(): string;

    public function get_description(): string;

    public function get_icon(): string;

    public function is_enabled(): bool;

    public function get_settings_fields(): array;

    public function render_button($course): string;

    public function create_order($course, $user_id): array;

    public function handle_webhook(\WP_REST_Request $request);

    public function validate_settings(array $settings): bool;
}
