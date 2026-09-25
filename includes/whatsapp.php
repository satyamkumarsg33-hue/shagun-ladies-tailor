<?php
/**
 * Shagun Ladies Tailor — WhatsApp Sharing Integration Helper
 * 
 * Provides click-to-chat URL generation for order completion updates with:
 * - Indian phone number normalization (e.g. +91 98765 43210 -> 919876543210)
 * - Truthful messaging: never claims files are attached or sent automatically
 * - Localhost vs Public URL detection: never exposes unreachable localhost URLs to customers
 * - Clear notices explaining click-to-chat vs Business Cloud API
 */

declare(strict_types=1);

/**
 * Format Indian phone number for WhatsApp API / wa.me link.
 * 
 * @param string $phone Raw phone input
 * @return string|null Formatted digits with country code (e.g. 919876543210) or null if invalid
 */
function format_whatsapp_phone(string $phone): ?string {
    $clean = preg_replace('/[^\d]/', '', $phone);
    if (empty($clean)) {
        return null;
    }

    // 10 digits starting with 6, 7, 8, or 9 -> prefix India country code 91
    if (strlen($clean) === 10 && in_array($clean[0], ['6', '7', '8', '9'], true)) {
        return '91' . $clean;
    }

    // 11 digits starting with 0 -> strip 0 and prefix 91
    if (strlen($clean) === 11 && str_starts_with($clean, '0')) {
        return '91' . substr($clean, 1);
    }

    // 12 digits starting with 91 -> valid Indian number with country code
    if (strlen($clean) === 12 && str_starts_with($clean, '91')) {
        return $clean;
    }

    // Fallback for valid international numbers (minimum 10 digits)
    return strlen($clean) >= 10 ? $clean : null;
}

/**
 * Check if the application is running with a genuine public URL (not localhost or 127.0.0.1).
 * 
 * @return bool True if a public website domain is configured
 */
function is_public_url_configured(): bool {
    $config = require __DIR__ . '/config.php';
    $appUrl = (string)($config['app']['url'] ?? '');
    $env = (string)($config['app']['env'] ?? 'local');

    if ($env === 'local') {
        return false;
    }

    if (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
        return false;
    }

    return !empty($appUrl);
}

/**
 * Build WhatsApp click-to-chat payload for an order.
 * 
 * @param array $orderData Order details, customer name, phone, financials
 * @param string|null $shareUrl Optional secure public share URL to include in the message
 * @return array [
 *   'can_share_url' => bool,
 *   'whatsapp_url' => string,
 *   'formatted_phone' => ?string,
 *   'message_text' => string,
 *   'notice' => string
 * ]
 */
function build_whatsapp_completion_update(array $orderData, ?string $shareUrl = null): array {
    $custPhone = trim((string)($orderData['customer_phone'] ?? ''));
    $custName = trim((string)($orderData['customer_name'] ?? 'Customer'));
    if ($custName === '' || $custName === 'Customer name not provided') {
        $custName = 'Valued Customer';
    }
    $orderRef = trim((string)($orderData['order_ref'] ?? ''));
    $formattedPhone = format_whatsapp_phone($custPhone);

    $isPublic = is_public_url_configured();

    if ($isPublic) {
        if (!empty($shareUrl)) {
            $message = "Hello {$custName},\n\nYour bespoke tailoring order {$orderRef} has been completed by Shagun Ladies Tailor!\n\nYour official Completion Dossier is available here:\n{$shareUrl}\n\nThank you for choosing Shagun Ladies Tailor.";
            $notice = "A prefilled WhatsApp message with the secure Completion Dossier share link will open in WhatsApp Web / Desktop.";
        } else {
            $totalAmt = (int)($orderData['total_amount'] ?? ($orderData['grand_total'] ?? 0));
            $balanceAmt = (int)($orderData['remaining_balance'] ?? ($orderData['balance_amount'] ?? 0));
            $balanceText = $balanceAmt > 0 ? "Rs. " . number_format($balanceAmt) : "Cleared (Rs. 0)";

            $message = "Hello {$custName},\n\nYour bespoke tailoring order {$orderRef} has been completed by Shagun Ladies Tailor!\n\nOrder Ref: {$orderRef}\nStatus: Completed\nTotal: Rs. " . number_format($totalAmt) . "\nBalance: {$balanceText}\n\nThank you for choosing Shagun Ladies Tailor.";
            $notice = "WhatsApp sharing creates a pre-filled completion update message via WhatsApp click-to-chat. Generate a share link in the admin dashboard to include a direct document link.";
        }
    } else {
        $totalAmt = (int)($orderData['total_amount'] ?? ($orderData['grand_total'] ?? 0));
        $balanceAmt = (int)($orderData['remaining_balance'] ?? ($orderData['balance_amount'] ?? 0));
        $balanceText = $balanceAmt > 0 ? "Rs. " . number_format($balanceAmt) : "Cleared (Rs. 0)";

        $message = "Hello {$custName},\n\nYour bespoke tailoring order {$orderRef} is completed and ready at Shagun Ladies Tailor!\n\nOrder Ref: {$orderRef}\nStatus: Completed\nTotal: Rs. " . number_format($totalAmt) . "\nBalance: {$balanceText}\n\nThank you for choosing Shagun Ladies Tailor.";
        $notice = "WhatsApp sharing creates a pre-filled completion update message via WhatsApp click-to-chat. It does not attach files or send automated messages without a configured WhatsApp Business Cloud API.";
    }

    $waUrl = '';
    if ($formattedPhone) {
        $waUrl = 'https://api.whatsapp.com/send?phone=' . urlencode($formattedPhone) . '&text=' . rawurlencode($message);
    } else {
        $waUrl = 'https://api.whatsapp.com/send?text=' . rawurlencode($message);
    }

    return [
        'can_share_url' => $isPublic,
        'whatsapp_url' => $waUrl,
        'formatted_phone' => $formattedPhone,
        'message_text' => $message,
        'notice' => $notice
    ];
}

/**
 * Convenience helper to get the click-to-chat URL directly.
 *
 * @param array $orderData
 * @param string|null $shareUrl Optional secure share URL
 * @return string WhatsApp URL or empty string if phone is missing/invalid
 */
function format_whatsapp_click_to_chat_url(array $orderData, ?string $shareUrl = null): string {
    $payload = build_whatsapp_completion_update($orderData, $shareUrl);
    return $payload['formatted_phone'] ? $payload['whatsapp_url'] : '';
}
