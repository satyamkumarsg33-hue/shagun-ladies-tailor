<?php
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_user_logged_in()) {
    set_demo_user('Satyam Kumar SG', 'satyam@example.com', 'demo_fallback');
}
require_user_login('people.php');

require_once __DIR__ . '/includes/cart.php';
demo_cart_bootstrap();

/*
 * Wedding people are temporarily stored in the PHP session.
 * Later, this can be moved to the database without changing
 * the customer-facing design.
 */

if (!isset($_SESSION['luxe_wedding']) || !is_array($_SESSION['luxe_wedding'])) {
    $_SESSION['luxe_wedding'] = [];
}


/*
 * ---------------------------------------------------------
 * 1. SAVE / UPDATE PEOPLE
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Defense-in-depth: If POSTing from Step 1 while session holds a completed order, initialize fresh draft
    if (function_exists('is_luxe_order_completed') && is_luxe_order_completed($_SESSION['luxe_wedding'] ?? [])) {
        $submittedWorkflow = (isset($_POST['workflow']) && in_array($_POST['workflow'], ['family', 'wedding'], true)) 
            ? $_POST['workflow'] 
            : 'wedding';
        if (function_exists('init_fresh_luxe_draft')) {
            init_fresh_luxe_draft($submittedWorkflow, true);
        }
    }

    /*
     * Workflow context (family vs wedding)
     */
    if (isset($_POST['workflow']) && in_array($_POST['workflow'], ['family', 'wedding'], true)) {
        $_SESSION['luxe_wedding']['workflow'] = $_POST['workflow'];
        $_SESSION['luxe_wedding']['occasion'] = ($_POST['workflow'] === 'family') ? 'Family & Celebrations' : 'Wedding';
    }

    /*
     * Check payment status: date editing is strictly locked after payment is completed.
     */
    $isPaid = function_exists('is_luxe_order_completed')
        ? is_luxe_order_completed($_SESSION['luxe_wedding'] ?? [])
        : (isset($_SESSION['luxe_wedding']['payment']['status']) && $_SESSION['luxe_wedding']['payment']['status'] === 'completed');

    /*
     * Step 1 sends the requested ready date.
     */
    if (isset($_POST['requested_ready_date']) || isset($_POST['wedding_date'])) {
        $submittedDate = trim((string) ($_POST['requested_ready_date'] ?? $_POST['wedding_date'] ?? ''));

        // Server-side payment lock: reject any customer date update post-payment
        if (!$isPaid && !empty($submittedDate)) {
            $today = date('Y-m-d');
            if ($submittedDate >= $today) {
                $previousDate = $_SESSION['luxe_wedding']['requested_ready_date'] ?? null;
                $_SESSION['luxe_wedding']['requested_ready_date'] = $submittedDate;
                $_SESSION['luxe_wedding']['wedding_date'] = $submittedDate; // backward compatibility

                // Initially, Admin Estimated Delivery Date matches the customer's requested date
                if (empty($_SESSION['luxe_wedding']['admin_updated_by'])) {
                    $_SESSION['luxe_wedding']['admin_delivery_date'] = $submittedDate;
                }

                // Preserve chronological date history
                if (!isset($_SESSION['luxe_wedding']['date_history']) || !is_array($_SESSION['luxe_wedding']['date_history'])) {
                    $_SESSION['luxe_wedding']['date_history'] = [];
                }

                if ($previousDate !== $submittedDate) {
                    $_SESSION['luxe_wedding']['date_history'][] = [
                        'type' => 'customer_request',
                        'date' => $submittedDate,
                        'created_at' => time(),
                        'formatted_created' => date('d M Y, h:i A'),
                        'actor' => 'customer',
                        'note' => $previousDate
                            ? "Customer updated ready date from $previousDate to $submittedDate"
                            : "Customer requested ready date: $submittedDate"
                    ];
                }
            }
        }
    }

    if (isset($_POST['wedding_notes'])) {
        $_SESSION['luxe_wedding']['notes'] = trim((string) $_POST['wedding_notes']);
    }

    /*
     * Wedding Details page sends the initial number of people.
     */
    if (isset($_POST['people_count'])) {

        $submittedCount = trim((string) $_POST['people_count']);

        /*
         * For the current builder we support exact counts 1–10.
         * The Wedding Details page also has 11–15 and 26+ options,
         * which we will handle later as a separate enquiry/bulk flow.
         */
        if (ctype_digit($submittedCount)) {

            $peopleCount = max(1, min(10, (int) $submittedCount));

            /*
             * Save the selected count.
             */
            $_SESSION['luxe_wedding']['people_count'] = $peopleCount;

            /*
             * Create the initial people only if they don't already exist.
             * This prevents names from being erased when returning
             * from Garments to People.
             */
            if (
                !isset($_SESSION['luxe_wedding']['people']) ||
                !is_array($_SESSION['luxe_wedding']['people'])
            ) {
                $_SESSION['luxe_wedding']['people'] = [];
            }

            $existingPeople = $_SESSION['luxe_wedding']['people'];

            /*
             * Keep the existing people and add/remove people so the
             * number matches the selected count.
             */
            $updatedPeople = [];

            for ($i = 0; $i < $peopleCount; $i++) {

                if (isset($existingPeople[$i]) && is_array($existingPeople[$i])) {

                    $updatedPeople[] = [
                        'name' => (string) ($existingPeople[$i]['name'] ?? ''),
                        'role' => (string) ($existingPeople[$i]['role'] ?? ''),
                        'garments' => is_array($existingPeople[$i]['garments'] ?? null)
                            ? $existingPeople[$i]['garments']
                            : []
                    ];

                } else {

                    $updatedPeople[] = [
                        'name' => '',
                        'role' => '',
                        'garments' => []
                    ];
                }
            }

            $_SESSION['luxe_wedding']['people'] = $updatedPeople;
        }
    }


    /*
     * People page submits the actual names and roles.
     */
    if (isset($_POST['save_people'])) {

        $names = $_POST['person_name'] ?? [];
        $roles = $_POST['person_role'] ?? [];
        $garmentsInputs = $_POST['person_garments'] ?? [];

        if (!is_array($names)) {
            $names = [];
        }

        if (!is_array($roles)) {
            $roles = [];
        }

        if (!is_array($garmentsInputs)) {
            $garmentsInputs = [];
        }

        $existingPeople = $_SESSION['luxe_wedding']['people'] ?? [];

        if (!is_array($existingPeople)) {
            $existingPeople = [];
        }

        $updatedPeople = [];

        foreach ($names as $index => $name) {

            $name = trim((string) $name);
            $role = trim((string) ($roles[$index] ?? ''));

            /*
             * Don't save completely empty people.
             */
            if ($name === '') {
                continue;
            }

            $personGarments = [];

            if (isset($garmentsInputs[$index])) {
                $decoded = json_decode($garmentsInputs[$index], true);
                if (is_array($decoded)) {
                    $personGarments = $decoded;
                }
            }

            if (
                empty($personGarments) &&
                isset($existingPeople[$index]) &&
                is_array($existingPeople[$index]) &&
                isset($existingPeople[$index]['garments']) &&
                is_array($existingPeople[$index]['garments'])
            ) {
                if (($existingPeople[$index]['name'] ?? '') === $name || empty($existingPeople[$index]['name'])) {
                    $personGarments = $existingPeople[$index]['garments'];
                }
            }

            $updatedPeople[] = [
                'name' => $name,
                'role' => $role,
                'garments' => $personGarments
            ];
        }

        /*
         * Save the people.
         */
        $_SESSION['luxe_wedding']['people'] = $updatedPeople;

        /*
         * Keep the count synchronized with the actual people.
         */
        $_SESSION['luxe_wedding']['people_count'] = count($updatedPeople);


        /*
         * Continue to Garments.
         */
        $gotoPerson = (int) ($_POST['goto_person'] ?? 0);
        if ($gotoPerson >= 1 && $gotoPerson <= count($updatedPeople)) {
            header('Location: garments.php?person=' . $gotoPerson);
        } else {
            header('Location: garments.php');
        }
        exit;
    }
}


/*
 * ---------------------------------------------------------
 * 2. READ PEOPLE FROM SESSION
 * ---------------------------------------------------------
 */

// If accessing people.php via GET when the order is already completed, redirect to orders page
if (function_exists('is_luxe_order_completed') && is_luxe_order_completed($_SESSION['luxe_wedding'] ?? [])) {
    $completedRef = $_SESSION['luxe_wedding']['order_ref'] ?? ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '');
    if (!empty($completedRef)) {
        header('Location: orders.php?ref=' . urlencode($completedRef));
    } else {
        header('Location: luxe-stitching.php');
    }
    exit;
}

$people = $_SESSION['luxe_wedding']['people'] ?? [];

if (!is_array($people) || count($people) === 0) {

    /*
     * Safety fallback if somebody opens people.php directly.
     */
    $peopleCount = (int) ($_SESSION['luxe_wedding']['people_count'] ?? 1);
    $peopleCount = max(1, min(10, $peopleCount));

    $people = [];

    for ($i = 0; $i < $peopleCount; $i++) {

        $people[] = [
            'name' => '',
            'role' => '',
            'garments' => []
        ];
    }


    $_SESSION['luxe_wedding']['people'] = $people;
    $initialPeople = count($people);
}


/*
 * Current number of people and workflow context.
 */
$peopleCount = count($people);
$workflow = $_SESSION['luxe_wedding']['workflow'] ?? 'wedding';
$isFamily = ($workflow === 'family');
$backUrl = $isFamily ? './luxe-family.php' : './luxe-wedding.php';
$backText = $isFamily ? '← Back to Family Details' : '← Back to Wedding Details';
$heroTitle = $isFamily ? 'Your Family Members' : 'Your Wedding Party';
$heroDesc = $isFamily 
    ? 'Add the family members for whom you want to plan outfits. You can add multiple people.' 
    : 'Add the people for whom you want to plan the tailoring. You can add multiple people.';
$heroImg = $isFamily ? './assets/images/luxe-2.jpg' : './assets/images/luxe-1.jpg';
$rolePlaceholder = $isFamily ? 'e.g. Mother, Father, Daughter, Sister' : 'e.g. Bride, Sister, Friend';

include __DIR__ . '/includes/header.php';
?>

<main class="people-builder">

    <!-- PEOPLE BUILDER HEADER -->
    <section class="people-builder-top">

        <a href="<?php echo $backUrl; ?>" class="people-back">
            <?php echo $backText; ?>
        </a>

        <?php
        require_once __DIR__ . '/includes/luxe-stepper.php';
        render_luxe_stepper(2);
        ?>

    </section>


    <!-- PEOPLE INTRO -->
    <section class="people-intro">

        <div class="people-intro-content">

            <p class="people-eyebrow">
                SHAGUN LUXE
            </p>

            <h1>
                <?php echo htmlspecialchars($heroTitle); ?>
            </h1>

            <p class="people-intro-text">
                <?php echo htmlspecialchars($heroDesc); ?>
            </p>

        </div>

        <div class="people-intro-image">

            <img
                src="<?php echo $heroImg; ?>"
                alt="<?php echo htmlspecialchars($heroTitle); ?>"
            >

        </div>

    </section>


    <!-- PEOPLE CARD -->
    <section class="people-card">

        <form method="post" action="people.php" id="people-form">

            <div class="people-list" id="people-list">

                <?php foreach ($people as $i => $person): ?>

                    <?php
                    $personNumber = $i + 1;
                    $personName = (string) ($person['name'] ?? '');
                    $personRole = (string) ($person['role'] ?? '');
                    $garmentCount = count(
                        is_array($person['garments'] ?? null)
                            ? $person['garments']
                            : []
                    );
                    ?>

                    <article class="person-card">

                        <div class="person-heading">

                            <span class="person-icon" aria-hidden="true">
                                ♟
                            </span>

                            <strong>
                                Person <?php echo $personNumber; ?>
                            </strong>

                        </div>


                        <div class="person-fields">
                            <input
                                type="hidden"
                                name="person_garments[]"
                                value="<?php echo htmlspecialchars(json_encode($person['garments'] ?? []), ENT_QUOTES, 'UTF-8'); ?>"
                            >

                            <div class="person-field">

                                <label>
                                    Name <span>*</span>
                                </label>

                                <input
                                    type="text"
                                    name="person_name[]"
                                    value="<?php echo htmlspecialchars($personName, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Enter name"
                                    autocomplete="name"
                                    required
                                >

                            </div>


                            <div class="person-field">

                                <label>
                                    Relationship / Role
                                    <small>(Optional)</small>
                                </label>

                                <input
                                    type="text"
                                    name="person_role[]"
                                    value="<?php echo htmlspecialchars($personRole, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="<?php echo htmlspecialchars($rolePlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
                                >

                            </div>

                        </div>


                        <div class="person-actions">

                            <div class="person-garments">

                                <span aria-hidden="true">
                                    ♧
                                </span>

                                <small>
                                    <?php echo $garmentCount; ?>
                                    <?php echo $garmentCount === 1 ? 'garment' : 'garments'; ?>
                                    added
                                </small>

                            </div>

                            <button
                                type="button"
                                class="person-garment-button"
                                data-garment-button
                            >
                                Add Garments →
                            </button>

                        </div>


                        <?php if ($personNumber > 1): ?>

                            <button
                                type="button"
                                class="person-remove"
                                data-remove-person
                                aria-label="Remove Person <?php echo $personNumber; ?>"
                            >
                                ×
                            </button>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>


            <!-- ADD ANOTHER PERSON -->
            <button
                type="button"
                class="people-add-person"
                id="add-person"
            >
                <span>+</span>
                Add another person
            </button>


            <!-- BOTTOM NAVIGATION -->
            <div class="people-navigation">

                <a
                    href="<?php echo $backUrl; ?>"
                    class="people-secondary-button"
                >
                    <?php echo $backText; ?>
                </a>


                <button
                    type="submit"
                    name="save_people"
                    value="1"
                    class="people-primary-button"
                    id="continue-garments"
                >
                    Continue → Add Garments
                </button>

            </div>


            <p class="people-save-note">
                ⓘ You can always make changes later.
            </p>

        </form>

    </section>

</main>












<?php include __DIR__ . '/includes/footer.php'; ?>