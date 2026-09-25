<?php
require_once __DIR__ . '/includes/auth.php';
require_user_login('luxe-family.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If the existing session contains an already paid/submitted order, or if new order is explicitly requested,
// cleanly initialize a fresh draft for a new family & celebrations order without leaking previous order state.
if (is_luxe_order_completed() || isset($_GET['new']) || isset($_GET['reset']) || empty($_SESSION['luxe_wedding'])) {
    init_fresh_luxe_draft('family', isset($_GET['new']) || isset($_GET['reset']) || is_luxe_order_completed());
} else {
    $_SESSION['luxe_wedding']['workflow'] = 'family';
    $_SESSION['luxe_wedding']['occasion'] = 'Family & Celebrations';
}

$luxe = $_SESSION['luxe_wedding'];
// Defensive: In a genuine fresh draft $isPaid is false. If inspecting a confirmed order, lock stays enforced.
$isPaid = is_luxe_order_completed($luxe);
$currentRequestedDate = $luxe['requested_ready_date'] ?? ($luxe['wedding_date'] ?? '');

include __DIR__ . '/includes/header.php';
?>

<main class="wedding-builder">

    <!-- FAMILY BUILDER HEADER -->
    <section class="wedding-builder-top">

        <a href="./luxe-stitching.php" class="wedding-back">
            ← Back to Shagun Luxe
        </a>

        <?php
        require_once __DIR__ . '/includes/luxe-stepper.php';
        render_luxe_stepper(1);
        ?>

    </section>


    <!-- FAMILY INTRO -->
    <section class="wedding-intro">

        <div class="wedding-intro-content">

            <p class="wedding-eyebrow">SHAGUN LUXE</p>

            <h1>
                Family &amp; Celebrations
            </h1>

            <p class="wedding-intro-text">
                One order for your entire family.
            </p>

            <p class="wedding-intro-text" style="font-size: 14px; line-height: 1.6; margin-top: 10px; color: #5a4949;">
                Plan coordinated outfits for your family members — from traditional wear to modern styles. Add multiple people, manage different garments, and get everything tailored with care in one seamless order.
            </p>

            <div class="wedding-intro-highlights">

                <div>
                    <span>◇</span>
                    <strong>Multiple Family</strong>
                    <small>Members</small>
                </div>

                <div>
                    <span>♧</span>
                    <strong>Different</strong>
                    <small>Garments</small>
                </div>

                <div>
                    <span>♡</span>
                    <strong>Personalized for</strong>
                    <small>Every Style</small>
                </div>

            </div>

        </div>

        <div class="wedding-intro-image">
            <img
                src="./assets/images/luxe-2.jpg"
                alt="Family & Celebrations tailoring"
            >
        </div>

    </section>


    <!-- FAMILY DETAILS -->
    <section class="wedding-details-card">

        <div class="wedding-card-heading">

            <p class="wedding-card-eyebrow">
                STEP 1 OF 7
            </p>

            <h2>Family Details</h2>

            <p>
                Help us understand your timeline so we can plan
                the tailoring for each person.
            </p>

        </div>


        <form class="wedding-details-form" action="people.php" method="post">
            <input type="hidden" name="workflow" value="family">

            <!-- DATE -->
            <div class="wedding-field">

                <label for="requested-ready-date">
                    When do you need your garments? <span>*</span>
                </label>

                <p class="wedding-field-help">
                    Select the date by which you would like your garments to be ready.
                </p>

                <input
                    type="date"
                    id="requested-ready-date"
                    name="requested_ready_date"
                    min="<?php echo date('Y-m-d'); ?>"
                    value="<?php echo htmlspecialchars((string) $currentRequestedDate); ?>"
                    <?php if ($isPaid): ?>readonly disabled title="Your order has been booked. Requested ready date cannot be changed online."<?php else: ?>required<?php endif; ?>
                >
                <?php if ($isPaid): ?>
                    <input type="hidden" name="requested_ready_date" value="<?php echo htmlspecialchars((string) $currentRequestedDate); ?>">
                    <p class="wedding-field-help" style="color: #9e6c38; font-weight: 600; margin-top: 6px;">
                        🔒 Order Confirmed: Your ready date is locked. To adjust timing, please contact Shagun Ladies Tailor directly.
                    </p>
                <?php endif; ?>

            </div>


            <!-- NUMBER OF PEOPLE -->
            <div class="wedding-field">

                <label for="people-count">
                    How many people are you planning outfits for? <span>*</span>
                </label>

                <select
                    id="people-count"
                    name="people_count"
                    required
                >
                    <option value="">Select number of people</option>
                    <option value="1">1 person</option>
                    <option value="2">2 people</option>
                    <option value="3">3 people</option>
                    <option value="4">4 people</option>
                    <option value="5">5 people</option>
                    <option value="6">6 people</option>
                    <option value="7">7 people</option>
                    <option value="8">8 people</option>
                    <option value="9">9 people</option>
                    <option value="10">10 people</option>
                    <option value="11-15">11–15 people</option>
                    <option value="16-25">16–25 people</option>
                    <option value="26+">26+ people</option>
                </select>

            </div>


            <!-- NOTES -->
            <div class="wedding-field">

                <label for="wedding-notes">
                    Additional Notes <small>(Optional)</small>
                </label>

                <textarea
                    id="wedding-notes"
                    name="wedding_notes"
                    rows="4"
                    placeholder="Share any special requirements, themes or ideas..."
                ><?php echo htmlspecialchars((string) ($luxe['notes'] ?? '')); ?></textarea>

            </div>


            <!-- CONTINUE -->
            <button
                type="submit"
                class="wedding-primary-button"
            >
                Continue → Add People
            </button>

            <p class="wedding-save-note">
                ◉ You can always make changes later.
            </p>

        </form>

    </section>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
