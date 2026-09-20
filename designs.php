<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/demo-data.php';
require_once __DIR__ . '/includes/auth.php';

$isLuxe = (isset($_GET['luxe']) && $_GET['luxe'] == '1');
if ($isLuxe) {
    require_user_login();
}
$personParam = $_GET['person'] ?? null;
$garmentParam = (string) ($_GET['garment'] ?? 'Blouse');
$garmentIdxParam = isset($_GET['garment_idx']) ? (int) $_GET['garment_idx'] : 0;
$initialCategory = strtolower(trim((string) ($_GET['category'] ?? 'all')));

if ($initialCategory === 'machine' || $initialCategory === 'machinework') {
    $initialCategory = 'machinework';
} elseif ($initialCategory === 'hand' || $initialCategory === 'handwork') {
    $initialCategory = 'handwork';
} else {
    $initialCategory = 'all';
}

$backUrl = 'luxe-work.php?luxe=1&person=' . urlencode((string) $personParam) . '&garment=' . urlencode($garmentParam) . '&garment_idx=' . urlencode((string) $garmentIdxParam);

$machineDesigns = luxe_machine_work_designs();
$handDesigns = luxe_hand_work_designs();

include 'includes/header.php';
?>

<section class="gallery <?php echo $isLuxe ? 'luxe-catalogue-mode' : ''; ?>" data-luxe-mode="<?php echo $isLuxe ? '1' : '0'; ?>" data-initial-category="<?php echo htmlspecialchars($initialCategory); ?>">

    <?php if ($isLuxe): ?>
        <!-- Luxe Contextual Header Banner -->
        <div class="luxe-catalogue-banner">
            <div class="luxe-catalogue-nav">
                <a href="<?php echo htmlspecialchars($backUrl); ?>" class="luxe-catalogue-back-link">
                    ← Back to Work Selection
                </a>
                <span class="luxe-catalogue-context-tag">
                    <?php echo htmlspecialchars($garmentParam . ' #' . ($garmentIdxParam + 1)); ?>
                </span>
            </div>
            <p class="luxe-catalogue-banner-desc">
                Browse all embroidery &amp; artisanal designs below. Click <strong>Select This Design</strong> on any card to assign it to your garment.
            </p>
        </div>
    <?php endif; ?>

    <div class="gallery-intro">
        <p class="gallery-eyebrow">Crafted for every occasion</p>
        <h2>Design Gallery</h2>

        <div class="gallery-filter-bar">
            <div class="gallery-filter-label">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 6h16M7 12h10M10 18h4"></path>
                </svg>
                <span>Filter Designs</span>
            </div>

            <div class="gallery-controls" role="group" aria-label="Filter designs by category">
                <button class="filter-btn <?php echo ($initialCategory === 'all') ? 'active' : ''; ?>" type="button" data-filter="all">All Designs</button>
                <button class="filter-btn" type="button" data-filter="blouse">Blouse</button>
                <button class="filter-btn" type="button" data-filter="kurti">Kurti</button>
                <button class="filter-btn" type="button" data-filter="lehenga">Lehenga</button>
                <button class="filter-btn <?php echo ($initialCategory === 'handwork') ? 'active' : ''; ?>" type="button" data-filter="handwork">Hand Work</button>
                <button class="filter-btn <?php echo ($initialCategory === 'machinework') ? 'active' : ''; ?>" type="button" data-filter="machinework">Machine Work</button>
            </div>
        </div>
    </div>

    <div class="gallery-grid <?php echo ($initialCategory !== 'all') ? 'is-filtered' : ''; ?>">
        <!-- Standard stitched garments -->
        <div class="gallery-item blouse" data-category="blouse" style="<?php echo ($isLuxe && $initialCategory !== 'all' && $initialCategory !== 'blouse') ? 'display: none;' : ''; ?>">
            <img src="assets/images/blouse.jpg" alt="Blouse stitching design">
            <span>View Design</span>
        </div>
        <div class="gallery-item kurti" data-category="kurti" style="<?php echo ($isLuxe && $initialCategory !== 'all' && $initialCategory !== 'kurti') ? 'display: none;' : ''; ?>">
            <img src="assets/images/kurti.jpg" alt="Kurti stitching design">
            <span>View Design</span>
        </div>
        <div class="gallery-item lehenga" data-category="lehenga" style="<?php echo ($isLuxe && $initialCategory !== 'all' && $initialCategory !== 'lehenga') ? 'display: none;' : ''; ?>">
            <img src="assets/images/lehenga.jpg" alt="Lehenga stitching design">
            <span>View Design</span>
        </div>
        <div class="gallery-item kurti" data-category="kurti" style="<?php echo ($isLuxe && $initialCategory !== 'all' && $initialCategory !== 'kurti') ? 'display: none;' : ''; ?>">
            <img src="assets/images/design 1.jpg" alt="Kurti design sample">
            <span>View Design</span>
        </div>
        <div class="gallery-item lehenga" data-category="lehenga" style="<?php echo ($isLuxe && $initialCategory !== 'all' && $initialCategory !== 'lehenga') ? 'display: none;' : ''; ?>">
            <img src="assets/images/design 2.jpg" alt="Lehenga design sample">
            <span>View Design</span>
        </div>

        <!-- Hand Work Designs (Full Catalogue) -->
        <?php foreach ($handDesigns as $hDes): ?>
            <?php $hSelectUrl = $backUrl . '&select_hand=' . urlencode($hDes['code']); ?>
            <div
                class="gallery-item handwork"
                data-category="handwork"
                data-design-code="<?php echo htmlspecialchars($hDes['code']); ?>"
                data-design-name="<?php echo htmlspecialchars($hDes['name']); ?>"
                data-price="<?php echo (int) $hDes['price']; ?>"
                data-luxe-select-url="<?php echo htmlspecialchars($hSelectUrl); ?>"
                style="<?php echo ($isLuxe && $initialCategory !== 'all' && $initialCategory !== 'handwork') ? 'display: none;' : ''; ?>"
            >
                <img src="<?php echo htmlspecialchars($hDes['image']); ?>" alt="<?php echo htmlspecialchars($hDes['name']); ?>">
                <span><?php echo htmlspecialchars($hDes['code'] . ' — ' . $hDes['name'] . ' (₹' . number_format($hDes['price']) . ')'); ?></span>
                <?php if ($isLuxe): ?>
                    <div class="luxe-catalogue-item-actions">
                        <a href="<?php echo htmlspecialchars($hSelectUrl); ?>" class="luxe-catalogue-select-btn" data-luxe-select-link>
                            Select This Design →
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <!-- Machine Work Designs (Full Catalogue) -->
        <?php foreach ($machineDesigns as $mDes): ?>
            <?php $mSelectUrl = $backUrl . '&select_machine=' . urlencode($mDes['code']); ?>
            <div
                class="gallery-item machinework"
                data-category="machinework"
                data-design-code="<?php echo htmlspecialchars($mDes['code']); ?>"
                data-design-name="<?php echo htmlspecialchars($mDes['name']); ?>"
                data-price="<?php echo (int) $mDes['price']; ?>"
                data-luxe-select-url="<?php echo htmlspecialchars($mSelectUrl); ?>"
                style="<?php echo ($isLuxe && $initialCategory !== 'all' && $initialCategory !== 'machinework') ? 'display: none;' : ''; ?>"
            >
                <img src="<?php echo htmlspecialchars($mDes['image']); ?>" alt="<?php echo htmlspecialchars($mDes['name']); ?>">
                <span><?php echo htmlspecialchars($mDes['code'] . ' — ' . $mDes['name'] . ' (₹' . number_format($mDes['price']) . ')'); ?></span>
                <?php if ($isLuxe): ?>
                    <div class="luxe-catalogue-item-actions">
                        <a href="<?php echo htmlspecialchars($mSelectUrl); ?>" class="luxe-catalogue-select-btn" data-luxe-select-link>
                            Select This Design →
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="lightbox" id="lightbox" aria-modal="true" role="dialog">
        <button class="close" type="button" aria-label="Close design preview">&times;</button>

        <div class="lightbox-viewer">
            <img id="lightbox-img" alt="Selected design preview">
            <div class="lightbox-zoom-lens" id="lightbox-zoom-lens" aria-hidden="true"></div>
        </div>

        <div class="lightbox-zoom-preview" id="lightbox-zoom-preview" aria-hidden="true">
            <span>Detail preview</span>
        </div>

        <aside class="lightbox-details" aria-live="polite">
            <button type="button" class="lightbox-details-close" id="lightbox-details-close" aria-label="Hide design details and continue viewing image">&times;</button>
            <p class="lightbox-eyebrow">Signature collection</p>
            <h3 id="lightbox-title">Designer Edit</h3>
            <p id="lightbox-description">Explore this custom design and its finishing details.</p>
            <?php if ($isLuxe): ?>
                <a id="lightbox-luxe-btn" class="luxe-lightbox-select-btn" href="<?php echo htmlspecialchars($backUrl); ?>" style="display: none;">
                    Select This Design for Garment →
                </a>
            <?php endif; ?>
            <a id="lightbox-link" class="lightbox-link" href="standard-stitching.php">Explore Service <span aria-hidden="true">→</span></a>
        </aside>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
