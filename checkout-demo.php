<?php
/**
 * Legacy Standard Checkout Route -> Redirects to Canonical checkout.php
 */
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = 'checkout.php' . ($queryString !== '' ? '?' . $queryString : '');
header('Location: ' . $target, true, 302);
exit;
