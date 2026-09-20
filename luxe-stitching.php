<?php include 'includes/header.php'; ?>

<main class="luxe-page">

    <!-- LUXE HERO -->
    <section class="luxe-hero">

        <div class="luxe-hero-content">

            <p class="luxe-eyebrow">SHAGUN LUXE</p>

            <h1>
                Tailoring for<br>
                moments that matter.
            </h1>

            <p class="luxe-hero-description">
                Weddings, families, festivals and special occasions —
                thoughtfully tailored for everyone.
            </p>

            <div class="luxe-highlights">
                <div>
                    <span>◇</span>
                    <strong>Premium</strong>
                    <small>Tailoring</small>
                </div>

                <div>
                    <span>♧</span>
                    <strong>For Every</strong>
                    <small>Occasion</small>
                </div>

                <div>
                    <span>♡</span>
                    <strong>Made</strong>
                    <small>with Care</small>
                </div>
            </div>

        </div>

        <div class="luxe-hero-image">
            <img
                src="assets/images/luxe-1.jpg"
                alt="Shagun Luxe tailoring"
            >
        </div>

    </section>


    <!-- OCCASIONS -->
    <section class="luxe-occasion-section">

        <div class="luxe-section-heading">
            <p class="luxe-eyebrow">TAILORED AROUND YOU</p>

            <h2>Choose your occasion</h2>

            <p>
                Tell us what you're preparing for.
                We'll take care of the tailoring.
            </p>
        </div>


        <div class="luxe-occasion-grid">

            <!-- WEDDINGS -->
            <a href="<?php echo is_user_logged_in() ? 'luxe-wedding.php' : 'login.php?redirect=luxe-wedding.php'; ?>" class="luxe-occasion-card">

                <div class="luxe-card-image">
                    <img
                        src="assets/images/luxe-1.jpg"
                        alt="Wedding tailoring"
                    >
                </div>

                <div class="luxe-card-content">

                    <h3>Weddings</h3>

                    <p>
                        Coordinated outfits for brides,
                        family members and the wedding party.
                    </p>

                    <span>
                        Plan a Wedding →
                    </span>

                </div>

            </a>


            <!-- FAMILY & CELEBRATIONS -->
            <a href="<?php echo is_user_logged_in() ? 'luxe-family.php' : 'login.php?redirect=luxe-family.php'; ?>" class="luxe-occasion-card">

                <div class="luxe-card-image">
                    <img
                        src="assets/images/luxe-2.jpg"
                        alt="Family and celebration tailoring"
                    >
                </div>

                <div class="luxe-card-content">

                    <h3>Family &amp; Celebrations</h3>

                    <p>
                        Outfits for families, festivals and every special occasion.
                    </p>

                    <span>
                        Plan Your Outfits →
                    </span>

                </div>

            </a>


            <!-- BULK / UNIFORM ORDERS -->
            <div class="luxe-occasion-card luxe-uniform-card">

                <div class="luxe-card-image">
                    <img
                        src="assets/images/luxe-5.jpg"
                        alt="Bulk uniform orders"
                    >
                </div>

                <div class="luxe-card-content">

                    <h3>Bulk / Uniform Orders</h3>

                    <p>
                        Coordinated outfits for schools, institutions, businesses and groups. Coming soon.
                    </p>

                    <span style="display: inline-flex; align-items: center; gap: 6px; width: fit-content; padding: 7px 16px; border-radius: 999px; background: #fdf5ea; color: #8e6c38; font-size: 12px; font-weight: 600; border: 1px solid #ebd7bf;">
                        🔒 Coming Soon
                    </span>

                </div>

            </div>

        </div>

    </section>


    <!-- WHY SHAGUN LUXE -->
    <section class="luxe-why-section">

        <div class="luxe-section-heading luxe-why-heading">

            <p class="luxe-eyebrow">THE SHAGUN DIFFERENCE</p>

            <h2>Why choose Shagun Luxe?</h2>

        </div>


        <div class="luxe-benefits">

            <div class="luxe-benefit">

                <div class="luxe-benefit-icon">♧</div>

                <h3>One Order</h3>

                <p>
                    Manage multiple people and garments together.
                </p>

            </div>


            <div class="luxe-benefit">

                <div class="luxe-benefit-icon">◎</div>

                <h3>Personalized Fit</h3>

                <p>
                    Measurements and references are handled
                    person by person.
                </p>

            </div>


            <div class="luxe-benefit">

                <div class="luxe-benefit-icon">◇</div>

                <h3>Complete Coordination</h3>

                <p>
                    From material and embroidery to final fitting.
                </p>

            </div>

        </div>


        <div class="luxe-bottom-message">

            <p>
                Let's make your special moments even more special.
            </p>

        </div>

    </section>

</main>

<?php include 'includes/footer.php'; ?>