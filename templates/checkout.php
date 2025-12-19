<?php

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$course_id = $GLOBALS['taos_commerce_checkout_course_id'] ?? '';
?>

<main class="taos-commerce-checkout-page">
    <?php echo taos_commerce()->render_checkout_shortcode(['course_id' => $course_id]); ?>
</main>

<?php get_footer();
