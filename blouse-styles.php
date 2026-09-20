<?php
require_once __DIR__ . '/includes/demo-data.php';
require_once __DIR__ . '/includes/auth.php';

$isLuxe = (isset($_GET['luxe']) && $_GET['luxe'] == '1');
if ($isLuxe) {
    require_user_login();
}
$luxePerson = isset($_GET['person']) ? (string) $_GET['person'] : '';
$luxeGarment = isset($_GET['garment']) ? (string) $_GET['garment'] : 'Blouse';
$luxeGarmentIdx = isset($_GET['garment_idx']) ? (string) $_GET['garment_idx'] : '';

$backUrl = $isLuxe ? 'luxe-workspace.php' : 'standard-stitching.php';
$backText = $isLuxe ? '← Back to Luxe Order Workspace' : '← Standard Stitching';
$eyebrow = $isLuxe ? 'Luxe Customization' : 'Step 1 of 3';
$introDesc = $isLuxe ? 'Select a style to customize this blouse for your Luxe Order Workspace.' : 'Every blouse is treated as its own garment, so you can tailor a different style for every person.';

$extraQuery = '';
if ($isLuxe) {
    $extraQuery = '&luxe=1'
        . ($luxePerson !== '' ? '&person=' . urlencode($luxePerson) : '')
        . ($luxeGarment !== '' ? '&garment=' . urlencode($luxeGarment) : '')
        . ($luxeGarmentIdx !== '' ? '&garment_idx=' . urlencode($luxeGarmentIdx) : '');
}

include __DIR__ . '/includes/header.php';
$styles = blouse_styles();
?>
<main class="demo-page">
    <section class="demo-page-intro"><a href="<?php echo htmlspecialchars($backUrl); ?>" class="demo-back"><?php echo htmlspecialchars($backText); ?></a><p class="demo-eyebrow"><?php echo htmlspecialchars($eyebrow); ?></p><h1>Choose your blouse style</h1><p><?php echo htmlspecialchars($introDesc); ?></p></section>
    <section class="style-grid" aria-label="Blouse styles">
        <?php foreach ($styles as $style): ?>
                 <a class="style-card" href="customize-blouse.php?style=<?php echo urlencode($style['slug']) . $extraQuery; ?>">
   <img src="<?php echo htmlspecialchars($style['image']); ?>" alt="<?php echo htmlspecialchars($style['name']); ?>">
    <div class="style-card-content">
        <h2><?php echo htmlspecialchars($style['name']); ?></h2>
        <p><?php echo htmlspecialchars($style['description']); ?></p>
        <div class="style-card-footer">
            <span>Starting from <strong>₹<?php echo number_format($style['price']); ?></strong></span>
            <span>Select style <b>→</b></span>
        </div>
    </div>
</a>
        <?php endforeach; ?>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
