<?php
/**
 * Shagun Ladies Tailor — Admin Modular Navigation Architecture
 * 
 * Module Registry & Top-Level Module Switcher
 * Defines active module ('Orders & Fulfillment') and provides extensible
 * architectural slots for future administrative modules without bloating
 * the order processing core.
 */

declare(strict_types=1);

// Active module identifier
$currentModule = trim((string)($_GET['module'] ?? 'orders'));
if ($currentModule === '') {
    $currentModule = 'orders';
}

// Admin Module Registry
$adminModules = [
    'orders' => [
        'id' => 'orders',
        'label' => 'Orders & Fulfillment',
        'icon' => '✂',
        'status' => 'active',
        'badge' => null,
        'url' => 'index.php?module=orders',
        'description' => 'Workshop order lifecycle, measurements, stitching, and dossier dispatch'
    ],
    'offers' => [
        'id' => 'offers',
        'label' => 'Festive Offers & Banners',
        'icon' => '🎉',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Seasonal promotions, hero banners, and discount rules'
    ],
    'campaigns' => [
        'id' => 'campaigns',
        'label' => 'Promotional Campaigns',
        'icon' => '📢',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'SMS & WhatsApp marketing campaigns for festivals and bridal seasons'
    ],
    'pricing' => [
        'id' => 'pricing',
        'label' => 'Price Management',
        'icon' => '🏷',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Base stitching charges, add-on rates, and luxury fabric surcharges'
    ],
    'cms' => [
        'id' => 'cms',
        'label' => 'Website CMS',
        'icon' => '🌐',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Atelier story, testimonials, lookbook gallery, and contact information'
    ],
    'customers' => [
        'id' => 'customers',
        'label' => 'Customer Management',
        'icon' => '👥',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Customer registry, order frequency, loyalty history, and contact directory'
    ],
    'measurements' => [
        'id' => 'measurements',
        'label' => 'Measurements & Profiles',
        'icon' => '📏',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Saved customer measurement profiles, blouse cuts, and fitting preferences'
    ],
    'materials' => [
        'id' => 'materials',
        'label' => 'Materials & Fabrics',
        'icon' => '🧵',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Fabric inventory, lining materials, laces, and embellishment stock'
    ],
    'payments' => [
        'id' => 'payments',
        'label' => 'Payments & Invoices',
        'icon' => '💳',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Advance settlements, balance collections, GST invoices, and receipts'
    ],
    'reports' => [
        'id' => 'reports',
        'label' => 'Analytics & Reports',
        'icon' => '📊',
        'status' => 'coming_soon',
        'badge' => 'Coming Soon',
        'url' => '#',
        'description' => 'Workshop turnaround times, revenue breakdown, and demand forecasting'
    ]
];
?>

<!-- MODULAR ADMIN NAVIGATION BAR -->
<nav class="admin-modules-nav" aria-label="Admin Modules Navigation">
    <div class="admin-modules-scroll">
        <?php foreach ($adminModules as $modKey => $mod): ?>
            <?php
            $isActive = ($modKey === $currentModule) && ($mod['status'] === 'active');
            $isDisabled = ($mod['status'] === 'coming_soon');
            ?>
            <?php if ($isDisabled): ?>
                <div class="admin-module-tab is-disabled" 
                     role="link" 
                     aria-disabled="true" 
                     title="<?php echo htmlspecialchars($mod['description']); ?>">
                    <span class="module-tab-icon" aria-hidden="true"><?php echo $mod['icon']; ?></span>
                    <span class="module-tab-label"><?php echo htmlspecialchars($mod['label']); ?></span>
                    <?php if (!empty($mod['badge'])): ?>
                        <span class="module-tab-badge"><?php echo htmlspecialchars($mod['badge']); ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <a href="<?php echo htmlspecialchars($mod['url']); ?>" 
                   class="admin-module-tab <?php echo $isActive ? 'is-active' : ''; ?>"
                   <?php echo $isActive ? 'aria-current="page"' : ''; ?>
                   title="<?php echo htmlspecialchars($mod['description']); ?>">
                    <span class="module-tab-icon" aria-hidden="true"><?php echo $mod['icon']; ?></span>
                    <span class="module-tab-label"><?php echo htmlspecialchars($mod['label']); ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>
