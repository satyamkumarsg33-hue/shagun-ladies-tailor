<?php
require_once __DIR__ . '/includes/auth.php';
require_user_login('garments.php');

require_once __DIR__ . '/includes/cart.php';
demo_cart_bootstrap();

if (!isset($_SESSION['luxe_wedding']) || !is_array($_SESSION['luxe_wedding'])) {
    $_SESSION['luxe_wedding'] = [];
}

// If session holds an already completed order, redirect to orders page
if (function_exists('is_luxe_order_completed') && is_luxe_order_completed($_SESSION['luxe_wedding'])) {
    $completedRef = $_SESSION['luxe_wedding']['order_ref'] ?? ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '');
    if (!empty($completedRef)) {
        header('Location: orders.php?ref=' . urlencode($completedRef));
    } else {
        header('Location: luxe-stitching.php');
    }
    exit;
}

$luxeWedding = &$_SESSION['luxe_wedding'];

/*
 * Get people from the Luxe wedding session.
 */
$people = $luxeWedding['people'] ?? [];

if (!is_array($people) || count($people) === 0) {
    header('Location: people.php');
    exit;
}


/*
 * Make sure every person has the expected structure.
 */
foreach ($people as $index => &$person) {

    if (!is_array($person)) {
        $person = [];
    }

    $person['name'] = (string) ($person['name'] ?? '');
    $person['role'] = (string) ($person['role'] ?? '');

    if (
        !isset($person['garments']) ||
        !is_array($person['garments'])
    ) {
        $person['garments'] = [];
    }
}

unset($person);


/*
 * Allowed Luxe garments list.
 */
$allowedGarments = [
    'Lehenga',
    'Saree',
    'Blouse',
    'Anarkali',
    'Salwar Suit',
    'Sherwani',
    'Gown',
    'Indo Western',
    'Dupatta',
    'Petticoat',
    'Kurta Set'
];


/*
 * Save garments for ALL people and redirect to Luxe Workspace.
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    (isset($_POST['save_all_garments']) || isset($_POST['garments_by_person']))
) {
    $submittedGarmentsByPerson = [];

    if (isset($_POST['garments_by_person']) && is_string($_POST['garments_by_person'])) {
        $decoded = json_decode($_POST['garments_by_person'], true);
        if (is_array($decoded)) {
            $submittedGarmentsByPerson = $decoded;
        }
    }

    if (empty($submittedGarmentsByPerson) && isset($_POST['people_garments']) && is_array($_POST['people_garments'])) {
        $submittedGarmentsByPerson = $_POST['people_garments'];
    }

    foreach ($people as $index => &$person) {
        $personNumber = $index + 1;
        $rawGarments = $submittedGarmentsByPerson[$personNumber] ?? $submittedGarmentsByPerson[(string) $personNumber] ?? null;

        if ($rawGarments !== null) {
            $desiredQuantities = [];
            if (is_array($rawGarments)) {
                // Check if associative ['Blouse' => 2, 'Saree' => 1] or indexed ['Blouse', 'Blouse', 'Saree']
                $isAssoc = (array_keys($rawGarments) !== range(0, count($rawGarments) - 1));
                if ($isAssoc) {
                    foreach ($rawGarments as $gName => $qty) {
                        $gNameStr = (string) $gName;
                        if (in_array($gNameStr, $allowedGarments, true)) {
                            $desiredQuantities[$gNameStr] = max(0, (int) $qty);
                        }
                    }
                } else {
                    foreach ($rawGarments as $gName) {
                        $gNameStr = is_string($gName) ? $gName : (is_array($gName) ? ($gName['name'] ?? '') : '');
                        $gNameStr = trim((string) $gNameStr);
                        if (in_array($gNameStr, $allowedGarments, true)) {
                            $desiredQuantities[$gNameStr] = ($desiredQuantities[$gNameStr] ?? 0) + 1;
                        }
                    }
                }
            }

            // Existing garments grouped by garment type
            $existingByType = [];
            if (isset($person['garments']) && is_array($person['garments'])) {
                foreach ($person['garments'] as $eg) {
                    $egName = is_array($eg) ? ($eg['name'] ?? $eg['garment'] ?? '') : (string) $eg;
                    $egName = trim((string) $egName);
                    if ($egName !== '') {
                        $existingByType[$egName][] = $eg;
                    }
                }
            }

            // Build new physical garments list preserving existing customized records
            $finalGarments = [];
            foreach ($allowedGarments as $gType) {
                $targetQty = $desiredQuantities[$gType] ?? 0;
                $existingList = $existingByType[$gType] ?? [];
                for ($i = 0; $i < $targetQty; $i++) {
                    if (isset($existingList[$i])) {
                        // Preserve existing physical garment record with all customizations, styles, prices, notes!
                        $finalGarments[] = $existingList[$i];
                    } else {
                        // New physical garment instance
                        $finalGarments[] = [
                            'name' => $gType,
                            'status' => 'not-started',
                            'summary' => ''
                        ];
                    }
                }
            }

            $person['garments'] = $finalGarments;
        }
    }
    unset($person);

    $luxeWedding['people'] = $people;
    $_SESSION['luxe_wedding']['people'] = $people;

    header('Location: luxe-workspace.php');
    exit;
}


/*
 * Save garments for one specific person (backward compatibility).
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_garments'])
) {

    $personNumber = (int) ($_POST['person'] ?? 0);

    if (
        $personNumber < 1 ||
        $personNumber > count($people)
    ) {
        header('Location: luxe-workspace.php');
        exit;
    }

    $raw = $_POST['garments'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }

    $desiredQuantities = [];
    $isAssoc = (array_keys($raw) !== range(0, count($raw) - 1));
    if ($isAssoc) {
        foreach ($raw as $gName => $qty) {
            if (in_array((string) $gName, $allowedGarments, true)) {
                $desiredQuantities[(string) $gName] = max(0, (int) $qty);
            }
        }
    } else {
        foreach ($raw as $gName) {
            $gNameStr = trim((string) $gName);
            if (in_array($gNameStr, $allowedGarments, true)) {
                $desiredQuantities[$gNameStr] = ($desiredQuantities[$gNameStr] ?? 0) + 1;
            }
        }
    }

    $existingByType = [];
    $targetPersonGarments = $people[$personNumber - 1]['garments'] ?? [];
    if (is_array($targetPersonGarments)) {
        foreach ($targetPersonGarments as $eg) {
            $egName = is_array($eg) ? ($eg['name'] ?? $eg['garment'] ?? '') : (string) $eg;
            $egName = trim((string) $egName);
            if ($egName !== '') {
                $existingByType[$egName][] = $eg;
            }
        }
    }

    $finalGarments = [];
    foreach ($allowedGarments as $gType) {
        $targetQty = $desiredQuantities[$gType] ?? 0;
        $existingList = $existingByType[$gType] ?? [];
        for ($i = 0; $i < $targetQty; $i++) {
            if (isset($existingList[$i])) {
                $finalGarments[] = $existingList[$i];
            } else {
                $finalGarments[] = [
                    'name' => $gType,
                    'status' => 'not-started',
                    'summary' => ''
                ];
            }
        }
    }

    $people[$personNumber - 1]['garments'] = $finalGarments;

    $luxeWedding['people'] = $people;
    $_SESSION['luxe_wedding']['people'] = $people;

    header(
        'Location: garments.php?person=' . $personNumber
    );
    exit;
}


/*
 * Save cleaned people data.
 */
$luxeWedding['people'] = $people;


/*
 * Determine the active person.
 */
$activePerson = (int) ($_GET['person'] ?? 1);

if (
    $activePerson < 1 ||
    $activePerson > count($people)
) {
    $activePerson = 1;
}


/*
 * Build garments by person map for frontend initialization.
 * Stores quantity per garment type: { 1: { "Blouse": 2, "Saree": 1 }, ... }
 */
$allGarmentsByPerson = [];
foreach ($people as $index => $person) {
    $personNumber = $index + 1;
    $countsByType = [];
    $rawList = $person['garments'] ?? [];
    if (is_array($rawList)) {
        foreach ($rawList as $g) {
            $gName = is_string($g) ? $g : (is_array($g) ? ($g['name'] ?? $g['garment'] ?? '') : '');
            $gName = trim((string) $gName);
            if ($gName !== '') {
                $countsByType[$gName] = ($countsByType[$gName] ?? 0) + 1;
            }
        }
    }
    $allGarmentsByPerson[$personNumber] = $countsByType;
}

$activeGarments = $allGarmentsByPerson[$activePerson] ?? [];


include __DIR__ . '/includes/header.php';
?>


<main
    class="luxe-garments-page"
    data-active-person="<?php echo $activePerson; ?>"
    data-selected-garments="<?php echo htmlspecialchars(json_encode($activeGarments), ENT_QUOTES, 'UTF-8'); ?>"
    data-all-garments="<?php echo htmlspecialchars(json_encode($allGarmentsByPerson), ENT_QUOTES, 'UTF-8'); ?>"
>

    <!-- BACK + PROGRESS -->
    <section class="luxe-builder-top">

        <a href="people.php" class="luxe-builder-back">
            ← Back to People
        </a>

        <?php
        require_once __DIR__ . '/includes/luxe-stepper.php';
        render_luxe_stepper(3);
        ?>

    </section>


    <!-- HERO BANNER -->
    <section class="luxe-garments-banner">

        <div class="luxe-garments-banner-content">

            <p class="luxe-garments-eyebrow">
                SHAGUN LUXE
            </p>

            <h1>
                Choose Garments
            </h1>

            <p>
                Select the garments you want to add for this person.
            </p>

        </div>

        <div class="luxe-garments-banner-image">
            <img
                src="<?php echo (($luxeWedding['workflow'] ?? '') === 'family') ? 'assets/images/luxe-2.jpg' : 'assets/images/luxe-1.jpg'; ?>"
                alt="Shagun Luxe tailoring"
            >
        </div>

    </section>


    <!-- PERSON SELECTOR -->
   <section class="luxe-person-selector">

    <div class="luxe-person-selector-intro">

        <span class="luxe-person-icon">♟</span>

        <div>
            <?php
            $activePersonIndex = $activePerson - 1;
            $activePersonName = trim((string) ($people[$activePersonIndex]['name'] ?? ''));
            $activePersonRole = trim((string) ($people[$activePersonIndex]['role'] ?? ''));
            ?>
            <strong>
                <?php echo htmlspecialchars($activePersonName !== '' ? $activePersonName : 'Person ' . $activePerson, ENT_QUOTES, 'UTF-8'); ?>
            </strong>

            <small>
                <?php echo htmlspecialchars($activePersonRole !== '' ? $activePersonRole : 'No role added', ENT_QUOTES, 'UTF-8'); ?>
            </small>
        </div>

    </div>


    <div class="luxe-person-tabs">

        <?php foreach ($people as $index => $person): ?>

            <?php
            $personNumber = $index + 1;
            $personName = trim((string) ($person['name'] ?? ''));
            $personRole = trim((string) ($person['role'] ?? ''));
            ?>

            <button
                type="button"
                class="luxe-person-tab <?php echo $personNumber === $activePerson ? 'is-active' : ''; ?>"
                data-person="<?php echo $personNumber; ?>"
            >
                <strong>
                    <?php echo htmlspecialchars(
                        $personName ?: 'Person ' . $personNumber,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </strong>

                <small>
                    <?php echo htmlspecialchars(
                        $personRole ?: 'No role added',
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </small>
            </button>

        <?php endforeach; ?>


        <button
            type="button"
            class="luxe-add-person-tab"
            onclick="window.location.href='people.php'"
        >
            <span>+</span>
            <span class="desktop-add-person">Add Another Person</span>
        </button>

    </div>

</section>


    <!-- GARMENTS -->
    <section class="luxe-garment-section">

        <div class="luxe-garment-heading">

            <h2>Select Garments</h2>

            <span>
                <strong id="selected-garment-count">0</strong>
                garment(s) selected
            </span>

        </div>


        <div class="luxe-garment-grid">

            <!-- LEHENGA -->
            <div class="luxe-garment-card" data-garment="Lehenga">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/lehenga.jpg"
                        alt="Lehenga"
                    >
                </div>
                <strong>Lehenga</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Lehenga quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Lehenga quantity">+</button>
                </div>
            </div>


            <!-- SAREE -->
            <div class="luxe-garment-card" data-garment="Saree">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/luxe-2.jpg"
                        alt="Saree"
                    >
                </div>
                <strong>Saree</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Saree quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Saree quantity">+</button>
                </div>
            </div>


            <!-- BLOUSE -->
            <div class="luxe-garment-card" data-garment="Blouse">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/blouse.jpg"
                        alt="Blouse"
                    >
                </div>
                <strong>Blouse</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Blouse quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Blouse quantity">+</button>
                </div>
            </div>


            <!-- ANARKALI -->
            <div class="luxe-garment-card" data-garment="Anarkali">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/design 3.jpg"
                        alt="Anarkali"
                    >
                </div>
                <strong>Anarkali</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Anarkali quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Anarkali quantity">+</button>
                </div>
            </div>


            <!-- SALWAR SUIT -->
            <div class="luxe-garment-card" data-garment="Salwar Suit">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/kurti.jpg"
                        alt="Salwar Suit"
                    >
                </div>
                <strong>Salwar Suit</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Salwar Suit quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Salwar Suit quantity">+</button>
                </div>
            </div>


            <!-- SHERWANI -->
            <div class="luxe-garment-card" data-garment="Sherwani">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/design 1.jpg"
                        alt="Sherwani"
                    >
                </div>
                <strong>Sherwani</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Sherwani quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Sherwani quantity">+</button>
                </div>
            </div>


            <!-- GOWN -->
            <div class="luxe-garment-card" data-garment="Gown">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/design 2.jpg"
                        alt="Gown"
                    >
                </div>
                <strong>Gown</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Gown quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Gown quantity">+</button>
                </div>
            </div>


            <!-- INDO WESTERN -->
            <div class="luxe-garment-card" data-garment="Indo Western">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/luxe-3.jpg"
                        alt="Indo Western"
                    >
                </div>
                <strong>Indo Western</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Indo Western quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Indo Western quantity">+</button>
                </div>
            </div>


            <!-- DUPATTA -->
            <div class="luxe-garment-card" data-garment="Dupatta">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/luxe-4.jpg"
                        alt="Dupatta"
                    >
                </div>
                <strong>Dupatta</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Dupatta quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Dupatta quantity">+</button>
                </div>
            </div>


            <!-- PETTICOAT -->
            <div class="luxe-garment-card" data-garment="Petticoat">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/luxe-5.jpg"
                        alt="Petticoat"
                    >
                </div>
                <strong>Petticoat</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Petticoat quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Petticoat quantity">+</button>
                </div>
            </div>


            <!-- KURTA SET -->
            <div class="luxe-garment-card" data-garment="Kurta Set">
                <div class="luxe-garment-image">
                    <img
                        src="assets/images/design 3.jpg"
                        alt="Kurta Set"
                    >
                </div>
                <strong>Kurta Set</strong>
                <div class="luxe-garment-qty" data-qty-control>
                    <button type="button" class="luxe-qty-btn luxe-qty-minus" data-qty-action="minus" aria-label="Decrease Kurta Set quantity">−</button>
                    <span class="luxe-qty-value" data-qty-display>0</span>
                    <button type="button" class="luxe-qty-btn luxe-qty-plus" data-qty-action="plus" aria-label="Increase Kurta Set quantity">+</button>
                </div>
            </div>


            <!-- CUSTOM GARMENT -->
            <button
                type="button"
                class="luxe-custom-garment"
            >

                <span class="luxe-custom-garment-icon">
                    ♧
                </span>

                <strong>
                    Custom Garment
                </strong>

                <small>
                    Tell us what you need
                </small>

            </button>

        </div>


        <!-- ACTIONS -->
        <div class="luxe-garment-actions">

            <a
                href="people.php"
                class="luxe-garment-back"
            >
                ← Back to People
            </a>


            <div class="luxe-garment-continue-wrap">

                <button
                    type="button"
                    class="luxe-garment-continue"
                    id="continue-garments"
                    disabled
                >
                    Continue
                    <span>→</span>
                    Customize
                </button>

                <p>
                    ⓘ You can always make changes later.
                </p>

            </div>

        </div>

    </section>

</main>





<?php
include __DIR__ . '/includes/footer.php';
?>