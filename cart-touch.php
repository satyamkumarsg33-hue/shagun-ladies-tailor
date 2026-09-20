<?php

require_once __DIR__ . '/includes/cart.php';

demo_cart_bootstrap();
$expired = demo_cart_is_expired();
demo_cart_touch();

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'expired' => $expired,
    'expiresIn' => demo_cart_expires_in(),
]);
