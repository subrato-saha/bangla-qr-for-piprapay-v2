<?php
$plugin_meta = [
    'Plugin Name'       => 'Bangla QR',
    'Description'       => 'Accept 100% automated Bangla QR payments across all Bangladeshi MFS and Banking apps.',
    'Version'           => '1.0.0',
    'Author'            => 'Subrato Saha',
    'Author URI'        => 'https://www.facebook.com/subrato.saha007',
    'License'           => 'GPL-2.0+',
    'License URI'       => 'http://www.gnu.org/licenses/gpl-2.0.txt',
    'Requires at least' => '1.0.0',
    'Plugin URI'        => 'https://www.facebook.com/subrato.saha007',
    'Text Domain'       => '',
    'Domain Path'       => '',
    'Requires PHP'      => '7.4'
];

// Load the admin UI rendering function
function bangla_qr_admin_page() {
    $viewFile = __DIR__ . '/views/admin-ui.php';
    if (!file_exists($viewFile)) {
        $viewFile = __DIR__ . '/bangla-qr/views/admin-ui.php';
    }

    if (file_exists($viewFile)) {
        include $viewFile;
    } else {
        echo "<div class='alert alert-warning'>Admin UI not found.</div>";
    }
}

// Load the checkout UI rendering function
function bangla_qr_checkout_page($payment_id) {
    $viewFile = __DIR__ . '/views/checkout-ui.php';
    if (!file_exists($viewFile)) {
        $viewFile = __DIR__ . '/bangla-qr/views/checkout-ui.php';
    }

    if (file_exists($viewFile)) {
        include $viewFile;
    } else {
        echo "<div class='alert alert-warning'>Checkout UI not found.</div>";
    }
}
