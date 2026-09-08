<?php include 'includes/header.php'; ?>

<section class="gallery">
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
                <button class="filter-btn active" type="button" data-filter="all">All Designs</button>
                <button class="filter-btn" type="button" data-filter="blouse">Blouse</button>
                <button class="filter-btn" type="button" data-filter="kurti">Kurti</button>
                <button class="filter-btn" type="button" data-filter="lehenga">Lehenga</button>
                <button class="filter-btn" type="button" data-filter="handwork">Hand Work</button>
                <button class="filter-btn" type="button" data-filter="machinework">Machine Work</button>
            </div>
        </div>
    </div>

    <div class="gallery-grid">
        <div class="gallery-item blouse" data-category="blouse">
            <img src="assets/images/blouse.jpg" alt="Blouse stitching design">
            <span>View Design</span>
        </div>
        <div class="gallery-item kurti" data-category="kurti">
            <img src="assets/images/kurti.jpg" alt="Kurti stitching design">
            <span>View Design</span>
        </div>
        <div class="gallery-item lehenga" data-category="lehenga">
            <img src="assets/images/lehenga.jpg" alt="Lehenga stitching design">
            <span>View Design</span>
        </div>
        <div class="gallery-item blouse" data-category="blouse">
            <img src="assets/images/blouse-work.jpg" alt="Designer blouse work">
            <span>View Design</span>
        </div>
        <div class="gallery-item kurti" data-category="kurti">
            <img src="assets/images/design 1.jpg" alt="Kurti design sample">
            <span>View Design</span>
        </div>
        <div class="gallery-item lehenga" data-category="lehenga">
            <img src="assets/images/design 2.jpg" alt="Lehenga design sample">
            <span>View Design</span>
        </div>
        <div class="gallery-item handwork" data-category="handwork">
            <img src="assets/images/luxe-1.jpg" alt="Hand work blouse back design">
            <span>Hand Work</span>
        </div>
        <div class="gallery-item handwork" data-category="handwork">
            <img src="assets/images/luxe-2.jpg" alt="Hand embroidered blouse design">
            <span>Hand Work</span>
        </div>
        <div class="gallery-item handwork" data-category="handwork">
            <img src="assets/images/luxe-3.jpg" alt="Hand work designer blouse">
            <span>Hand Work</span>
        </div>
        <div class="gallery-item machinework" data-category="machinework">
            <img src="assets/images/luxe-4.jpg" alt="Machine work blouse detailing">
            <span>Machine Work</span>
        </div>
        <div class="gallery-item machinework" data-category="machinework">
            <img src="assets/images/luxe-5.jpg" alt="Machine work neckline design">
            <span>Machine Work</span>
        </div>
        <div class="gallery-item machinework" data-category="machinework">
            <img src="assets/images/luxe-6.jpg" alt="Machine work sleeve design">
            <span>Machine Work</span>
        </div>
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
            <a id="lightbox-link" class="lightbox-link" href="standard-stitching.php">Explore Service <span aria-hidden="true">→</span></a>
        </aside>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
