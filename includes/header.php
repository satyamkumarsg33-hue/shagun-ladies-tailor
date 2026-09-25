<?php
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
$config = require __DIR__ . '/config.php';
demo_cart_bootstrap();
demo_cart_touch();

if (!defined('BASE_URL')) {
    define('BASE_URL', app_base_path($config['app']['url']));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=Poppins:wght@300;400;500&display=swap" rel="stylesheet">
    <!-- SEO -->
    <title>Shagun Ladies Tailor | Best Ladies Tailor Near You</title>
    <meta name="description" content="Shagun Ladies Tailor offers blouse, kurti, lehenga stitching with perfect fitting. Book your tailoring service today.">
    <meta name="keywords" content="ladies tailor near me, blouse stitching, kurti stitching, lehenga stitching">

    <!-- CSS -->
   <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css?v=20260925-4">
</head>
<body>

<?php include 'navbar.php'; ?>
