<?php
/**
 * Shagun Ladies Tailor — Order Section Component Architecture
 * 
 * Defines standard rendering contract for canonical order lifecycle sections:
 *  - section-awaiting-confirmation
 *  - section-stitching-in-process
 *  - section-completed
 *  - section-delivered
 * 
 * Empty State Requirement:
 *  When no orders exist in a given section, display exactly:
 *  "No orders currently in this stage."
 */

declare(strict_types=1);

/**
 * Renders an empty state message for an order section.
 */
function render_order_section_empty_state(): string {
    return '<div class="section-empty">No orders currently in this stage.</div>';
}
