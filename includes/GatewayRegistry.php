<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_Gateway_Registry {
    private $gateways = [];

    public function register(TAOS_Gateway_Interface $gateway) {
        $this->gateways[$gateway->get_id()] = $gateway;
    }

    public function get(string $id): ?TAOS_Gateway_Interface {
        return $this->gateways[$id] ?? null;
    }

    public function get_all(): array {
        return $this->gateways;
    }

    public function get_enabled(): array {
        return array_filter($this->gateways, function($gateway) {
            return $gateway->is_enabled();
        });
    }

    public function get_available_for_course($course): array {
        $enabled_gateways = json_decode($course->enabled_gateways, true) ?: [];
        
        return array_filter($this->gateways, function($gateway) use ($enabled_gateways) {
            return $gateway->is_enabled() && in_array($gateway->get_id(), $enabled_gateways);
        });
    }
}
