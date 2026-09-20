<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/unified-cart.php';

demo_cart_bootstrap();

if (isset($_GET['remove'])) {
    demo_cart_remove((string) $_GET['remove']);
    header('Location: cart.php');
    exit;
}

if (isset($_GET['clear'])) {
    demo_cart_clear();
    header('Location: cart.php');
    exit;
}

$expired = demo_cart_is_expired();
$basket = get_unified_basket();
$hasStandard = $basket['has_standard'];
$hasLuxe = $basket['has_luxe'];
$isCombined = $basket['is_combined'];
$standardItems = $basket['standard_items'];
$luxeItems = $basket['luxe_items'];
$allItems = $basket['all_items'];
$totalGarments = $basket['total_physical_garment_count'] ?? count($allItems);
$grandTotal = $basket['grand_total'];
$hasIncompleteLuxe = $basket['has_incomplete_luxe'] ?? false;
$isEmpty = !$hasStandard && !$hasLuxe;

$luxeEntryUrl = 'luxe-stitching.php';

include __DIR__ . '/includes/header.php';
?>
<main class="cart-page">
    <section class="cart-intro">
        <a href="standard-stitching.php" class="demo-back">← Continue browsing</a>
        <p class="demo-eyebrow">Your tailoring order</p>
        <h1>Your Shagun Order</h1>
        <p>Every garment below is tailored independently, with its own style and choices.</p>
    </section>

    <?php if ($expired): ?>
        <div class="demo-message is-warning">
            <strong>Your guest cart has expired.</strong>
            <span>Start a fresh order whenever you are ready.</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['added'])): ?>
        <div class="demo-message">
            <strong>Your garment is in the order.</strong>
            <span>Add another blouse or review the individual details below.</span>
        </div>
    <?php endif; ?>

    <?php if ($isEmpty): ?>
        <section class="empty-order">
            <p class="demo-eyebrow">No garments yet</p>
            <h2>Your order is waiting for its first piece.</h2>
            <p>Choose a blouse style and make it yours.</p>
            <div class="empty-order-actions">
                <a class="demo-primary-action" href="blouse-styles.php">Choose a Blouse Style</a>
                <a class="demo-secondary-action luxe-entry-btn" href="<?php echo htmlspecialchars($luxeEntryUrl); ?>">Explore Luxe Stitching →</a>
            </div>
            <p class="luxe-entry-note">Ordering for a wedding or family event? Design coordinated outfits with dedicated fitting references.</p>
        </section>
    <?php else: ?>
        <div class="cart-layout">
            <section class="cart-items">
                <?php if ($hasStandard): ?>
                    <?php if ($isCombined): ?>
                        <div class="cart-section-divider" style="margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #f0e8de; display: flex; align-items: center; gap: 10px;">
                            <span class="shagun-category-badge badge-standard">STANDARD STITCHING</span>
                            <h3 style="margin: 0; font-size: 17px; color: #57141f; font-family: 'Playfair Display', serif;">Standard Tailoring Garments</h3>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($standardItems as $item): ?>
                        <article class="cart-item">
                            <img src="<?php echo htmlspecialchars((string) ($item['image'] ?? 'assets/images/blouse.jpg')); ?>" alt="<?php echo htmlspecialchars((string) ($item['style_name'] ?? 'Blouse')); ?>">
                            <div class="cart-item-main">
                                <div class="cart-item-badge-row">
                                    <?php foreach ($item['badges'] as $b): ?>
                                        <?php echo render_category_badge($b); ?>
                                    <?php endforeach; ?>
                                </div>
                                <p class="demo-eyebrow">Garment <?php echo $item['item_number']; ?> · <?php echo htmlspecialchars((string) ($item['person_name'] ?? 'Self')); ?></p>
                                <h2><?php echo htmlspecialchars((string) ($item['style_name'] ?? 'Custom Tailored')); ?></h2>
                                <ul>
                                    <?php foreach (array_slice($item['customization_choices'] ?? [], 0, 5) as $choice): ?>
                                        <li>
                                            <span><?php echo htmlspecialchars((string) ($choice['field'] ?? 'Option')); ?></span>
                                            <strong><?php echo htmlspecialchars((string) ($choice['label'] ?? '')); ?><?php if (!empty($choice['price'])): ?> (+₹<?php echo number_format($choice['price']); ?>)<?php endif; ?></strong>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (!empty($item['machine_work'])): ?>
                                        <li>
                                            <span>Machine Work</span>
                                            <strong><?php echo htmlspecialchars($item['machine_work']['placement'] ?? 'Neck & Sleeves'); ?> (+₹<?php echo number_format($item['machine_work']['price'] ?? 250); ?>)</strong>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (!empty($item['hand_work'])): ?>
                                        <li>
                                            <span>Hand Work</span>
                                            <strong><?php echo htmlspecialchars($item['hand_work']['placement'] ?? 'Neck & Sleeves'); ?> (+₹<?php echo number_format($item['hand_work']['price'] ?? 500); ?>)</strong>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                                <?php if (!empty($item['notes'])): ?>
                                    <p class="cart-note">Note: <?php echo htmlspecialchars((string) $item['notes']); ?></p>
                                <?php endif; ?>
                                <div class="cart-item-actions">
                                    <a href="<?php echo htmlspecialchars($item['edit_url']); ?>">Edit</a>
                                    <a class="remove-link" href="<?php echo htmlspecialchars($item['remove_url']); ?>" onclick="return confirm('Remove this garment from your order?');">Remove</a>
                                </div>
                            </div>
                            <strong class="cart-item-price">₹<?php echo number_format((int) ($item['total_price'] ?? 0)); ?></strong>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($hasLuxe): ?>
                    <?php if ($isCombined): ?>
                        <div class="cart-section-divider" style="margin-top: 28px; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #f0e8de; display: flex; align-items: center; gap: 10px;">
                            <span class="shagun-category-badge badge-luxe">LUXE STITCHING</span>
                            <h3 style="margin: 0; font-size: 17px; color: #57141f; font-family: 'Playfair Display', serif;">Luxe Workspace Garments</h3>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($luxeItems as $item): ?>
                        <article class="cart-item">
                            <img src="<?php echo htmlspecialchars((string) ($item['image'] ?? 'assets/images/blouse.jpg')); ?>" alt="<?php echo htmlspecialchars((string) ($item['style_name'] ?? 'Blouse')); ?>">
                            <div class="cart-item-main">
                                <div class="cart-item-badge-row" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    <?php foreach ($item['badges'] as $b): ?>
                                        <?php echo render_category_badge($b); ?>
                                    <?php endforeach; ?>
                                    <?php if ($item['status'] === 'completed' || !empty($item['is_completed'])): ?>
                                        <span class="luxe-status-pill status-completed" style="background: #eaf5ea; color: #1e7033; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px;">✓ Completed</span>
                                    <?php elseif ($item['status'] === 'in-progress'): ?>
                                        <span class="luxe-status-pill status-in-progress" style="background: #fef3e2; color: #a87020; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px;">⏳ In Progress</span>
                                    <?php else: ?>
                                        <span class="luxe-status-pill status-not-started" style="background: #f5f0eb; color: #73695e; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px;">○ Not Started</span>
                                    <?php endif; ?>
                                </div>
                                <p class="demo-eyebrow"><?php echo htmlspecialchars($item['person_name']); ?><?php if (!empty($item['person_role'])): ?> (<?php echo htmlspecialchars($item['person_role']); ?>)<?php endif; ?> · <?php echo htmlspecialchars($item['item_label']); ?></p>
                                <h2><?php echo htmlspecialchars((string) ($item['style_name'] ?? 'Luxe Garment')); ?></h2>
                                <ul>
                                    <?php if (!empty($item['customization_choices'])): ?>
                                        <?php foreach (array_slice($item['customization_choices'], 0, 5) as $choice): ?>
                                            <li>
                                                <span><?php echo htmlspecialchars((string) ($choice['field'] ?? 'Option')); ?></span>
                                                <strong><?php echo htmlspecialchars((string) ($choice['label'] ?? '')); ?><?php if (!empty($choice['price'])): ?> (+₹<?php echo number_format($choice['price']); ?>)<?php endif; ?></strong>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if ($item['work_type'] === 'machine' || $item['work_type'] === 'both'): ?>
                                        <li>
                                            <span>Machine Work</span>
                                            <strong>
                                                <?php echo htmlspecialchars($item['machine_work']['placement'] ?? 'Neck & Sleeves'); ?>
                                                <?php if (!empty($item['machine_work']['price'])): ?> (+₹<?php echo number_format($item['machine_work']['price']); ?>)<?php endif; ?>
                                                <?php if ($item['machine_work_target'] === 'separate_cloth' || $item['machine_work_target'] === 'separate_material'): ?>
                                                    <em>(On Separate Cloth<?php if (!empty($item['machine_material_description'])): ?>: <?php echo htmlspecialchars($item['machine_material_description']); ?><?php endif; ?>)</em>
                                                <?php else: ?>
                                                    <em>(On Garment)</em>
                                                <?php endif; ?>
                                            </strong>
                                        </li>
                                    <?php endif; ?>

                                    <?php if ($item['work_type'] === 'hand' || $item['work_type'] === 'both'): ?>
                                        <li>
                                            <span>Hand Work</span>
                                            <strong>
                                                <?php echo htmlspecialchars($item['hand_work']['placement'] ?? 'Neck & Sleeves'); ?>
                                                <?php if (!empty($item['hand_work']['price'])): ?> (+₹<?php echo number_format($item['hand_work']['price']); ?>)<?php endif; ?>
                                                <?php if ($item['hand_work_target'] === 'separate_cloth' || $item['hand_work_target'] === 'separate_material'): ?>
                                                    <em>(On Separate Cloth<?php if (!empty($item['hand_material_description'])): ?>: <?php echo htmlspecialchars($item['hand_material_description']); ?><?php endif; ?>)</em>
                                                <?php else: ?>
                                                    <em>(On Garment)</em>
                                                <?php endif; ?>
                                            </strong>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (empty($item['customization_choices']) && empty($item['work_type'])): ?>
                                        <li><span>Configuration</span><strong>Pending choices in workspace</strong></li>
                                    <?php endif; ?>
                                </ul>
                                <?php if (!empty($item['notes'])): ?>
                                    <p class="cart-note">Note: <?php echo htmlspecialchars((string) $item['notes']); ?></p>
                                <?php endif; ?>
                                <div class="cart-item-actions">
                                    <?php if ($item['status'] === 'completed' || !empty($item['is_completed'])): ?>
                                        <a href="<?php echo htmlspecialchars($item['edit_url']); ?>">View / Edit</a>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars($item['edit_url']); ?>" style="font-weight: 700; color: #6b1d28;">Continue Customizing →</a>
                                    <?php endif; ?>
                                    <a href="luxe-workspace.php" style="color: #73695e; font-size: 13px;">Workspace</a>
                                </div>
                            </div>
                            <strong class="cart-item-price">₹<?php echo number_format((int) ($item['total_price'] ?? 0)); ?></strong>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Dedicated Luxe Exploration Banner in Cart -->
                <section class="luxe-cart-banner">
                    <div class="luxe-cart-banner-content">
                        <span class="shagun-category-badge badge-luxe">LUXE STITCHING</span>
                        <h3>Want custom bridal/wedding wear with multiple garments and people? Explore Luxe Stitching</h3>
                        <p>Multiple family members, intricate hand/machine embroidery, dedicated wedding workspace, and 30% advance booking.</p>
                    </div>
                    <a href="<?php echo htmlspecialchars($luxeEntryUrl); ?>" class="luxe-cart-banner-btn">Explore Luxe Stitching →</a>
                </section>
            </section>

            <aside class="cart-summary">
                <p class="demo-eyebrow">Order total</p>
                <h2>Ready when you are</h2>
                <p><span>Total Physical Garments</span><strong><?php echo $totalGarments; ?></strong></p>
                <?php if ($hasStandard): ?>
                    <p><span>Standard Items</span><strong><?php echo count($standardItems); ?></strong></p>
                <?php endif; ?>
                <?php if ($hasLuxe): ?>
                    <p><span>Luxe Garments</span><strong><?php echo count($luxeItems); ?></strong></p>
                <?php endif; ?>
                <p><span>Subtotal</span><strong>₹<?php echo number_format($grandTotal); ?></strong></p>
                <div><span>Total</span><strong>₹<?php echo number_format($grandTotal); ?></strong></div>

                <?php if ($hasIncompleteLuxe): ?>
                    <div class="demo-message is-warning" style="margin: 14px 0; font-size: 12.5px; text-align: left;">
                        <strong>Luxe Garments In Progress</strong>
                        <span>Complete your Luxe garments before continuing to the final review and payment.</span>
                    </div>
                    <a class="demo-primary-action" href="luxe-workspace.php">Continue Luxe Workspace</a>
                    <a class="demo-secondary-link" href="blouse-styles.php" style="margin-top: 10px;">+ Add Another Blouse</a>
                    <?php if ($hasStandard): ?>
                        <a class="demo-secondary-action" href="checkout.php" style="margin-top: 8px;">Checkout Standard Only</a>
                    <?php endif; ?>
                <?php elseif ($isCombined): ?>
                    <a class="demo-primary-action" href="review-payment.php">Proceed to Combined Review →</a>
                    <a class="demo-secondary-action" href="checkout.php" style="margin-top: 8px;">Checkout Standard Only</a>
                <?php elseif ($hasLuxe): ?>
                    <a class="demo-primary-action" href="review-payment.php">Proceed to Luxe Review →</a>
                <?php else: ?>
                    <a class="demo-primary-action" href="checkout.php">Proceed to Place Order</a>
                <?php endif; ?>

                <a class="demo-secondary-link" href="blouse-styles.php">+ Add Another Blouse</a>
                <div class="cart-luxe-cta-divider"></div>
                <a class="demo-secondary-action luxe-entry-btn" href="<?php echo htmlspecialchars($luxeEntryUrl); ?>">Explore Luxe Stitching</a>
                <p class="luxe-entry-note">Want custom bridal/wedding wear with multiple garments and people? Explore Luxe Stitching (Multiple family members, intricate hand/machine embroidery, dedicated wedding workspace, and 30% advance booking).</p>
                <?php if ($hasStandard): ?>
                    <a class="demo-clear-link" href="cart.php?clear=1" onclick="return confirm('Clear every garment from this demo order?');">Clear Order</a>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
