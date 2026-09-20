<?php
require_once __DIR__ . '/includes/demo-data.php';
include __DIR__ . '/includes/header.php';
$categories = standard_stitching_categories();
?>

<main class="service-landing">
    <section class="service-landing-hero">
        <div><p class="demo-eyebrow">Shagun Ladies Tailor</p><h1>Standard Stitching</h1><p>Everyday tailoring with dependable timelines, careful fitting, and elegant finishing.</p><span class="service-landing-note">Most standard orders are ready in 5–7 days.</span></div>
        <img src="assets/images/design 2.jpg" alt="Elegant standard stitching design">
    </section>
    <section class="service-category-section">
        <div class="section-heading"><p class="demo-eyebrow">Choose your service</p><h2>What would you like stitched?</h2><p>Start with a garment type. You will be able to choose details for each individual piece.</p></div>
        <div class="service-category-grid">
            <?php foreach ($categories as $category): ?>
                <?php if ($category['available']): ?>
                    <a class="service-category-card" href="<?php echo htmlspecialchars($category['href']); ?>"><img src="<?php echo htmlspecialchars($category['image']); ?>" alt="<?php echo htmlspecialchars($category['name']); ?>"><div><h3><?php echo htmlspecialchars($category['name']); ?></h3><p><?php echo htmlspecialchars($category['description']); ?></p><span>Choose style <b>→</b></span></div></a>
                <?php else: ?>
                    <article class="service-category-card is-coming" aria-label="<?php echo htmlspecialchars($category['name']); ?> coming next"><img src="<?php echo htmlspecialchars($category['image']); ?>" alt="<?php echo htmlspecialchars($category['name']); ?>"><div><p class="coming-label">Demo coming next</p><h3><?php echo htmlspecialchars($category['name']); ?></h3><p><?php echo htmlspecialchars($category['description']); ?></p></div></article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
