<?php
/**
 * Shagun Ladies Tailor — Order Status Metric Summary Cards
 * 
 * Interactive status navigation and filtering buttons for the 4 canonical
 * order lifecycle stages + Total Orders.
 * 
 * Each button is keyboard-accessible (Enter, Space, Tab) and provides
 * ARIA attributes and data-status-target values for instant client-side
 * filtering and URL synchronization.
 */

declare(strict_types=1);

/**
 * @var int $countTotal
 * @var int $countAwaiting
 * @var int $countInProcess
 * @var int $countCompleted
 * @var int $countDelivered
 */
$countTotal = (int)($countTotal ?? 0);
$countAwaiting = (int)($countAwaiting ?? 0);
$countInProcess = (int)($countInProcess ?? 0);
$countCompleted = (int)($countCompleted ?? 0);
$countDelivered = (int)($countDelivered ?? 0);
?>
<div class="admin-metrics-grid" role="region" aria-label="Order status summary and filters">
    <button
        type="button"
        class="metric-box metric-card-interactive is-active"
        data-status-target="all"
        aria-label="View all orders (<?php echo $countTotal; ?> total)"
        aria-pressed="true">
        <div class="metric-box-num"><?php echo $countTotal; ?></div>
        <div class="metric-box-label">Total Orders</div>
    </button>
    <button
        type="button"
        class="metric-box metric-card-interactive is-awaiting"
        data-status-target="awaiting_confirmation"
        aria-label="View awaiting confirmation orders (<?php echo $countAwaiting; ?> orders)"
        aria-pressed="false">
        <div class="metric-box-num"><?php echo $countAwaiting; ?></div>
        <div class="metric-box-label">Awaiting Confirmation</div>
    </button>
    <button
        type="button"
        class="metric-box metric-card-interactive is-in-process"
        data-status-target="stitching_in_process"
        aria-label="View stitching in process orders (<?php echo $countInProcess; ?> orders)"
        aria-pressed="false">
        <div class="metric-box-num"><?php echo $countInProcess; ?></div>
        <div class="metric-box-label">Stitching in Process</div>
    </button>
    <button
        type="button"
        class="metric-box metric-card-interactive is-completed"
        data-status-target="completed"
        aria-label="View completed orders (<?php echo $countCompleted; ?> orders)"
        aria-pressed="false">
        <div class="metric-box-num"><?php echo $countCompleted; ?></div>
        <div class="metric-box-label">Completed</div>
    </button>
    <button
        type="button"
        class="metric-box metric-card-interactive is-delivered"
        data-status-target="delivered"
        aria-label="View delivered orders and customer history (<?php echo $countDelivered; ?> orders)"
        aria-pressed="false">
        <div class="metric-box-num"><?php echo $countDelivered; ?></div>
        <div class="metric-box-label">Delivered / History</div>
    </button>
</div>
