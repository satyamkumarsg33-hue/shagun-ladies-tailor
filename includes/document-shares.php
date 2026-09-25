<?php
/**
 * Shagun Ladies Tailor — Document Share Token Management
 * 
 * Provides cryptographically secure, expiring, hash-validated share links
 * for Completion Dossier PDFs:
 * - 64-hex character random bearer tokens (random_bytes(32))
 * - Only SHA-256 hashes are persisted in the database (never plaintext tokens)
 * - Expiration window (default 30 days, configurable via SHARE_TOKEN_LIFETIME_DAYS)
 * - Revocation support (single share or all order shares)
 * - Gating: order must be 'completed' or 'delivered' and have >= 1 completed photo
 * - Access auditing: tracks access_count and last_accessed_at
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/order-status.php';

/**
 * Generate a cryptographically secure random token (64 hex characters).
 *
 * @return string
 */
function generate_share_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Compute SHA-256 hash of a raw token.
 *
 * @param string $token
 * @return string 64-character lowercase hex hash
 */
function hash_share_token(string $token): string {
    return hash('sha256', trim($token));
}

/**
 * Build the full public share URL for a raw token.
 *
 * @param string $rawToken
 * @return string
 */
function get_document_share_url(string $rawToken): string {
    $config = require __DIR__ . '/config.php';
    $appUrl = rtrim((string)($config['app']['url'] ?? 'http://localhost/shagun-ladies-tailor'), '/');
    return $appUrl . '/view-completion-dossier.php?token=' . urlencode($rawToken);
}

/**
 * Retrieve the active (non-revoked, non-expired) share record for an order, if one exists.
 * Note: Only metadata and token_hash are stored in DB.
 *
 * @param PDO $pdo
 * @param int $orderId
 * @return array|null
 */
function get_active_order_document_share(PDO $pdo, int $orderId): ?array {
    $stmt = $pdo->prepare("
        SELECT ods.*, od.file_path, od.file_name, od.version
        FROM order_document_shares ods
        INNER JOIN order_documents od ON od.id = ods.document_id
        WHERE ods.order_id = :oid 
          AND ods.is_revoked = 0 
          AND ods.expires_at > NOW()
        ORDER BY ods.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([':oid' => $orderId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Create a new secure expiring share link for an order's Completion Dossier.
 * Revokes any previous active shares for this order.
 * 
 * @param PDO $pdo
 * @param int $orderId
 * @param int|null $adminId Admin user ID who generated the link
 * @param int|null $lifetimeDays Expiration lifetime in days (defaults to config)
 * @return array [
 *   'id' => int,
 *   'token' => string (plaintext, 64-hex, available only at creation),
 *   'token_hash' => string,
 *   'order_id' => int,
 *   'document_id' => int,
 *   'expires_at' => string,
 *   'share_url' => string
 * ]
 * @throws RuntimeException If order does not qualify for completion dossier
 */
function create_order_document_share(PDO $pdo, int $orderId, ?int $adminId = null, ?int $lifetimeDays = null): array {
    // 1. Fetch order details
    $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :oid LIMIT 1");
    $ordStmt->execute([':oid' => $orderId]);
    $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new InvalidArgumentException("Order ID {$orderId} not found.");
    }

    // 2. Status gating: Must be completed or delivered
    $canonicalStatus = get_canonical_status((string)$order['status']);
    if ($canonicalStatus !== 'completed' && $canonicalStatus !== 'delivered') {
        throw new DomainException("Completion Dossier share link is only available for Completed or Delivered orders. Current status: {$canonicalStatus}");
    }

    // 3. Photo gating: Must have at least 1 completed photo
    $photoStmt = $pdo->prepare("SELECT COUNT(*) FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
    $photoStmt->execute([':oid' => $orderId]);
    $photoCount = (int)$photoStmt->fetchColumn();
    if ($photoCount < 1) {
        throw new DomainException("Upload at least one completed garment photo before creating a Completion Dossier share link.");
    }

    // 4. Ensure an order_documents completion_dossier record exists
    $docStmt = $pdo->prepare("
        SELECT id, file_path FROM order_documents 
        WHERE order_id = :oid AND document_type = 'completion_dossier' 
        ORDER BY version DESC LIMIT 1
    ");
    $docStmt->execute([':oid' => $orderId]);
    $existingDoc = $docStmt->fetch(PDO::FETCH_ASSOC);
    $documentId = 0;

    if ($existingDoc && !empty($existingDoc['id'])) {
        $documentId = (int)$existingDoc['id'];
        // Check if physical file exists; if missing, re-generate
        $root = dirname(__DIR__);
        $fpath = (string)$existingDoc['file_path'];
        $absPath = str_starts_with($fpath, '/') || (strlen($fpath) > 1 && $fpath[1] === ':') ? $fpath : $root . '/' . ltrim($fpath, '/\\');
        if (!file_exists($absPath)) {
            $documentId = 0; // force re-generation
        }
    }

    if ($documentId === 0) {
        require_once __DIR__ . '/completion-dossier-pdf.php';
        // Build full order data for PDF generator
        $orderRef = (string)$order['order_ref'];
        $userId = (int)$order['user_id'];
        $orderData = get_customer_order_by_ref($orderRef, $userId);
        if (!$orderData) {
            $orderData = $order;
        }
        $orderData['id'] = $orderId;
        $orderData['canonical_status'] = $canonicalStatus;

        // Fetch completed photos
        $pStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed' ORDER BY created_at ASC");
        $pStmt->execute([':oid' => $orderId]);
        $orderData['completed_photos'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch intake photos
        $inStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'awaiting_confirmation' ORDER BY created_at ASC LIMIT 3");
        $inStmt->execute([':oid' => $orderId]);
        $orderData['intake_photos'] = $inStmt->fetchAll(PDO::FETCH_ASSOC);

        $custProfile = $userId > 0 ? get_customer_profile($userId) : null;
        if ($custProfile) {
            $orderData['customer_name'] = $custProfile['name'];
            $orderData['customer_phone'] = $custProfile['phone'];
            $orderData['customer_email'] = $custProfile['email'];
            $orderData['customer_address'] = $custProfile['address'];
            $orderData['phone_display'] = $custProfile['phone_display'];
            $orderData['address_display'] = $custProfile['address_display'];
        }

        // Fetch customer sequence
        $seqMap = get_customer_order_sequence_map($pdo);
        $seq = $seqMap['by_order_id'][$orderId] ?? 1;
        $orderData['customer_sequence'] = $seq;
        $orderData['customer_sequence_label'] = get_customer_sequence_label($seq);

        // Fetch financials
        $payStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = :oid AND status = 'completed'");
        $payStmt->execute([':oid' => $orderId]);
        $completedPaySum = (float)$payStmt->fetchColumn();
        $orderData['total_amount'] = (float)$order['total_amount'];
        $orderData['amount_paid'] = $completedPaySum > 0 ? $completedPaySum : (float)$order['advance_amount'];
        $orderData['remaining_balance'] = max(0.0, (float)$order['total_amount'] - $orderData['amount_paid']);
        $orderData['payment_status'] = ($orderData['amount_paid'] >= $orderData['total_amount'] && $orderData['total_amount'] > 0) ? 'fully_paid' : (($orderData['amount_paid'] > 0) ? 'partially_paid' : 'unpaid');

        $savedDoc = ShagunCompletionDossierPdf::saveToStorage($orderData, 'admin_action');
        if (!$savedDoc || empty($savedDoc['id'])) {
            throw new RuntimeException("Failed to generate and store Completion Dossier PDF for order {$orderRef}.");
        }
        $documentId = (int)$savedDoc['id'];
    }

    // 5. Revoke existing active shares for this order to avoid dangling active links
    $revokeStmt = $pdo->prepare("
        UPDATE order_document_shares 
        SET is_revoked = 1, revoked_at = NOW() 
        WHERE order_id = :oid AND is_revoked = 0
    ");
    $revokeStmt->execute([':oid' => $orderId]);

    // 6. Compute expiration
    if ($lifetimeDays === null || $lifetimeDays <= 0) {
        $config = require __DIR__ . '/config.php';
        $lifetimeDays = (int)($config['app']['share_token_lifetime_days'] ?? 30);
        if ($lifetimeDays <= 0) {
            $lifetimeDays = 30;
        }
    }
    $expiresAt = date('Y-m-d H:i:s', time() + ($lifetimeDays * 86400));

    // 7. Generate random token and hash
    $rawToken = generate_share_token();
    $tokenHash = hash_share_token($rawToken);

    // 8. Insert into order_document_shares
    $insStmt = $pdo->prepare("
        INSERT INTO order_document_shares 
        (order_id, document_id, token_hash, created_by_admin_id, expires_at, is_revoked, access_count, created_at)
        VALUES (:oid, :did, :thash, :aid, :exp, 0, 0, NOW())
    ");
    $insStmt->execute([
        ':oid' => $orderId,
        ':did' => $documentId,
        ':thash' => $tokenHash,
        ':aid' => $adminId,
        ':exp' => $expiresAt,
    ]);

    $shareId = (int)$pdo->lastInsertId();

    return [
        'id' => $shareId,
        'token' => $rawToken,
        'token_hash' => $tokenHash,
        'order_id' => $orderId,
        'document_id' => $documentId,
        'expires_at' => $expiresAt,
        'share_url' => get_document_share_url($rawToken),
    ];
}

/**
 * Revoke active share links for an order.
 *
 * @param PDO $pdo
 * @param int $orderId
 * @param int|null $shareId Optional specific share ID to revoke
 * @return bool
 */
function revoke_order_document_shares(PDO $pdo, int $orderId, ?int $shareId = null): bool {
    if ($shareId !== null && $shareId > 0) {
        $stmt = $pdo->prepare("
            UPDATE order_document_shares 
            SET is_revoked = 1, revoked_at = NOW() 
            WHERE id = :sid AND order_id = :oid AND is_revoked = 0
        ");
        return $stmt->execute([':sid' => $shareId, ':oid' => $orderId]);
    }

    $stmt = $pdo->prepare("
        UPDATE order_document_shares 
        SET is_revoked = 1, revoked_at = NOW() 
        WHERE order_id = :oid AND is_revoked = 0
    ");
    return $stmt->execute([':oid' => $orderId]);
}

/**
 * Validate an incoming raw share token.
 * 
 * Performs:
 * - Format check (64-hex)
 * - Hash comparison in DB
 * - Revocation check
 * - Expiration check
 * - Order status check (completed or delivered)
 * - Completed photo check (>= 1 photo)
 * - Physical file verification
 * - Access count and last_accessed_at audit update
 *
 * @param PDO $pdo
 * @param string $rawToken
 * @return array [
 *   'valid' => bool,
 *   'error' => string|null,
 *   'error_title' => string|null,
 *   'error_message' => string|null,
 *   'http_code' => int,
 *   'share' => array|null,
 *   'file_path' => string|null,
 *   'file_name' => string|null
 * ]
 */
function validate_order_document_share_token(PDO $pdo, string $rawToken): array {
    $cleanToken = trim($rawToken);

    // Format validation: must be exactly 64 hex characters
    if (strlen($cleanToken) !== 64 || !ctype_xdigit($cleanToken)) {
        return [
            'valid' => false,
            'error' => 'invalid_token_format',
            'error_title' => 'Invalid Document Link',
            'error_message' => 'The provided link format is not recognized. Please verify the URL or contact Shagun Ladies Tailor.',
            'http_code' => 404,
            'share' => null,
            'file_path' => null,
            'file_name' => null
        ];
    }

    $tokenHash = hash_share_token($cleanToken);

    // Query share joined with order, document, customer
    $stmt = $pdo->prepare("
        SELECT 
            ods.*,
            od.file_path, od.file_name, od.file_size_bytes, od.checksum, od.document_type, od.version,
            o.order_ref, o.status AS order_status, o.user_id, o.total_amount, o.advance_amount,
            u.name AS customer_name, u.phone AS customer_phone
        FROM order_document_shares ods
        INNER JOIN orders o ON o.id = ods.order_id
        INNER JOIN order_documents od ON od.id = ods.document_id
        LEFT JOIN users u ON u.id = o.user_id
        WHERE ods.token_hash = :thash
        LIMIT 1
    ");
    $stmt->execute([':thash' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'valid' => false,
            'error' => 'not_found',
            'error_title' => 'Link Not Found',
            'error_message' => 'This Completion Dossier link does not exist or has been removed. Please request a new link from Shagun Ladies Tailor.',
            'http_code' => 404,
            'share' => null,
            'file_path' => null,
            'file_name' => null
        ];
    }

    // Revocation check
    if ((int)$row['is_revoked'] === 1) {
        return [
            'valid' => false,
            'error' => 'revoked',
            'error_title' => 'Link Revoked',
            'error_message' => 'This Completion Dossier link was revoked by the atelier. Please contact us to receive an updated share link.',
            'http_code' => 403,
            'share' => $row,
            'file_path' => null,
            'file_name' => null
        ];
    }

    // Expiration check
    $expiresTime = strtotime($row['expires_at']);
    if ($expiresTime === false || $expiresTime <= time()) {
        return [
            'valid' => false,
            'error' => 'expired',
            'error_title' => 'Link Expired',
            'error_message' => 'For your security, Completion Dossier share links expire after 30 days. Please contact Shagun Ladies Tailor to receive an updated link.',
            'http_code' => 410,
            'share' => $row,
            'file_path' => null,
            'file_name' => null
        ];
    }

    // Order status gating: Must still be completed or delivered
    $canonicalStatus = get_canonical_status((string)$row['order_status']);
    if ($canonicalStatus !== 'completed' && $canonicalStatus !== 'delivered') {
        return [
            'valid' => false,
            'error' => 'invalid_order_status',
            'error_title' => 'Dossier Not Available',
            'error_message' => 'The Completion Dossier is only available for orders with Completed or Delivered status.',
            'http_code' => 403,
            'share' => $row,
            'file_path' => null,
            'file_name' => null
        ];
    }

    // Photo gating: Order must still have at least 1 completed photo
    $orderId = (int)$row['order_id'];
    $photoStmt = $pdo->prepare("SELECT COUNT(*) FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
    $photoStmt->execute([':oid' => $orderId]);
    $photoCount = (int)$photoStmt->fetchColumn();
    if ($photoCount < 1) {
        return [
            'valid' => false,
            'error' => 'missing_photos',
            'error_title' => 'Dossier Incomplete',
            'error_message' => 'This Completion Dossier is currently unavailable because the completed garment photographs are pending verification.',
            'http_code' => 403,
            'share' => $row,
            'file_path' => null,
            'file_name' => null
        ];
    }

    // Verify physical file on disk
    $root = dirname(__DIR__);
    $fpath = (string)$row['file_path'];
    $absPath = str_starts_with($fpath, '/') || (strlen($fpath) > 1 && $fpath[1] === ':') ? $fpath : $root . '/' . ltrim($fpath, '/\\');

    if (!file_exists($absPath)) {
        // Attempt to regenerate
        try {
            require_once __DIR__ . '/completion-dossier-pdf.php';
            $orderRef = (string)$row['order_ref'];
            $userId = (int)$row['user_id'];
            $orderData = get_customer_order_by_ref($orderRef, $userId);
            if (!$orderData) {
                $orderData = $row;
            }
            $orderData['id'] = $orderId;
            $orderData['canonical_status'] = $canonicalStatus;

            $pStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed' ORDER BY created_at ASC");
            $pStmt->execute([':oid' => $orderId]);
            $orderData['completed_photos'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

            $inStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'awaiting_confirmation' ORDER BY created_at ASC LIMIT 3");
            $inStmt->execute([':oid' => $orderId]);
            $orderData['intake_photos'] = $inStmt->fetchAll(PDO::FETCH_ASSOC);

            $custProfile = $userId > 0 ? get_customer_profile($userId) : null;
            if ($custProfile) {
                $orderData['customer_name'] = $custProfile['name'];
                $orderData['customer_phone'] = $custProfile['phone'];
                $orderData['customer_email'] = $custProfile['email'];
                $orderData['customer_address'] = $custProfile['address'];
                $orderData['phone_display'] = $custProfile['phone_display'];
                $orderData['address_display'] = $custProfile['address_display'];
            }

            $seqMap = get_customer_order_sequence_map($pdo);
            $seq = $seqMap['by_order_id'][$orderId] ?? 1;
            $orderData['customer_sequence'] = $seq;
            $orderData['customer_sequence_label'] = get_customer_sequence_label($seq);

            $payStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = :oid AND status = 'completed'");
            $payStmt->execute([':oid' => $orderId]);
            $completedPaySum = (float)$payStmt->fetchColumn();
            $orderData['total_amount'] = (float)$row['total_amount'];
            $orderData['amount_paid'] = $completedPaySum > 0 ? $completedPaySum : (float)$row['advance_amount'];
            $orderData['remaining_balance'] = max(0.0, (float)$row['total_amount'] - $orderData['amount_paid']);
            $orderData['payment_status'] = ($orderData['amount_paid'] >= $orderData['total_amount'] && $orderData['total_amount'] > 0) ? 'fully_paid' : (($orderData['amount_paid'] > 0) ? 'partially_paid' : 'unpaid');

            $regenerated = ShagunCompletionDossierPdf::saveToStorage($orderData, 'automated');
            if ($regenerated && !empty($regenerated['file_path']) && file_exists($regenerated['file_path'])) {
                $absPath = $regenerated['file_path'];
            }
        } catch (\Throwable $e) {
            error_log('[Document Share Regenerate Error] ' . $e->getMessage());
        }
    }

    if (!file_exists($absPath)) {
        return [
            'valid' => false,
            'error' => 'file_not_found',
            'error_title' => 'File Missing',
            'error_message' => 'The PDF document could not be located on the server. Please contact Shagun Ladies Tailor.',
            'http_code' => 404,
            'share' => $row,
            'file_path' => null,
            'file_name' => null
        ];
    }

    // Update access metrics: increment access_count, record last_accessed_at
    try {
        $upd = $pdo->prepare("
            UPDATE order_document_shares 
            SET access_count = access_count + 1, last_accessed_at = NOW() 
            WHERE id = :id
        ");
        $upd->execute([':id' => (int)$row['id']]);
        $row['access_count'] = ((int)$row['access_count']) + 1;
        $row['last_accessed_at'] = date('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        error_log('[Document Share Audit Error] ' . $e->getMessage());
    }

    $safeRef = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$row['order_ref']);
    $downloadName = (string)($row['file_name'] ?? "Shagun-Completion-Dossier-{$safeRef}.pdf");

    return [
        'valid' => true,
        'error' => null,
        'error_title' => null,
        'error_message' => null,
        'http_code' => 200,
        'share' => $row,
        'file_path' => $absPath,
        'file_name' => $downloadName
    ];
}
