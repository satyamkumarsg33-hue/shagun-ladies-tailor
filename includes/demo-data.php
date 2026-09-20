<?php

declare(strict_types=1);

/**
 * Shagun Ladies Tailor — Catalogue Bridge & Controlled Fallback
 * 
 * In Phase 2, this file bridges existing customer-facing pages to the authoritative
 * database catalogue access layer (includes/catalogue.php).
 * 
 * Fallback Policy:
 * - Local Development (APP_ENV=local): Database is authoritative when online.
 *   If the database connection is unavailable, fallback to static demo data is permitted
 *   ONLY with an explicit logged warning so developers are notified immediately.
 * - Production (APP_ENV=production): Database is strictly authoritative.
 *   If the database is unreachable, a 503 Service Unavailable exception is thrown.
 *   Stale demo data is NEVER silently substituted in production.
 */

require_once __DIR__ . '/catalogue.php';

/**
 * Standard Stitching Categories
 * Delegated to MySQL table: garment_categories
 */
function standard_stitching_categories(): array
{
    return catalogue_garment_categories();
}

/**
 * Blouse Cuts & Styles
 * Delegated to MySQL table: garment_styles
 */
function blouse_styles(): array
{
    return catalogue_blouse_styles();
}

/**
 * Blouse Style by Slug
 * Delegated to MySQL table: garment_styles
 */
function blouse_style_by_slug(string $slug): ?array
{
    return catalogue_blouse_style_by_slug($slug);
}

/**
 * Blouse Customization Feature Groups and Options
 * Delegated to MySQL tables: customization_groups & customization_options
 */
function blouse_customization_options(): array
{
    return catalogue_blouse_customization_options();
}

/**
 * Specific Customization Choice by Field and Value
 * Delegated to MySQL tables: customization_groups & customization_options
 */
function demo_choice(string $field, string $value): ?array
{
    return catalogue_choice($field, $value);
}

/**
 * Machine Embroidery Work Designs
 * Delegated to MySQL table: embroidery_designs (category = 'machine')
 */
function luxe_machine_work_designs(): array
{
    return catalogue_machine_work_designs();
}

/**
 * Hand Embroidery Work Designs
 * Delegated to MySQL table: embroidery_designs (category = 'hand')
 */
function luxe_hand_work_designs(): array
{
    return catalogue_hand_work_designs();
}

/**
 * Standard Embroidery Placement Positions
 * Delegated to MySQL table: work_placements
 */
function luxe_work_placements(): array
{
    return catalogue_work_placements();
}

// ============================================================================
// CONTROLLED LOCAL DEVELOPMENT FALLBACK DATASETS
// Only invoked by includes/catalogue.php if APP_ENV === 'local' and DB fails
// ============================================================================

function demo_data_fallback_standard_stitching_categories(): array
{
    return [
        ['name' => 'Blouse Stitching', 'slug' => 'blouse', 'description' => 'Tailored blouses with fitting, finishing, and custom details.', 'image' => 'assets/images/blouse-work.jpg', 'href' => 'blouse-styles.php', 'available' => true],
        ['name' => 'Lehenga Stitching', 'slug' => 'lehenga', 'description' => 'Festive silhouettes made for your celebration.', 'image' => 'assets/images/lehenga.jpg', 'available' => false],
        ['name' => 'Kurti & Top Stitching', 'slug' => 'kurti', 'description' => 'Comfort-led everyday and occasion wear.', 'image' => 'assets/images/kurti.jpg', 'available' => false],
        ['name' => 'Salwar Suit Stitching', 'slug' => 'salwar-suit', 'description' => 'Coordinated pieces with refined finishing.', 'image' => 'assets/images/design 1.jpg', 'available' => false],
        ['name' => 'Anarkali Stitching', 'slug' => 'anarkali', 'description' => 'Graceful flow and a made-for-you fit.', 'image' => 'assets/images/design 2.jpg', 'available' => false],
        ['name' => 'Gown Stitching', 'slug' => 'gown', 'description' => 'Elegant occasion wear, tailored with care.', 'image' => 'assets/images/luxe-5.jpg', 'available' => false],
        ['name' => 'Alterations', 'slug' => 'alterations', 'description' => 'Thoughtful fit corrections for favourite outfits.', 'image' => 'assets/images/design 3.jpg', 'available' => false],
    ];
}

function demo_data_fallback_blouse_styles(): array
{
    return [
        ['slug' => 'u-cut', 'name' => 'U-Cut Blouse', 'description' => 'An elegant curved neckline for versatile occasion wear.', 'price' => 650, 'image' => 'assets/images/blouse.jpg'],
        ['slug' => 'princess-cut', 'name' => 'Princess Cut Blouse', 'description' => 'Contoured seams for a structured, flattering fit.', 'price' => 800, 'image' => 'assets/images/luxe-1.jpg'],
        ['slug' => 'katori-cut', 'name' => 'Katori Cut Blouse', 'description' => 'Classic shaping with support and a polished silhouette.', 'price' => 900, 'image' => 'assets/images/luxe-2.jpg'],
        ['slug' => 'panel-cut', 'name' => 'Panel Cut Blouse', 'description' => 'Refined panel construction with a tailored finish.', 'price' => 850, 'image' => 'assets/images/luxe-3.jpg'],
        ['slug' => 'boat-neck', 'name' => 'Boat Neck Blouse', 'description' => 'A graceful wide neckline for understated elegance.', 'price' => 700, 'image' => 'assets/images/luxe-4.jpg'],
        ['slug' => 'high-neck', 'name' => 'High Neck Blouse', 'description' => 'A polished neckline with a modern, elevated feel.', 'price' => 750, 'image' => 'assets/images/luxe-5.jpg'],
        ['slug' => 'v-neck', 'name' => 'V-Neck Blouse', 'description' => 'A clean, elongating neckline for a timeless look.', 'price' => 700, 'image' => 'assets/images/blouse-work.jpg'],
        ['slug' => 'deep-back', 'name' => 'Back Open / Deep Back', 'description' => 'A statement back design customised to your comfort.', 'price' => 850, 'image' => 'assets/images/luxe-6.jpg'],
        ['slug' => 'basic-traditional', 'name' => 'Basic / Traditional Blouse', 'description' => 'Reliable everyday tailoring with a neat classic finish.', 'price' => 550, 'image' => 'assets/images/design 1.jpg'],
        ['slug' => 'designer', 'name' => 'Designer Blouse', 'description' => 'A detail-led piece for special occasions and celebrations.', 'price' => 1100, 'image' => 'assets/images/design 2.jpg'],
    ];
}

function demo_data_fallback_blouse_style_by_slug(string $slug): ?array
{
    foreach (demo_data_fallback_blouse_styles() as $style) {
        if ($style['slug'] === $slug) {
            return $style;
        }
    }
    return null;
}

function demo_data_fallback_blouse_customization_options(): array
{
    return [
        'neck_design' => ['label' => 'Neck design', 'required' => true, 'choices' => [['value' => 'style-default', 'label' => 'Style default', 'price' => 0], ['value' => 'sweetheart', 'label' => 'Sweetheart neck', 'price' => 100], ['value' => 'square', 'label' => 'Square neck', 'price' => 80], ['value' => 'keyhole', 'label' => 'Keyhole neck', 'price' => 120]]],
        'sleeve_style' => ['label' => 'Sleeve style', 'required' => true, 'choices' => [['value' => 'standard', 'label' => 'Standard sleeve', 'price' => 0], ['value' => 'puff', 'label' => 'Puff sleeve', 'price' => 100], ['value' => 'off-shoulder', 'label' => 'Off-shoulder', 'price' => 150], ['value' => 'cap', 'label' => 'Cap sleeve', 'price' => 50]]],
        'sleeve_length' => ['label' => 'Sleeve length', 'required' => true, 'choices' => [['value' => 'short', 'label' => 'Short', 'price' => 0], ['value' => 'elbow', 'label' => 'Elbow length', 'price' => 40], ['value' => 'three-quarter', 'label' => 'Three-quarter', 'price' => 75], ['value' => 'full', 'label' => 'Full sleeve', 'price' => 120]]],
        'lining' => ['label' => 'Lining', 'required' => false, 'choices' => [['value' => 'no', 'label' => 'No lining', 'price' => 0], ['value' => 'yes', 'label' => 'Add lining', 'price' => 120]]],
        'cups' => ['label' => 'Cups', 'required' => false, 'choices' => [['value' => 'no', 'label' => 'No cups', 'price' => 0], ['value' => 'yes', 'label' => 'Add cups', 'price' => 180]]],
        'piping' => ['label' => 'Piping', 'required' => false, 'choices' => [['value' => 'none', 'label' => 'No piping', 'price' => 0], ['value' => 'contrast', 'label' => 'Contrast piping', 'price' => 80], ['value' => 'matching', 'label' => 'Matching piping', 'price' => 60]]],
        'back_design' => ['label' => 'Back design', 'required' => false, 'choices' => [['value' => 'style-default', 'label' => 'Style default', 'price' => 0], ['value' => 'tie-up', 'label' => 'Tie-up back', 'price' => 100], ['value' => 'buttoned', 'label' => 'Buttoned back', 'price' => 120], ['value' => 'deep-back', 'label' => 'Deep back', 'price' => 150]]],
        'finishing' => ['label' => 'Finishing', 'required' => false, 'choices' => [['value' => 'standard', 'label' => 'Standard finish', 'price' => 0], ['value' => 'premium', 'label' => 'Premium finish', 'price' => 150]]],
        'embroidery' => ['label' => 'Embroidery / work', 'required' => false, 'choices' => [['value' => 'none', 'label' => 'No additional work', 'price' => 0], ['value' => 'machine', 'label' => 'Machine work', 'price' => 250], ['value' => 'hand', 'label' => 'Hand work', 'price' => 500]]],
    ];
}

function demo_data_fallback_demo_choice(string $field, string $value): ?array
{
    $options = demo_data_fallback_blouse_customization_options();
    if (!isset($options[$field])) {
        return null;
    }
    foreach ($options[$field]['choices'] as $choice) {
        if ($choice['value'] === $value) {
            return $choice;
        }
    }
    return null;
}

function demo_data_fallback_luxe_machine_work_designs(): array
{
    return [
        ['code' => 'M-024', 'name' => 'Bridal Motif', 'price' => 350, 'image' => 'assets/images/luxe-4.jpg', 'description' => 'Fine metallic thread motif, ideal for festive necklines and back.'],
        ['code' => 'M-018', 'name' => 'Paisley Border', 'price' => 250, 'image' => 'assets/images/luxe-5.jpg', 'description' => 'Classic paisley border detailing along sleeves and neck.'],
        ['code' => 'M-031', 'name' => 'Floral Jal', 'price' => 400, 'image' => 'assets/images/luxe-6.jpg', 'description' => 'Intricate all-over floral latticework stitched with precision.'],
        ['code' => 'M-045', 'name' => 'Geometrical Zari', 'price' => 300, 'image' => 'assets/images/blouse-work.jpg', 'description' => 'Modern structured linear motifs crafted with fine zari thread.'],
    ];
}

function demo_data_fallback_luxe_hand_work_designs(): array
{
    return [
        ['code' => 'H-012', 'name' => 'Zari Floral', 'price' => 800, 'image' => 'assets/images/luxe-1.jpg', 'description' => 'Handcrafted traditional zari embroidery with antique gold tones.'],
        ['code' => 'H-007', 'name' => 'Heavy Maggam / Aari Work', 'price' => 1200, 'image' => 'assets/images/luxe-2.jpg', 'description' => 'Artisan needlework with rich beads, stones, and metallic wire.'],
        ['code' => 'H-023', 'name' => 'Pearl & Zardosi', 'price' => 950, 'image' => 'assets/images/luxe-3.jpg', 'description' => 'Intricate zardosi coils complemented by subtle pearl embellishments.'],
        ['code' => 'H-034', 'name' => 'Thread & Mirror Work', 'price' => 650, 'image' => 'assets/images/design 2.jpg', 'description' => 'Vibrant hand-stitched silk threads framing reflective mini-mirrors.'],
    ];
}

function demo_data_fallback_luxe_work_placements(): array
{
    return ['Neck', 'Sleeve', 'Back', 'All Over', 'Custom'];
}
