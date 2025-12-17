<?php

if (!defined('ABSPATH')) {
    exit;
}

class TAOS_Admin_Orders_Page {
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'taos-commerce'));
        }

        $orders = TAOS_Commerce_Order::get_all(['limit' => 100]);
        ?>
        <div class="wrap taos-commerce-wrap">
            <h1><?php _e('Orders', 'taos-commerce'); ?></h1>
            <p class="description"><?php _e('View payment history and order status.', 'taos-commerce'); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Order', 'taos-commerce'); ?></th>
                        <th><?php _e('User', 'taos-commerce'); ?></th>
                        <th><?php _e('Course', 'taos-commerce'); ?></th>
                        <th><?php _e('Amount', 'taos-commerce'); ?></th>
                        <th><?php _e('Gateway', 'taos-commerce'); ?></th>
                        <th><?php _e('Status', 'taos-commerce'); ?></th>
                        <th><?php _e('Date', 'taos-commerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7"><?php _e('No orders found.', 'taos-commerce'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): 
                            $user = get_userdata($order->user_id);
                            $course = TAOS_Commerce_Course::get_by_id($order->course_id);
                        ?>
                        <tr>
                            <td>
                                <strong>#<?php echo esc_html($order->id); ?></strong>
                                <?php if ($order->transaction_id): ?>
                                    <br><small><?php echo esc_html($order->transaction_id); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user): ?>
                                    <?php echo esc_html($user->display_name); ?>
                                    <br><small><?php echo esc_html($user->user_email); ?></small>
                                <?php else: ?>
                                    <?php _e('User not found', 'taos-commerce'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $course ? esc_html($course->name) : __('Course not found', 'taos-commerce'); ?>
                            </td>
                            <td>
                                <?php echo esc_html($order->currency . ' ' . number_format($order->amount, 2)); ?>
                            </td>
                            <td>
                                <?php echo esc_html(ucfirst($order->gateway)); ?>
                            </td>
                            <td>
                                <span class="taos-badge taos-badge-<?php echo esc_attr($order->status); ?>">
                                    <?php echo esc_html(ucfirst($order->status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($order->created_at))); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
