<?php

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$course_key = $GLOBALS['taos_commerce_checkout_course_key'] ?? '';
?>

<main class="taos-commerce-checkout-page">
    <?php echo taos_commerce()->render_checkout_shortcode(['course' => $course_key]); ?>
</main>

<?php get_footer();
