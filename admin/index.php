<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order-status.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/completion-dossier-pdf.php';
require_once __DIR__ . '/../includes/document-shares.php';

// Server-side guard: enforce authenticated admin session
require_admin_login();

$admin = get_logged_in_admin();
$adminId = (int) ($admin['id'] ?? 0);
$adminRole = (string) ($admin['role'] ?? 'super_admin');
$adminRoleLabel = get_admin_role_label($adminRole);
$fullName = htmlspecialchars($admin['full_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($admin['username'] ?? '', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($admin['email'] ?? '', ENT_QUOTES, 'UTF-8');

$flashSuccess = null;
$flashError = null;

// -------------------------------------------------------------
// POST ACTIONS: STATUS, DATE & GALLERY UPDATES
// -------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!verify_csrf_token($csrfToken)) {
        $flashError = "Invalid or expired security token (CSRF). Please refresh the page and try again.";
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'admin_update_status') {
            $orderRef = trim((string) ($_POST['order_ref'] ?? ''));
            $newStatus = trim((string) ($_POST['new_status'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $res = admin_update_order_status($orderRef, $newStatus, $notes, $adminId, $adminRole);
            if ($res['success']) {
                $flashSuccess = "Order {$orderRef} status updated to '" . htmlspecialchars($res['status_label']) . "'.";
            } else {
                $flashError = $res['error'] ?? 'Could not update status.';
            }
        } elseif ($action === 'admin_update_delivery_date') {
            if (!in_array($adminRole, ['super_admin', 'store_manager'], true)) {
                $flashError = "You do not have permission to update committed delivery dates.";
            } else {
                $orderRef = trim((string) ($_POST['order_ref'] ?? ''));
                $newDate = trim((string) ($_POST['admin_delivery_date'] ?? ''));
                $note = trim((string) ($_POST['admin_note'] ?? ''));

                if (!empty($orderRef) && !empty($newDate)) {
                    $dateUpdated = false;

                    // 1. Update permanent database
                    try {
                        $pdo = get_db_connection();
                        $ordStmt = $pdo->prepare('SELECT id, admin_delivery_date FROM orders WHERE order_ref = :ref LIMIT 1');
                        $ordStmt->execute([':ref' => $orderRef]);
                        $dbOrd = $ordStmt->fetch();
                        if ($dbOrd) {
                            $orderId = (int)$dbOrd['id'];
                            $oldDate = $dbOrd['admin_delivery_date'];
                            $upd = $pdo->prepare('UPDATE orders SET admin_delivery_date = :dt, updated_at = NOW() WHERE id = :id');
                            $upd->execute([':dt' => $newDate, ':id' => $orderId]);

                            $insDate = $pdo->prepare('
                                INSERT INTO order_date_history (
                                    order_id, event_type, date_type, old_date, new_date, actor, admin_user_id, note, created_at
                                ) VALUES (
                                    :oid, \'admin_update\', \'admin_delivery_date\', :old_dt, :new_dt, \'admin\', :admin_id, :note, NOW()
                                )
                            ');
                            $insDate->execute([
                                ':oid' => $orderId,
                                ':old_dt' => $oldDate,
                                ':new_dt' => $newDate,
                                ':admin_id' => $adminId,
                                ':note' => $note ?: "Admin updated delivery date to {$newDate}"
                            ]);
                            $dateUpdated = true;
                        }
                    } catch (Throwable $e) {
                        error_log('[Admin Delivery Date DB Error] ' . $e->getMessage());
                    }

                    // 2. Search and update in $_SESSION['customer_orders']
                    if (!empty($_SESSION['customer_orders']) && is_array($_SESSION['customer_orders'])) {
                        foreach ($_SESSION['customer_orders'] as $uId => $orders) {
                            if (isset($orders[$orderRef])) {
                                $_SESSION['customer_orders'][$uId][$orderRef]['admin_delivery_date'] = $newDate;
                                $_SESSION['customer_orders'][$uId][$orderRef]['admin_note'] = $note;
                                $_SESSION['customer_orders'][$uId][$orderRef]['admin_updated_by'] = 'admin';
                                $_SESSION['customer_orders'][$uId][$orderRef]['admin_updated_at'] = time();
                                if (!isset($_SESSION['customer_orders'][$uId][$orderRef]['date_history'])) {
                                    $_SESSION['customer_orders'][$uId][$orderRef]['date_history'] = [];
                                }
                                $_SESSION['customer_orders'][$uId][$orderRef]['date_history'][] = [
                                    'type' => 'admin_update',
                                    'date' => $newDate,
                                    'created_at' => time(),
                                    'formatted_created' => date('d M Y, h:i A'),
                                    'actor' => 'admin',
                                    'note' => $note ?: "Admin updated delivery date to $newDate"
                                ];
                                $dateUpdated = true;
                                break;
                            }
                        }
                    }

                    if (!empty($_SESSION['standard_order']) && (($_SESSION['standard_order']['order_ref'] ?? '') === $orderRef || ($_SESSION['standard_order']['payment']['order_ref'] ?? '') === $orderRef)) {
                        $_SESSION['standard_order']['admin_delivery_date'] = $newDate;
                        $_SESSION['standard_order']['admin_note'] = $note;
                        $dateUpdated = true;
                    }
                    if (!empty($_SESSION['luxe_wedding']) && (($_SESSION['luxe_wedding']['order_ref'] ?? '') === $orderRef || ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '') === $orderRef)) {
                        $_SESSION['luxe_wedding']['admin_delivery_date'] = $newDate;
                        $_SESSION['luxe_wedding']['admin_note'] = $note;
                        $dateUpdated = true;
                    }

                    if ($dateUpdated) {
                        $flashSuccess = "Committed delivery date for {$orderRef} updated to " . date('j F Y', strtotime($newDate)) . ".";
                    } else {
                        $flashError = "Order {$orderRef} not found.";
                    }
                }
            }
        } elseif ($action === 'admin_upload_order_photo') {
            $orderRef = trim((string)($_POST['order_ref'] ?? ''));
            $stage = trim((string)($_POST['stage'] ?? ''));
            $caption = trim((string)($_POST['caption'] ?? ''));

            if (!in_array($stage, ['awaiting_confirmation', 'completed'], true)) {
                $flashError = "Invalid gallery stage specified.";
            } elseif (empty($_FILES['gallery_photo']) || $_FILES['gallery_photo']['error'] !== UPLOAD_ERR_OK) {
                $flashError = "Please select a valid image file to upload.";
            } else {
                $file = $_FILES['gallery_photo'];

                // 1. File size limit (max 5MB)
                if ($file['size'] > 5 * 1024 * 1024) {
                    $flashError = "Image file exceeds maximum allowed size of 5MB.";
                } else {
                    // 2. Validate MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    $allowedMimes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];

                    if (!isset($allowedMimes[$mime])) {
                        $flashError = "Invalid image format. Only JPEG, PNG, and WebP are supported.";
                    } elseif (!@getimagesize($file['tmp_name'])) {
                        $flashError = "Uploaded file is not a valid image.";
                    } else {
                        try {
                            $pdo = get_db_connection();
                            $ordStmt = $pdo->prepare("SELECT id FROM orders WHERE order_ref = :ref LIMIT 1");
                            $ordStmt->execute([':ref' => $orderRef]);
                            $ordRow = $ordStmt->fetch(PDO::FETCH_ASSOC);

                            if (!$ordRow) {
                                $flashError = "Order '{$orderRef}' not found.";
                            } else {
                                $orderId = (int)$ordRow['id'];

                                // Check stage limit (max 3 images)
                                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM order_gallery_photos WHERE order_id = :oid AND stage = :stage");
                                $countStmt->execute([':oid' => $orderId, ':stage' => $stage]);
                                $existingCount = (int)$countStmt->fetchColumn();

                                if ($existingCount >= 3) {
                                    $stageName = ($stage === 'awaiting_confirmation') ? 'Intake (Awaiting Confirmation)' : 'Finished Garment (Completed)';
                                    $flashError = "Maximum 3 photos allowed for {$stageName}. Please remove an existing photo first.";
                                } else {
                                    $ext = $allowedMimes[$mime];
                                    $filename = 'gallery_' . $orderId . '_' . $stage . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                                    $targetDir = __DIR__ . '/../uploads/order_gallery/';
                                    if (!is_dir($targetDir)) {
                                        mkdir($targetDir, 0755, true);
                                    }
                                    $targetPath = $targetDir . $filename;

                                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                                        $insStmt = $pdo->prepare("
                                            INSERT INTO order_gallery_photos 
                                            (order_id, stage, photo_url, original_filename, file_size_bytes, mime_type, caption, uploaded_by_admin_id, created_at)
                                            VALUES (:oid, :stage, :url, :orig, :size, :mime, :caption, :admin_id, NOW())
                                        ");
                                        $insStmt->execute([
                                            ':oid' => $orderId,
                                            ':stage' => $stage,
                                            ':url' => 'uploads/order_gallery/' . $filename,
                                            ':orig' => basename($file['name']),
                                            ':size' => (int)$file['size'],
                                            ':mime' => $mime,
                                            ':caption' => $caption !== '' ? $caption : null,
                                            ':admin_id' => $adminId > 0 ? $adminId : null
                                        ]);
                                        $flashSuccess = "Photo uploaded successfully for order {$orderRef}.";
                                    } else {
                                        $flashError = "Failed to store uploaded photo on server.";
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            $flashError = "Error saving photo: " . $e->getMessage();
                        }
                    }
                }
            }
        } elseif ($action === 'admin_delete_order_photo') {
            if (!in_array($adminRole, ['super_admin', 'store_manager'], true)) {
                $flashError = "You do not have permission to delete order photographs.";
            } else {
                $photoId = (int)($_POST['photo_id'] ?? 0);
                $orderRef = trim((string)($_POST['order_ref'] ?? ''));

                if ($photoId > 0 && $orderRef !== '') {
                    try {
                        $pdo = get_db_connection();
                        $ordStmt = $pdo->prepare("SELECT id FROM orders WHERE order_ref = :ref LIMIT 1");
                        $ordStmt->execute([':ref' => $orderRef]);
                        $targetOrderId = (int)$ordStmt->fetchColumn();

                        $chkStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE id = :id LIMIT 1");
                        $chkStmt->execute([':id' => $photoId]);
                        $photo = $chkStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$photo) {
                            $flashError = "Photo not found.";
                        } elseif ($targetOrderId <= 0 || (int)$photo['order_id'] !== $targetOrderId) {
                            // Cross-order authorization check
                            $flashError = "Unauthorized: photo does not belong to order '{$orderRef}'.";
                        } else {
                            if (!empty($photo['photo_url'])) {
                                $realPath = realpath(__DIR__ . '/../' . ltrim($photo['photo_url'], '/\\'));
                                $uploadsDir = realpath(__DIR__ . '/../uploads/order_gallery');
                                if ($realPath && $uploadsDir && strpos($realPath, $uploadsDir) === 0) {
                                    @unlink($realPath);
                                }
                            }

                            $delStmt = $pdo->prepare("DELETE FROM order_gallery_photos WHERE id = :id");
                            $delStmt->execute([':id' => $photoId]);
                            $flashSuccess = "Photo deleted successfully.";
                        }
                    } catch (\Throwable $e) {
                        $flashError = "Error deleting photo: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'generate_share_link') {
            if (!in_array($adminRole, ['super_admin', 'store_manager'], true)) {
                $flashError = "You do not have permission to generate document share links.";
            } else {
                $orderRef = trim((string) ($_POST['order_ref'] ?? ''));
                if ($orderRef !== '') {
                    try {
                        $pdo = get_db_connection();
                        $ordStmt = $pdo->prepare('SELECT id FROM orders WHERE order_ref = :ref LIMIT 1');
                        $ordStmt->execute([':ref' => $orderRef]);
                        $orderId = (int)$ordStmt->fetchColumn();

                        if ($orderId > 0) {
                            $share = create_order_document_share($pdo, $orderId, $adminId);
                            if (!isset($_SESSION['last_generated_share'])) {
                                $_SESSION['last_generated_share'] = [];
                            }
                            $_SESSION['last_generated_share'][$orderRef] = $share['share_url'];
                            $flashSuccess = "Secure 30-day Completion Dossier share link generated for order {$orderRef}.";
                        } else {
                            $flashError = "Order '{$orderRef}' not found.";
                        }
                    } catch (\Throwable $e) {
                        error_log('[Generate Share Error] ' . $e->getMessage());
                        $flashError = "Could not generate share link: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'revoke_share_link') {
            if (!in_array($adminRole, ['super_admin', 'store_manager'], true)) {
                $flashError = "You do not have permission to revoke document share links.";
            } else {
                $orderRef = trim((string) ($_POST['order_ref'] ?? ''));
                if ($orderRef !== '') {
                    try {
                        $pdo = get_db_connection();
                        $ordStmt = $pdo->prepare('SELECT id FROM orders WHERE order_ref = :ref LIMIT 1');
                        $ordStmt->execute([':ref' => $orderRef]);
                        $orderId = (int)$ordStmt->fetchColumn();

                        if ($orderId > 0) {
                            revoke_order_document_shares($pdo, $orderId);
                            if (isset($_SESSION['last_generated_share'][$orderRef])) {
                                unset($_SESSION['last_generated_share'][$orderRef]);
                            }
                            $flashSuccess = "All active share links for order {$orderRef} have been revoked.";
                        } else {
                            $flashError = "Order '{$orderRef}' not found.";
                        }
                    } catch (\Throwable $e) {
                        error_log('[Revoke Share Error] ' . $e->getMessage());
                        $flashError = "Could not revoke share link: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// -------------------------------------------------------------
// GET ACTION: ADMIN DOWNLOAD DOSSIER PDF
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_dossier') {
    require_admin_login();

    $targetRef = trim((string) ($_GET['ref'] ?? ''));
    if ($targetRef !== '') {
        $orderToDownload = null;

        // 1. Query permanent database first with full child records
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare('SELECT user_id FROM orders WHERE order_ref = :ref LIMIT 1');
            $stmt->execute([':ref' => $targetRef]);
            $dbOrd = $stmt->fetch();
            if ($dbOrd) {
                $dbUserId = (int) $dbOrd['user_id'];
                $orderToDownload = get_customer_order_by_ref($targetRef, $dbUserId);
            }
        } catch (Throwable $e) {
            error_log('[Admin Dossier Lookup Error] ' . $e->getMessage());
        }

        // 2. Check session storage if not found in database
        if ($orderToDownload === null) {
            if (!empty($_SESSION['customer_orders']) && is_array($_SESSION['customer_orders'])) {
                foreach ($_SESSION['customer_orders'] as $uId => $orders) {
                    if (isset($orders[$targetRef])) {
                        $orderToDownload = $orders[$targetRef];
                        break;
                    }
                }
            }
            if ($orderToDownload === null && !empty($_SESSION['standard_order']) && (($_SESSION['standard_order']['order_ref'] ?? '') === $targetRef || ($_SESSION['standard_order']['payment']['order_ref'] ?? '') === $targetRef)) {
                $orderToDownload = $_SESSION['standard_order'];
            }
            if ($orderToDownload === null && !empty($_SESSION['luxe_wedding']) && (($_SESSION['luxe_wedding']['order_ref'] ?? '') === $targetRef || ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '') === $targetRef)) {
                $orderToDownload = $_SESSION['luxe_wedding'];
            }
        }

        if ($orderToDownload !== null) {
            $uId = (int) ($orderToDownload['user_id'] ?? 0);
            if ($uId > 0) {
                $custProfile = get_customer_profile($uId);
                if ($custProfile) {
                    $orderToDownload['customer_name'] = $custProfile['name'];
                    $orderToDownload['customer_phone'] = $custProfile['phone'] ?? '';
                    $orderToDownload['customer_email'] = $custProfile['email'] ?? '';
                    $orderToDownload['customer_address'] = $custProfile['address'] ?? '';
                }
            }
            require_once __DIR__ . '/../includes/dossier-pdf.php';
            ShagunDossierPdf::download($orderToDownload);
            exit;
        } else {
            $flashError = "Order '{$targetRef}' not found for dossier download.";
        }
    } else {
        $flashError = "Invalid order reference.";
    }
}

// -------------------------------------------------------------
// GET ACTION: ADMIN DOWNLOAD COMPLETION DOSSIER PDF
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_completion_dossier') {
    require_admin_login();

    $targetRef = trim((string) ($_GET['ref'] ?? ''));
    if ($targetRef !== '') {
        try {
            $pdo = get_db_connection();
            $ordStmt = $pdo->prepare('SELECT * FROM orders WHERE order_ref = :ref LIMIT 1');
            $ordStmt->execute([':ref' => $targetRef]);
            $dbOrd = $ordStmt->fetch(PDO::FETCH_ASSOC);

            if (!$dbOrd) {
                $flashError = "Order '{$targetRef}' not found in permanent database.";
            } else {
                $canonicalStatus = get_canonical_status((string)$dbOrd['status']);
                if ($canonicalStatus !== 'completed' && $canonicalStatus !== 'delivered') {
                    $flashError = "Completion dossier is only available for orders with status Completed or Delivered.";
                } else {
                    $orderId = (int)$dbOrd['id'];
                    $photoStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed' ORDER BY created_at ASC");
                    $photoStmt->execute([':oid' => $orderId]);
                    $completedPhotos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($completedPhotos)) {
                        $flashError = "Upload at least one completed garment photo before generating the Completion Dossier.";
                    } else {
                        $inPhotoStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'awaiting_confirmation' ORDER BY created_at ASC LIMIT 3");
                        $inPhotoStmt->execute([':oid' => $orderId]);
                        $intakePhotos = $inPhotoStmt->fetchAll(PDO::FETCH_ASSOC);

                        $dbUserId = (int)$dbOrd['user_id'];
                        $orderData = get_customer_order_by_ref($targetRef, $dbUserId);
                        if (!$orderData) {
                            $orderData = $dbOrd;
                        }
                        $custProfile = $dbUserId > 0 ? get_customer_profile($dbUserId) : null;
                        if ($custProfile) {
                            $orderData['customer_name'] = $custProfile['name'];
                            $orderData['customer_phone'] = $custProfile['phone'];
                            $orderData['customer_email'] = $custProfile['email'];
                            $orderData['customer_address'] = $custProfile['address'];
                            $orderData['phone_display'] = $custProfile['phone_display'];
                            $orderData['address_display'] = $custProfile['address_display'];
                        }
                        $orderData['id'] = $orderId;
                        $orderData['completed_photos'] = $completedPhotos;
                        $orderData['intake_photos'] = $intakePhotos;
                        $orderData['canonical_status'] = $canonicalStatus;

                        // Customer sequence label
                        $seqMap = get_customer_order_sequence_map($pdo);
                        $seq = $seqMap['by_order_id'][$orderId] ?? ($seqMap['by_order_ref'][$targetRef] ?? 1);
                        $orderData['customer_sequence'] = $seq;
                        $orderData['customer_sequence_label'] = get_customer_sequence_label($seq);

                        // Financials
                        $payStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = :oid AND status = 'completed'");
                        $payStmt->execute([':oid' => $orderId]);
                        $completedPaySum = (float)$payStmt->fetchColumn();
                        $orderData['total_amount'] = (float)$dbOrd['total_amount'];
                        $orderData['amount_paid'] = $completedPaySum > 0 ? $completedPaySum : (float)$dbOrd['advance_amount'];
                        $orderData['remaining_balance'] = max(0.0, (float)$dbOrd['total_amount'] - $orderData['amount_paid']);
                        $orderData['payment_status'] = ($orderData['amount_paid'] >= $orderData['total_amount'] && $orderData['total_amount'] > 0) ? 'fully_paid' : (($orderData['amount_paid'] > 0) ? 'partially_paid' : 'unpaid');

                        ShagunCompletionDossierPdf::download($orderData);
                        exit;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Admin Completion Dossier Error] ' . $e->getMessage());
            $flashError = "Error generating Completion Dossier: " . $e->getMessage();
        }
    } else {
        $flashError = "Invalid order reference.";
    }
}

// -------------------------------------------------------------
// GET ACTION: ADMIN WHATSAPP SHARE COMPLETION UPDATE
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'whatsapp_share') {
    require_admin_login();

    $targetRef = trim((string) ($_GET['ref'] ?? ''));
    if ($targetRef !== '') {
        try {
            $pdo = get_db_connection();
            $ordStmt = $pdo->prepare('SELECT * FROM orders WHERE order_ref = :ref LIMIT 1');
            $ordStmt->execute([':ref' => $targetRef]);
            $dbOrd = $ordStmt->fetch(PDO::FETCH_ASSOC);

            if (!$dbOrd) {
                $flashError = "Order '{$targetRef}' not found.";
            } else {
                $canonicalStatus = get_canonical_status((string)$dbOrd['status']);
                if ($canonicalStatus !== 'completed' && $canonicalStatus !== 'delivered') {
                    $flashError = "Completion update sharing is only available for orders with status Completed or Delivered.";
                } else {
                    $orderId = (int)$dbOrd['id'];
                    $dbUserId = (int)$dbOrd['user_id'];
                    $orderData = get_customer_order_by_ref($targetRef, $dbUserId);
                    if (!$orderData) {
                        $orderData = $dbOrd;
                    }
                    $orderData['id'] = $orderId;
                    $orderData['canonical_status'] = $canonicalStatus;

                    // Financials
                    $payStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = :oid AND status = 'completed'");
                    $payStmt->execute([':oid' => $orderId]);
                    $completedPaySum = (float)$payStmt->fetchColumn();
                    $orderData['total_amount'] = (float)$dbOrd['total_amount'];
                    $orderData['amount_paid'] = $completedPaySum > 0 ? $completedPaySum : (float)$dbOrd['advance_amount'];
                    $orderData['remaining_balance'] = max(0.0, (float)$dbOrd['total_amount'] - $orderData['amount_paid']);

                    $shareUrl = null;
                    if (is_public_url_configured()) {
                        if (!empty($_SESSION['last_generated_share'][$targetRef])) {
                            $shareUrl = $_SESSION['last_generated_share'][$targetRef];
                        } else {
                            $photoStmt = $pdo->prepare("SELECT COUNT(*) FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed'");
                            $photoStmt->execute([':oid' => $orderId]);
                            if ((int)$photoStmt->fetchColumn() >= 1) {
                                $share = create_order_document_share($pdo, $orderId, $adminId);
                                $shareUrl = $share['share_url'];
                                if (!isset($_SESSION['last_generated_share'])) {
                                    $_SESSION['last_generated_share'] = [];
                                }
                                $_SESSION['last_generated_share'][$targetRef] = $shareUrl;
                            }
                        }
                    }

                    $waUrl = format_whatsapp_click_to_chat_url($orderData, $shareUrl);
                    if (!empty($waUrl)) {
                        header('Location: ' . $waUrl);
                        exit;
                    } else {
                        $flashError = "Customer phone number not available or invalid for WhatsApp.";
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Admin WhatsApp Share Error] ' . $e->getMessage());
            $flashError = "Could not prepare WhatsApp share: " . $e->getMessage();
        }
    } else {
        $flashError = "Invalid order reference.";
    }
}

// -------------------------------------------------------------
// LOAD ORDERS FOR WORKSHOP QUEUE (DATABASE FIRST)
// -------------------------------------------------------------
$allOrders = [];
$sequenceMap = ['by_order_id' => [], 'by_order_ref' => [], 'by_user_id' => []];

// 1. Permanent database orders
try {
    $pdo = get_db_connection();
    $sequenceMap = get_customer_order_sequence_map($pdo);

    // Query gallery photos grouped by order_id and stage
    $galleryStmt = $pdo->query("SELECT * FROM order_gallery_photos ORDER BY created_at ASC");
    $galleryByOrder = [];
    while ($gp = $galleryStmt->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int)$gp['order_id'];
        $stg = $gp['stage'];
        if (!isset($galleryByOrder[$oid])) {
            $galleryByOrder[$oid] = ['awaiting_confirmation' => [], 'completed' => []];
        }
        $galleryByOrder[$oid][$stg][] = $gp;
    }

    // Query active document shares grouped by order_id
    $sharesStmt = $pdo->query("SELECT * FROM order_document_shares WHERE is_revoked = 0 AND expires_at > NOW() ORDER BY created_at DESC");
    $sharesByOrder = [];
    while ($sh = $sharesStmt->fetch(PDO::FETCH_ASSOC)) {
        $oid = (int)$sh['order_id'];
        if (!isset($sharesByOrder[$oid])) {
            $sharesByOrder[$oid] = $sh;
        }
    }

    $dbOrdersStmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC");
    while ($dbOrd = $dbOrdersStmt->fetch()) {
        $ref = (string) $dbOrd['order_ref'];
        $orderId = (int) $dbOrd['id'];
        $dbUserId = (int) $dbOrd['user_id'];
        $custProfile = get_customer_profile($dbUserId);

        // Fetch completed payment sum from order_payments
        $paySumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = :oid AND status = 'completed'");
        $paySumStmt->execute([':oid' => $orderId]);
        $completedPaymentsSum = (float)$paySumStmt->fetchColumn();

        $totalAmt = (float)$dbOrd['total_amount'];
        $advanceAmt = (float)$dbOrd['advance_amount'];
        $paidAmt = $completedPaymentsSum > 0 ? $completedPaymentsSum : $advanceAmt;
        $balanceAmt = max(0.0, $totalAmt - $paidAmt);

        $paymentStatus = 'unpaid';
        if ($paidAmt >= $totalAmt && $totalAmt > 0) {
            $paymentStatus = 'fully_paid';
        } elseif ($paidAmt > 0) {
            $paymentStatus = 'partially_paid';
        }

        // Customer Sequence
        $customerSeq = $sequenceMap['by_order_id'][$orderId] ?? ($sequenceMap['by_order_ref'][$ref] ?? 1);
        $customerSeqLabel = get_customer_sequence_label($customerSeq);

        // Fetch status history for audit trail
        $shStmt = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id = :oid ORDER BY id ASC');
        $shStmt->execute([':oid' => $orderId]);
        $statusHistory = [];
        while ($sh = $shStmt->fetch()) {
            $statusHistory[] = [
                'old_status' => $sh['old_status'],
                'new_status' => $sh['new_status'],
                'actor' => $sh['actor'],
                'notes' => $sh['notes'],
                'created_at' => strtotime($sh['created_at']),
                'formatted_created' => date('d M Y, h:i A', strtotime($sh['created_at']))
            ];
        }

        $allOrders[$ref] = [
            'id' => $orderId,
            'order_ref' => $ref,
            'user_id' => $dbUserId,
            'customer_name' => $custProfile['name'] ?? null,
            'customer_phone' => $custProfile['phone'] ?? null,
            'customer_email' => $custProfile['email'] ?? null,
            'customer_address' => $custProfile['address'] ?? null,
            'phone_display' => $custProfile['phone_display'] ?? 'Phone number not provided',
            'address_display' => $custProfile['address_display'] ?? 'Address not provided',
            'customer_sequence' => $customerSeq,
            'customer_sequence_label' => $customerSeqLabel,
            'workflow' => $dbOrd['workflow_type'],
            'order_type' => $dbOrd['workflow_type'] === 'standard' ? 'Standard Stitching' : 'Luxe Stitching',
            'occasion' => $dbOrd['occasion'],
            'status' => $dbOrd['status'],
            'production_status' => $dbOrd['status'],
            'canonical_status' => get_canonical_status((string)$dbOrd['status']),
            'total_amount' => $totalAmt,
            'grand_total' => $totalAmt,
            'amount_paid' => $paidAmt,
            'advance_amount' => $paidAmt,
            'remaining_balance' => $balanceAmt,
            'balance_amount' => $balanceAmt,
            'payment_status' => $paymentStatus,
            'booked_date' => $dbOrd['booked_date'],
            'requested_ready_date' => $dbOrd['requested_ready_date'],
            'admin_delivery_date' => $dbOrd['admin_delivery_date'],
            'customer_notes' => $dbOrd['customer_notes'],
            'status_history' => $statusHistory,
            'gallery_photos' => $galleryByOrder[$orderId] ?? ['awaiting_confirmation' => [], 'completed' => []],
            'active_share' => $sharesByOrder[$orderId] ?? null,
            'source' => 'database'
        ];
    }
} catch (\Throwable $e) {
    error_log('[Admin Orders DB Error] ' . $e->getMessage());
}

// 2. Session store orders (merge if not already present, or enrich existing with session garment details)
if (!empty($_SESSION['customer_orders']) && is_array($_SESSION['customer_orders'])) {
    foreach ($_SESSION['customer_orders'] as $uId => $orders) {
        if (is_array($orders)) {
            foreach ($orders as $ref => $ord) {
                if (!isset($allOrders[$ref])) {
                    $custUserId = (int) ($ord['user_id'] ?? $uId);
                    $custProfile = $custUserId > 0 ? get_customer_profile($custUserId) : null;
                    $ord['customer_name'] = $custProfile['name'] ?? ($ord['customer_name'] ?? null);
                    $ord['customer_phone'] = $custProfile['phone'] ?? null;
                    $ord['customer_email'] = $custProfile['email'] ?? ($ord['customer_email'] ?? null);
                    $ord['customer_address'] = $custProfile['address'] ?? null;
                    $ord['phone_display'] = $custProfile['phone_display'] ?? 'Phone number not provided';
                    $ord['address_display'] = $custProfile['address_display'] ?? 'Address not provided';

                    $orderId = (int)($ord['id'] ?? 0);
                    $customerSeq = $sequenceMap['by_order_id'][$orderId] ?? ($sequenceMap['by_order_ref'][$ref] ?? 1);
                    $ord['customer_sequence'] = $customerSeq;
                    $ord['customer_sequence_label'] = get_customer_sequence_label($customerSeq);

                    $tot = (float)($ord['advance_payment']['order_total'] ?? ($ord['grand_total'] ?? ($ord['total_amount'] ?? 0)));
                    $paid = (float)($ord['payment']['amount_paid'] ?? ($ord['advance_payment']['selected_amount'] ?? ($ord['advance_amount'] ?? 0)));
                    $bal = (float)($ord['payment']['remaining_balance'] ?? ($ord['balance_amount'] ?? max(0, $tot - $paid)));

                    $ord['total_amount'] = $tot;
                    $ord['grand_total'] = $tot;
                    $ord['amount_paid'] = $paid;
                    $ord['advance_amount'] = $paid;
                    $ord['remaining_balance'] = $bal;
                    $ord['balance_amount'] = $bal;
                    $ord['payment_status'] = ($paid >= $tot && $tot > 0) ? 'fully_paid' : (($paid > 0) ? 'partially_paid' : 'unpaid');
                    $ord['canonical_status'] = get_canonical_status((string)($ord['status'] ?? 'pending_confirmation'));
                    $ord['gallery_photos'] = ['awaiting_confirmation' => [], 'completed' => []];

                    $allOrders[$ref] = $ord;
                } else {
                    if (!empty($ord['people'])) $allOrders[$ref]['people'] = $ord['people'];
                    if (!empty($ord['standard_items'])) $allOrders[$ref]['standard_items'] = $ord['standard_items'];
                    if (!empty($ord['status_history'])) $allOrders[$ref]['status_history'] = $ord['status_history'];
                    if (!empty($ord['date_history'])) $allOrders[$ref]['date_history'] = $ord['date_history'];
                }
            }
        }
    }
}

if (!empty($_SESSION['standard_order']['payment']['status']) && $_SESSION['standard_order']['payment']['status'] === 'completed') {
    $ref = $_SESSION['standard_order']['order_ref'] ?? ($_SESSION['standard_order']['payment']['order_ref'] ?? '');
    if ($ref !== '' && !isset($allOrders[$ref])) {
        $custUserId = (int) ($_SESSION['standard_order']['user_id'] ?? 0);
        $custProfile = $custUserId > 0 ? get_customer_profile($custUserId) : null;
        $_SESSION['standard_order']['customer_name'] = $custProfile['name'] ?? null;
        $_SESSION['standard_order']['customer_phone'] = $custProfile['phone'] ?? null;
        $_SESSION['standard_order']['customer_email'] = $custProfile['email'] ?? null;
        $_SESSION['standard_order']['customer_address'] = $custProfile['address'] ?? null;
        $_SESSION['standard_order']['phone_display'] = $custProfile['phone_display'] ?? 'Phone number not provided';
        $_SESSION['standard_order']['address_display'] = $custProfile['address_display'] ?? 'Address not provided';
        
        $customerSeq = $sequenceMap['by_order_ref'][$ref] ?? 1;
        $_SESSION['standard_order']['customer_sequence'] = $customerSeq;
        $_SESSION['standard_order']['customer_sequence_label'] = get_customer_sequence_label($customerSeq);

        $tot = (float)($_SESSION['standard_order']['advance_payment']['order_total'] ?? ($_SESSION['standard_order']['grand_total'] ?? 0));
        $paid = (float)($_SESSION['standard_order']['payment']['amount_paid'] ?? ($_SESSION['standard_order']['advance_payment']['selected_amount'] ?? 0));
        $bal = (float)($_SESSION['standard_order']['payment']['remaining_balance'] ?? max(0, $tot - $paid));

        $_SESSION['standard_order']['total_amount'] = $tot;
        $_SESSION['standard_order']['grand_total'] = $tot;
        $_SESSION['standard_order']['amount_paid'] = $paid;
        $_SESSION['standard_order']['advance_amount'] = $paid;
        $_SESSION['standard_order']['remaining_balance'] = $bal;
        $_SESSION['standard_order']['balance_amount'] = $bal;
        $_SESSION['standard_order']['payment_status'] = ($paid >= $tot && $tot > 0) ? 'fully_paid' : (($paid > 0) ? 'partially_paid' : 'unpaid');
        $_SESSION['standard_order']['canonical_status'] = get_canonical_status((string)($_SESSION['standard_order']['status'] ?? 'pending_confirmation'));
        $_SESSION['standard_order']['gallery_photos'] = ['awaiting_confirmation' => [], 'completed' => []];

        $allOrders[$ref] = $_SESSION['standard_order'];
    }
}

if (!empty($_SESSION['luxe_wedding']['payment']['status']) && $_SESSION['luxe_wedding']['payment']['status'] === 'completed') {
    $ref = $_SESSION['luxe_wedding']['order_ref'] ?? ($_SESSION['luxe_wedding']['payment']['order_ref'] ?? '');
    if ($ref !== '' && !isset($allOrders[$ref])) {
        $custUserId = (int) ($_SESSION['luxe_wedding']['user_id'] ?? 0);
        $custProfile = $custUserId > 0 ? get_customer_profile($custUserId) : null;
        $_SESSION['luxe_wedding']['customer_name'] = $custProfile['name'] ?? null;
        $_SESSION['luxe_wedding']['customer_phone'] = $custProfile['phone'] ?? null;
        $_SESSION['luxe_wedding']['customer_email'] = $custProfile['email'] ?? null;
        $_SESSION['luxe_wedding']['customer_address'] = $custProfile['address'] ?? null;
        $_SESSION['luxe_wedding']['phone_display'] = $custProfile['phone_display'] ?? 'Phone number not provided';
        $_SESSION['luxe_wedding']['address_display'] = $custProfile['address_display'] ?? 'Address not provided';

        $customerSeq = $sequenceMap['by_order_ref'][$ref] ?? 1;
        $_SESSION['luxe_wedding']['customer_sequence'] = $customerSeq;
        $_SESSION['luxe_wedding']['customer_sequence_label'] = get_customer_sequence_label($customerSeq);

        $tot = (float)($_SESSION['luxe_wedding']['advance_payment']['order_total'] ?? ($_SESSION['luxe_wedding']['grand_total'] ?? 0));
        $paid = (float)($_SESSION['luxe_wedding']['payment']['amount_paid'] ?? ($_SESSION['luxe_wedding']['advance_payment']['selected_amount'] ?? 0));
        $bal = (float)($_SESSION['luxe_wedding']['payment']['remaining_balance'] ?? max(0, $tot - $paid));

        $_SESSION['luxe_wedding']['total_amount'] = $tot;
        $_SESSION['luxe_wedding']['grand_total'] = $tot;
        $_SESSION['luxe_wedding']['amount_paid'] = $paid;
        $_SESSION['luxe_wedding']['advance_amount'] = $paid;
        $_SESSION['luxe_wedding']['remaining_balance'] = $bal;
        $_SESSION['luxe_wedding']['balance_amount'] = $bal;
        $_SESSION['luxe_wedding']['payment_status'] = ($paid >= $tot && $tot > 0) ? 'fully_paid' : (($paid > 0) ? 'partially_paid' : 'unpaid');
        $_SESSION['luxe_wedding']['canonical_status'] = get_canonical_status((string)($_SESSION['luxe_wedding']['status'] ?? 'pending_confirmation'));
        $_SESSION['luxe_wedding']['gallery_photos'] = ['awaiting_confirmation' => [], 'completed' => []];

        $allOrders[$ref] = $_SESSION['luxe_wedding'];
    }
}

// -------------------------------------------------------------
// BUCKET ORDERS INTO 4 MUTUALLY EXCLUSIVE CANONICAL BUCKETS
// -------------------------------------------------------------
$bucketAwaiting = [];
$bucketStitching = [];
$bucketCompleted = [];
$bucketDelivered = [];
$unknownStatusOrders = [];

foreach ($allOrders as $ref => $ord) {
    $rawStatus = (string)($ord['status'] ?? ($ord['production_status'] ?? 'pending_confirmation'));
    $canonical = $ord['canonical_status'] ?? get_canonical_status($rawStatus);
    if ($canonical === 'awaiting_confirmation') {
        $bucketAwaiting[$ref] = $ord;
    } elseif ($canonical === 'stitching_in_process') {
        $bucketStitching[$ref] = $ord;
    } elseif ($canonical === 'completed') {
        $bucketCompleted[$ref] = $ord;
    } elseif ($canonical === 'delivered') {
        $bucketDelivered[$ref] = $ord;
    } else {
        // Unknown/unexpected status: NEVER silently assign to stitching, completed, delivered, or awaiting!
        $unknownStatusOrders[$ref] = $ord;
    }
}

// CRITICAL REQUIREMENT: Delivered Orders sorted newest delivered first (admin_delivery_date DESC, id DESC)
uasort($bucketDelivered, function (array $a, array $b): int {
    $dateA = !empty($a['admin_delivery_date']) ? strtotime((string)$a['admin_delivery_date']) : 0;
    $dateB = !empty($b['admin_delivery_date']) ? strtotime((string)$b['admin_delivery_date']) : 0;
    if ($dateA !== $dateB) {
        return $dateB <=> $dateA;
    }
    return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
});

$sections = [
    'awaiting_confirmation' => [
        'key' => 'awaiting_confirmation',
        'title' => '1. Awaiting Confirmation',
        'badge' => 'badge-awaiting',
        'desc' => 'Orders received and awaiting review, intake photos, and dispatch to production.',
        'orders' => $bucketAwaiting
    ],
    'stitching_in_process' => [
        'key' => 'stitching_in_process',
        'title' => '2. Stitching in Process',
        'badge' => 'badge-production',
        'desc' => 'Orders accepted by atelier and actively being cut, stitched, embroidered, or fitted.',
        'orders' => $bucketStitching
    ],
    'completed' => [
        'key' => 'completed',
        'title' => '3. Completed Orders',
        'badge' => 'badge-completed',
        'desc' => 'Orders finished, quality-checked, and awaiting final garment photos & customer delivery.',
        'orders' => $bucketCompleted
    ],
    'delivered' => [
        'key' => 'delivered',
        'title' => '4. Delivered Orders & Customer History',
        'badge' => 'badge-delivered',
        'desc' => 'Historical archive of successfully delivered and fulfilled customer orders.',
        'orders' => $bucketDelivered
    ]
];

$countAwaiting = count($bucketAwaiting);
$countInProcess = count($bucketStitching);
$countCompleted = count($bucketCompleted);
$countDelivered = count($bucketDelivered);
// Summary total strictly corresponds to the orders rendered across the canonical lifecycle sections
$countTotal = $countAwaiting + $countInProcess + $countCompleted + $countDelivered;
$countUnknown = count($unknownStatusOrders);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shagun Admin Dashboard | Workshop & Order Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #fcf9f5;
            --card-bg: #ffffff;
            --primary: #6b1d28;
            --primary-dark: #4e111b;
            --gold: #b38743;
            --gold-light: #f7eedb;
            --text-dark: #252321;
            --text-muted: #726c66;
            --border: #e8ded2;
            --sidebar-w: 260px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-color);
            color: var(--text-dark);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .admin-nav {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .admin-brand h1 {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: 0.02em;
        }

        .admin-tag {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: var(--gold-light);
            color: var(--gold);
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        .admin-nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .admin-nav-link {
            font-size: 13px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.15s ease;
        }

        .admin-nav-link:hover {
            color: var(--primary);
        }

        .btn-logout {
            padding: 6px 14px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-logout:hover {
            background: var(--primary);
            color: #ffffff;
        }

        .admin-main {
            flex: 1;
            max-width: 1320px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 24px 60px;
        }

        .admin-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 28px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }

        .admin-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            padding-bottom: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .admin-title-area h2 {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .admin-title-area p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .status-badge {
            font-size: 11.5px;
            font-weight: 600;
            color: #1e7e34;
            background: #eef8f1;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #c3e6cb;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .admin-metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
        }

        .metric-box {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .metric-box.metric-card-interactive {
            cursor: pointer;
            width: 100%;
            font-family: inherit;
            appearance: none;
            -webkit-appearance: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease,
                border-color 0.2s ease,
                background-color 0.2s ease;
        }

        .metric-box.metric-card-interactive:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(107, 29, 40, 0.08);
        }

        .metric-box.metric-card-interactive:focus-visible {
            outline: 2px solid #881337;
            outline-offset: 2px;
        }

        .metric-box.metric-card-interactive.is-active {
            border-color: #881337;
            box-shadow: 0 0 0 1px #881337;
            background-color: #fff9fa;
        }

        .admin-section-block.is-filtered-out {
            display: none !important;
        }

        /* MODULAR ADMIN NAVIGATION */
        .admin-modules-nav {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 0 28px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .admin-modules-scroll {
            display: flex;
            gap: 8px;
            align-items: center;
            min-width: max-content;
            padding: 10px 0;
        }

        .admin-module-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-dark);
            text-decoration: none;
            background: #f8f6f3;
            border: 1px solid var(--border);
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .admin-module-tab:hover:not(.is-disabled) {
            background: var(--gold-light);
            border-color: var(--gold);
            color: var(--primary);
        }

        .admin-module-tab.is-active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary-dark);
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(107, 29, 40, 0.2);
        }

        .admin-module-tab.is-disabled {
            opacity: 0.65;
            cursor: not-allowed;
            background: #f5f3ef;
            color: #8c857f;
        }

        .module-tab-icon {
            font-size: 14px;
        }

        .module-tab-badge {
            font-size: 10px;
            padding: 2px 6px;
            background: #e2ded9;
            color: #665f59;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .metric-box-num {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .metric-box-label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .metric-box.is-awaiting .metric-box-num { color: #b38743; }
        .metric-box.is-in-process .metric-box-num { color: #6b1d28; }
        .metric-box.is-completed .metric-box-num { color: #1e7e34; }
        .metric-box.is-delivered .metric-box-num { color: #1a568c; }

        .flash-alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }

        .flash-success {
            background: #eef8f1;
            border: 1px solid #c3e6cb;
            color: #1e7e34;
        }

        .flash-error {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            color: #c53030;
        }

        /* Order Section Blocks */
        .admin-section-block {
            margin-bottom: 36px;
        }

        .admin-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid var(--border);
            padding-bottom: 8px;
            margin-bottom: 6px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .admin-section-title {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-section-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .section-count-badge {
            background: var(--primary);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 12px;
        }

        .section-empty {
            background: #ffffff;
            border: 1px dashed var(--border);
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13.5px;
        }

        /* Order Cards */
        .order-item-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            transition: box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .order-item-card:hover {
            border-color: var(--gold);
            box-shadow: 0 4px 14px rgba(0,0,0,0.05);
        }

        .order-item-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .order-item-ref {
            font-family: 'Playfair Display', serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
        }

        .order-badge-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge-awaiting {
            background: #fdf5ea;
            color: #9e6c38;
            border: 1px solid #ecc99d;
        }

        .badge-production {
            background: #f8ecf0;
            color: #6b1d28;
            border: 1px solid #e6b8c4;
        }

        .badge-completed {
            background: #eaf6ee;
            color: #2e6945;
            border: 1px solid #b7dfc4;
        }

        .badge-delivered {
            background: #e8f4fd;
            color: #1a568c;
            border: 1px solid #b3d7f5;
        }

        /* Customer Details Box */
        .order-customer-section {
            background: #fdfaf7;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 14px;
        }

        .customer-info-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 10px;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 6px;
        }

        .customer-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            font-size: 12.5px;
        }

        .customer-cell .cell-label {
            display: block;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 2px;
        }

        .customer-cell .cell-val {
            color: var(--text-dark);
            font-weight: 600;
            word-break: break-word;
        }

        .customer-cell.address-cell {
            grid-column: 1 / -1;
        }

        /* Customer Sequence Pill */
        .cust-seq-pill {
            display: inline-block;
            padding: 2px 7px;
            font-size: 10.5px;
            font-weight: 700;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: #f0e6d6;
            color: #795548;
            border: 1px solid #d7ccc8;
            margin-left: 6px;
            vertical-align: middle;
        }

        .cust-seq-pill.is-returning {
            background: #e0f2fe;
            color: #0369a1;
            border-color: #bae6fd;
        }

        /* Payment Status Pill */
        .pay-status-pill {
            display: inline-block;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 700;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .pay-status-pill.unpaid { background: #fee2e2; color: #991b1b; }
        .pay-status-pill.partially_paid { background: #fef3c7; color: #92400e; }
        .pay-status-pill.fully_paid { background: #dcfce7; color: #166534; }

        .btn-download-dossier {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #ffffff;
            color: var(--primary);
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-download-dossier:hover {
            background: var(--gold-light);
            border-color: var(--gold);
            color: var(--primary-dark);
        }

        .btn-completion-dossier {
            background: linear-gradient(135deg, #1b3d2f 0%, #0d281e 100%) !important;
            color: #d4af37 !important;
            border-color: #d4af37 !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .btn-completion-dossier:hover {
            background: linear-gradient(135deg, #234f3d 0%, #15382b 100%) !important;
            border-color: #ffd700 !important;
            color: #ffffff !important;
        }

        .btn-disabled {
            opacity: 0.55;
            cursor: not-allowed !important;
            background: #f1f5f9 !important;
            color: #94a3b8 !important;
            border-color: #cbd5e1 !important;
            text-decoration: none !important;
            pointer-events: auto;
        }

        .btn-disabled:hover {
            background: #f1f5f9 !important;
            color: #94a3b8 !important;
            border-color: #cbd5e1 !important;
        }

        .btn-whatsapp-share {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #25D366;
            color: #ffffff !important;
            border: 1px solid #20ba5a;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-whatsapp-share:hover {
            background: #1eb857 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 5px rgba(37, 211, 102, 0.3);
        }

        .completion-warning-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .share-link-bar {
            margin-top: 10px;
            padding: 8px 12px;
            background: #FAF8F5;
            border: 1px solid var(--border);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 12px;
        }

        .btn-create-share, .btn-regen-share {
            padding: 5px 10px;
            background: #722F37;
            color: #ffffff;
            border: none;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s ease;
        }

        .btn-create-share:hover, .btn-regen-share:hover {
            background: #561d23;
        }

        .btn-revoke-share {
            padding: 5px 10px;
            background: #fff;
            color: #b91c1c;
            border: 1px solid #fca5a5;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-revoke-share:hover {
            background: #fee2e2;
        }

        .btn-copy-share {
            padding: 5px 10px;
            background: #C5A059;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-copy-share:hover {
            background: #ab8841;
        }

        .share-url-input {
            flex: 1;
            min-width: 220px;
            padding: 5px 8px;
            font-size: 11.5px;
            font-family: monospace;
            border: 1px solid #C5A059;
            border-radius: 4px;
            background: #FFF9EE;
            color: #333;
        }

        .order-item-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 16px;
            font-size: 13px;
        }

        .detail-cell span {
            display: block;
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 2px;
        }

        .detail-cell strong {
            color: var(--text-dark);
            font-weight: 600;
        }

        /* Gallery Widget */
        .order-gallery-container {
            margin-top: 14px;
            background: #fdfaf7;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
        }

        .order-gallery-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 6px;
            margin-bottom: 10px;
        }

        .gallery-grid {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .gallery-card {
            position: relative;
            width: 110px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .gallery-thumb {
            width: 100%;
            height: 85px;
            object-fit: cover;
            display: block;
        }

        .gallery-caption {
            padding: 4px 6px;
            font-size: 10px;
            color: var(--text-dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            background: #fff;
        }

        .gallery-delete-btn {
            position: absolute;
            top: 4px;
            right: 4px;
            background: rgba(197, 48, 48, 0.85);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s ease;
        }

        .gallery-delete-btn:hover {
            background: #c53030;
        }

        .gallery-upload-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            padding-top: 8px;
            border-top: 1px dashed var(--border);
            margin-top: 6px;
        }

        .gallery-upload-form input[type="file"] {
            font-size: 11.5px;
            flex: 1;
            min-width: 160px;
        }

        .gallery-upload-form input[type="text"] {
            font-size: 11.5px;
            padding: 5px 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            flex: 1;
            min-width: 140px;
        }

        .btn-upload-photo {
            padding: 6px 12px;
            background: var(--gold);
            color: #ffffff;
            border: none;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-upload-photo:hover {
            background: #9e6c38;
        }

        /* Controls / Forms */
        .order-item-controls {
            background: #faf6f2;
            border-radius: 8px;
            padding: 14px 16px;
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .control-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
            flex: 1;
            min-width: 280px;
        }

        .control-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 140px;
        }

        .control-field label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .control-field select,
        .control-field input {
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 12.5px;
            background: #ffffff;
            font-family: inherit;
        }

        .btn-update-status {
            padding: 8px 16px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-update-status:hover {
            background: var(--primary-dark);
        }

        .status-history-box {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px dashed var(--border);
            font-size: 12px;
            color: var(--text-muted);
        }

        .admin-footer {
            text-align: center;
            padding: 24px;
            font-size: 13px;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
            background: #ffffff;
        }

        @media (max-width: 768px) {
            .admin-metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .order-item-controls {
                flex-direction: column;
                align-items: stretch;
            }
        }

        @media (max-width: 480px) {
            .admin-metrics-grid {
                grid-template-columns: 1fr;
            }
            .admin-modules-nav {
                padding: 0 14px;
            }
        }
    </style>
</head>
<body>

    <header class="admin-nav">
        <a href="index.php" class="admin-brand">
            <h1>Shagun Ladies Tailor</h1>
            <span class="admin-tag">Atelier Console</span>
        </a>
        <div class="admin-nav-actions">
            <a href="../orders.php" class="admin-nav-link" target="_blank">Storefront Orders</a>
            <a href="../logout.php" class="btn-logout">Logout</a>
        </div>
    </header>

    <?php require_once __DIR__ . '/modules/navigation/admin_nav.php'; ?>

    <main class="admin-main">

        <?php if ($flashSuccess): ?>
            <div class="flash-alert flash-success">
                ✓ <?php echo htmlspecialchars($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="flash-alert flash-error">
                ⚠ <?php echo htmlspecialchars($flashError); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($unknownStatusOrders)): ?>
            <div class="flash-alert flash-warning" role="alert" style="background: #fff3cd; border: 1px solid #ffeeba; color: #856404; margin-bottom: 24px;">
                <strong>⚠️ Attention Required — <?php echo count($unknownStatusOrders); ?> Order(s) with Unrecognized Status:</strong>
                <p style="margin-top: 4px; font-size: 13px;">
                    The following order(s) have an unrecognized status in the database and have been excluded from the canonical production lifecycle sections to prevent incorrect production tracking. Please review and update them:
                </p>
                <ul style="margin: 8px 0 0 20px; font-size: 13px;">
                    <?php foreach ($unknownStatusOrders as $uRef => $uOrd): ?>
                        <li>
                            <strong><?php echo htmlspecialchars((string)$uRef); ?></strong> &bull;
                            Database Status: <code><?php echo htmlspecialchars((string)($uOrd['status'] ?? 'unknown')); ?></code> &bull;
                            Customer: <?php echo htmlspecialchars((string)($uOrd['customer_name'] ?? 'Unknown')); ?> &bull;
                            Amount: ₹<?php echo number_format((float)($uOrd['total_amount'] ?? 0), 2); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- ADMIN SESSION PROFILE CARD -->
        <div class="admin-card">
            <div class="admin-card-header">
                <div class="admin-title-area">
                    <h2>Shagun Admin Dashboard</h2>
                    <p>Protected atelier administration &bull; Logged in as <strong><?php echo $fullName; ?></strong> (@<?php echo $username; ?>) &bull; Role: <strong><?php echo $adminRoleLabel; ?></strong></p>
                </div>
                <div class="status-badge">
                    Authentication Successful &bull; Active
                </div>
            </div>

            <!-- METRIC STATS -->
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
        </div>

        <!-- 4 CANONICAL LIFECYCLE ORDER SECTIONS -->
        <?php foreach ($sections as $sectionKey => $section): ?>
            <div class="admin-section-block" id="section-<?php echo str_replace('_', '-', htmlspecialchars($sectionKey)); ?>" data-status-section="<?php echo htmlspecialchars($sectionKey); ?>">
                <div class="admin-section-header">
                    <div class="admin-section-title">
                        <span><?php echo htmlspecialchars($section['title']); ?></span>
                        <span class="section-count-badge"><?php echo count($section['orders']); ?></span>
                    </div>
                </div>
                <p class="admin-section-desc"><?php echo htmlspecialchars($section['desc']); ?></p>

                <?php if (empty($section['orders'])): ?>
                    <div class="section-empty">
                        No orders currently in this stage.
                    </div>
                <?php else: ?>
                    <?php foreach ($section['orders'] as $ref => $ord): ?>
                        <?php
                        $curStatus = strtolower(trim((string) ($ord['status'] ?? ($ord['production_status'] ?? 'pending_confirmation'))));
                        $canonicalStatus = $ord['canonical_status'] ?? get_canonical_status($curStatus);
                        $badgeClass = get_status_badge_class($canonicalStatus);
                        $statusLabel = get_status_display_label($curStatus);

                        $totalAmt = (float) ($ord['total_amount'] ?? ($ord['grand_total'] ?? 0));
                        $paidAmt = (float) ($ord['amount_paid'] ?? ($ord['advance_amount'] ?? 0));
                        $balanceAmt = (float) ($ord['remaining_balance'] ?? ($ord['balance_amount'] ?? max(0.0, $totalAmt - $paidAmt)));
                        $paymentStatus = $ord['payment_status'] ?? (($paidAmt >= $totalAmt && $totalAmt > 0) ? 'fully_paid' : (($paidAmt > 0) ? 'partially_paid' : 'unpaid'));

                        $bookedDate = $ord['booked_date'] ?? date('Y-m-d');
                        $reqDate = $ord['requested_ready_date'] ?? ($ord['wedding_date'] ?? 'Not specified');
                        $delDate = $ord['admin_delivery_date'] ?? $reqDate;
                        $history = $ord['status_history'] ?? [];

                        $custName = $ord['customer_name'] ?? null;
                        $custPhone = $ord['customer_phone'] ?? null;
                        $custEmail = $ord['customer_email'] ?? null;
                        $custAddress = $ord['customer_address'] ?? null;
                        $phoneDisplay = $ord['phone_display'] ?? ($custPhone ?: 'Phone number not provided');
                        $addressDisplay = $ord['address_display'] ?? ($custAddress ?: 'Address not provided');
                        $nameDisplay = $custName ?: (!empty($ord['user_id']) ? 'Customer' : 'Customer account not linked');
                        $emailDisplay = $custEmail ?: 'Email not provided';

                        $custSeq = (int)($ord['customer_sequence'] ?? 1);
                        $custSeqLabel = $ord['customer_sequence_label'] ?? get_customer_sequence_label($custSeq);

                        $intakePhotos = $ord['gallery_photos']['awaiting_confirmation'] ?? [];
                        $finishedPhotos = $ord['gallery_photos']['completed'] ?? [];
                        ?>
                        <div class="order-item-card" id="manage-order-<?php echo htmlspecialchars($ref); ?>">
                            <div class="order-item-top">
                                <div>
                                    <span class="order-item-ref"><?php echo htmlspecialchars($ref); ?></span>
                                    <span style="font-size: 12.5px; color: var(--text-muted); margin-left: 8px;">
                                        <?php echo htmlspecialchars($ord['order_type'] ?? ($ord['occasion'] ?? 'Tailoring Order')); ?>
                                    </span>
                                </div>
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <a href="index.php?action=download_dossier&ref=<?php echo urlencode($ref); ?>" class="btn-download-dossier" target="_blank" id="download-dossier-<?php echo htmlspecialchars($ref); ?>">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        Download Order Dossier PDF
                                    </a>
                                    <?php if ($canonicalStatus === 'completed' || $canonicalStatus === 'delivered'): ?>
                                        <?php if (count($finishedPhotos) >= 1): ?>
                                            <a href="index.php?action=download_completion_dossier&ref=<?php echo urlencode($ref); ?>" class="btn-download-dossier btn-completion-dossier" target="_blank" id="download-completion-dossier-<?php echo htmlspecialchars($ref); ?>">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                                Download Completion Dossier PDF
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-download-dossier btn-disabled" title="Upload at least 1 completed garment photo to generate Completion Dossier">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                                                Completion Dossier (Photo Required)
                                            </span>
                                            <span class="completion-warning-pill" title="Upload at least 1 completed photo below to unlock dossier">
                                                ⚠ Photo Required
                                            </span>
                                        <?php endif; ?>
                                        <?php 
                                        $custPhoneClean = trim((string)($ord['customer_phone'] ?? ''));
                                        $hasValidPhone = !empty(format_whatsapp_phone($custPhoneClean));
                                        ?>
                                        <?php if ($hasValidPhone): ?>
                                            <a href="index.php?action=whatsapp_share&ref=<?php echo urlencode($ref); ?>" class="btn-whatsapp-share" target="_blank" rel="noopener noreferrer" id="whatsapp-share-<?php echo htmlspecialchars($ref); ?>" title="Share completion update with customer via WhatsApp">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                                Share Completion Update
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-whatsapp-share btn-disabled" title="Customer phone number not available for WhatsApp">
                                                Share Completion Update
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="btn-download-dossier btn-disabled" title="Completion Dossier becomes available when order reaches Completed or Delivered status">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                            Completion Dossier (Locked)
                                        </span>
                                    <?php endif; ?>
                                    <span class="order-badge-pill <?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (($canonicalStatus === 'completed' || $canonicalStatus === 'delivered') && count($finishedPhotos) >= 1): ?>
                                <?php
                                $hasActiveShare = !empty($ord['active_share']);
                                $lastGenShareUrl = $_SESSION['last_generated_share'][$ref] ?? null;
                                ?>
                                <div class="share-link-bar" id="share-bar-<?php echo htmlspecialchars($ref); ?>">
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <span style="font-weight: 600; color: #722F37; display: flex; align-items: center; gap: 4px;">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                                            Customer Share Link:
                                        </span>
                                        <?php if ($lastGenShareUrl): ?>
                                            <input type="text" readonly value="<?php echo htmlspecialchars($lastGenShareUrl); ?>" class="share-url-input" id="share-input-<?php echo htmlspecialchars($ref); ?>">
                                            <button type="button" class="btn-copy-share" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($lastGenShareUrl, ENT_QUOTES); ?>'); this.innerText = 'Copied!'; setTimeout(() => this.innerText = 'Copy Link', 2000);">Copy Link</button>
                                        <?php elseif ($hasActiveShare): ?>
                                            <?php 
                                            $expDate = date('d M Y', strtotime((string)$ord['active_share']['expires_at'])); 
                                            $accCount = (int)($ord['active_share']['access_count'] ?? 0);
                                            ?>
                                            <span style="color: #166534; font-weight: 500;">
                                                Active &bull; Expires: <?php echo $expDate; ?> &bull; Accessed: <?php echo $accCount; ?> time(s)
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #64748b;">No active share link generated yet</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; gap: 6px; align-items: center;">
                                        <form method="POST" style="display: inline; margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="generate_share_link">
                                            <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                            <button type="submit" class="<?php echo ($hasActiveShare || $lastGenShareUrl) ? 'btn-regen-share' : 'btn-create-share'; ?>" id="btn-generate-share-<?php echo htmlspecialchars($ref); ?>" title="Generate a fresh 30-day expiring share link">
                                                <?php echo ($hasActiveShare || $lastGenShareUrl) ? '↻ Regenerate Link' : '+ Generate Share Link'; ?>
                                            </button>
                                        </form>
                                        <?php if ($hasActiveShare || $lastGenShareUrl): ?>
                                            <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('Are you sure you want to revoke this share link? The customer will no longer be able to access the dossier via this link.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="revoke_share_link">
                                                <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                                <button type="submit" class="btn-revoke-share" id="btn-revoke-share-<?php echo htmlspecialchars($ref); ?>" title="Revoke access immediately">
                                                    Revoke Link
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- CUSTOMER INFORMATION SECTION -->
                            <div class="order-customer-section">
                                <div class="customer-info-header">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    <span>Customer Information</span>
                                </div>
                                <div class="customer-info-grid">
                                    <div class="customer-cell">
                                        <span class="cell-label">Customer Name</span>
                                        <strong class="cell-val">
                                            <?php echo htmlspecialchars($nameDisplay); ?>
                                            <span class="cust-seq-pill <?php echo ($custSeq > 1) ? 'is-returning' : ''; ?>">
                                                <?php echo htmlspecialchars($custSeqLabel); ?>
                                            </span>
                                        </strong>
                                    </div>
                                    <div class="customer-cell">
                                        <span class="cell-label">Phone Number</span>
                                        <strong class="cell-val"><?php echo htmlspecialchars($phoneDisplay); ?></strong>
                                    </div>
                                    <div class="customer-cell">
                                        <span class="cell-label">Email Address</span>
                                        <strong class="cell-val"><?php echo htmlspecialchars($emailDisplay); ?></strong>
                                    </div>
                                    <div class="customer-cell address-cell">
                                        <span class="cell-label">Delivery Address</span>
                                        <strong class="cell-val"><?php echo htmlspecialchars($addressDisplay); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <!-- TIMELINE & FINANCIAL DETAILS -->
                            <div class="order-item-details-grid">
                                <div class="detail-cell">
                                    <span>Booked Date</span>
                                    <strong><?php echo date('d M Y', strtotime((string)$bookedDate)); ?></strong>
                                </div>
                                <div class="detail-cell">
                                    <span>Requested Date</span>
                                    <strong><?php echo $reqDate !== 'Not specified' ? date('d M Y', strtotime((string)$reqDate)) : 'Not specified'; ?></strong>
                                </div>
                                <div class="detail-cell">
                                    <span>Admin Delivery Date</span>
                                    <strong style="color: var(--gold);"><?php echo $delDate !== 'Not specified' ? date('d M Y', strtotime((string)$delDate)) : 'Not specified'; ?></strong>
                                </div>
                                <div class="detail-cell">
                                    <span>Financials</span>
                                    <strong>₹<?php echo number_format((int)$totalAmt); ?> (Paid: ₹<?php echo number_format((int)$paidAmt); ?>)</strong>
                                    <div style="margin-top: 4px; display: flex; gap: 6px; align-items: center;">
                                        <span class="pay-status-pill <?php echo $paymentStatus; ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $paymentStatus)); ?>
                                        </span>
                                        <?php if ($balanceAmt > 0): ?>
                                            <span style="font-size: 11px; color: #b91c1c; font-weight: 600;">Due: ₹<?php echo number_format((int)$balanceAmt); ?></span>
                                        <?php else: ?>
                                            <span style="font-size: 11px; color: #166534; font-weight: 600;">Cleared</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- ORDER GALLERY: INTAKE PHOTOS (AWAITING CONFIRMATION & LATER REFERENCE) -->
                            <?php if ($canonicalStatus === 'awaiting_confirmation' || !empty($intakePhotos)): ?>
                                <div class="order-gallery-container">
                                    <div class="order-gallery-header">
                                        <span>Intake Photos (Max 3)</span>
                                        <span style="color: var(--text-muted); font-size: 10px;"><?php echo count($intakePhotos); ?> / 3 uploaded</span>
                                    </div>
                                    <div class="gallery-grid">
                                        <?php foreach ($intakePhotos as $photo): ?>
                                            <div class="gallery-card">
                                                <a href="../<?php echo htmlspecialchars($photo['photo_url']); ?>" target="_blank">
                                                    <img src="../<?php echo htmlspecialchars($photo['photo_url']); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?? 'Intake photo'); ?>" class="gallery-thumb">
                                                </a>
                                                <?php if (!empty($photo['caption'])): ?>
                                                    <div class="gallery-caption" title="<?php echo htmlspecialchars($photo['caption']); ?>">
                                                        <?php echo htmlspecialchars($photo['caption']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Delete this intake photo?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="admin_delete_order_photo">
                                                    <input type="hidden" name="photo_id" value="<?php echo (int)$photo['id']; ?>">
                                                    <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                                    <button type="submit" class="gallery-delete-btn" title="Delete photo">&times;</button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if ($canonicalStatus === 'awaiting_confirmation' && count($intakePhotos) < 3): ?>
                                        <form method="POST" action="index.php" enctype="multipart/form-data" class="gallery-upload-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="admin_upload_order_photo">
                                            <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                            <input type="hidden" name="stage" value="awaiting_confirmation">
                                            <input type="file" name="gallery_photo" accept="image/jpeg,image/png,image/webp" required>
                                            <input type="text" name="caption" placeholder="Caption (e.g. Fabric roll, client sample)...">
                                            <button type="submit" class="btn-upload-photo">Upload Intake Photo</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- ORDER GALLERY: COMPLETED GARMENT PHOTOS (COMPLETED & DELIVERED REFERENCE) -->
                            <?php if ($canonicalStatus === 'completed' || $canonicalStatus === 'delivered' || !empty($finishedPhotos)): ?>
                                <div class="order-gallery-container" style="margin-top: 10px;">
                                    <div class="order-gallery-header">
                                        <span>Completed Garment Photos (Max 3)</span>
                                        <span style="color: var(--text-muted); font-size: 10px;">
                                            <?php echo count($finishedPhotos); ?> / 3 uploaded
                                            <?php if (count($finishedPhotos) === 0): ?>
                                                &bull; <span style="color: #b45309; font-weight: 600;">At least 1 required for Completion Dossier</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="gallery-grid">
                                        <?php foreach ($finishedPhotos as $photo): ?>
                                            <div class="gallery-card">
                                                <a href="../<?php echo htmlspecialchars($photo['photo_url']); ?>" target="_blank">
                                                    <img src="../<?php echo htmlspecialchars($photo['photo_url']); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?? 'Completed garment'); ?>" class="gallery-thumb">
                                                </a>
                                                <?php if (!empty($photo['caption'])): ?>
                                                    <div class="gallery-caption" title="<?php echo htmlspecialchars($photo['caption']); ?>">
                                                        <?php echo htmlspecialchars($photo['caption']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($canonicalStatus !== 'delivered'): ?>
                                                    <form method="POST" action="index.php" style="display:inline;" onsubmit="return confirm('Delete this completed garment photo?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="admin_delete_order_photo">
                                                        <input type="hidden" name="photo_id" value="<?php echo (int)$photo['id']; ?>">
                                                        <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                                        <button type="submit" class="gallery-delete-btn" title="Delete photo">&times;</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if ($canonicalStatus === 'completed' && count($finishedPhotos) < 3): ?>
                                        <form method="POST" action="index.php" enctype="multipart/form-data" class="gallery-upload-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="admin_upload_order_photo">
                                            <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                            <input type="hidden" name="stage" value="completed">
                                            <input type="file" name="gallery_photo" accept="image/jpeg,image/png,image/webp" required>
                                            <input type="text" name="caption" placeholder="Caption (e.g. Front view, embroidery finish)...">
                                            <button type="submit" class="btn-upload-photo">Upload Completed Garment Photo</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- STATUS & DELIVERY DATE CONTROLS -->
                            <?php if ($canonicalStatus !== 'delivered'): ?>
                                <div class="order-item-controls">
                                    <!-- STATUS UPDATE FORM WITH EXACTLY 4 CANONICAL STATUSES -->
                                    <form method="POST" action="index.php" class="control-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="admin_update_status">
                                        <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                        <div class="control-field">
                                            <label for="status-select-<?php echo htmlspecialchars($ref); ?>">Update Status:</label>
                                            <select name="new_status" id="status-select-<?php echo htmlspecialchars($ref); ?>" required>
                                                <?php foreach (ORDER_LIFECYCLE_STATUSES as $st): ?>
                                                    <option value="<?php echo htmlspecialchars($st); ?>" <?php echo ($st === $canonicalStatus) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars(get_status_display_label($st)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="control-field">
                                            <label for="notes-<?php echo htmlspecialchars($ref); ?>">Admin Note:</label>
                                            <input type="text" name="notes" id="notes-<?php echo htmlspecialchars($ref); ?>" placeholder="Reason for status change...">
                                        </div>
                                        <button type="submit" class="btn-update-status">Save Status</button>
                                    </form>

                                    <!-- DELIVERY DATE UPDATE -->
                                    <form method="POST" action="index.php" class="control-form" style="border-left: 1px solid var(--border); padding-left: 14px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="admin_update_delivery_date">
                                        <input type="hidden" name="order_ref" value="<?php echo htmlspecialchars($ref); ?>">
                                        <div class="control-field">
                                            <label for="del-date-<?php echo htmlspecialchars($ref); ?>">Committed Date:</label>
                                            <input type="date" name="admin_delivery_date" id="del-date-<?php echo htmlspecialchars($ref); ?>" value="<?php echo htmlspecialchars((string)$delDate); ?>" required>
                                        </div>
                                        <div class="control-field">
                                            <label for="del-note-<?php echo htmlspecialchars($ref); ?>">Date Note:</label>
                                            <input type="text" name="admin_note" id="del-note-<?php echo htmlspecialchars($ref); ?>" placeholder="Date change reason...">
                                        </div>
                                        <button type="submit" class="btn-update-status" style="background: #252321;">Update Date</button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <!-- AUDIT HISTORY -->
                            <?php if (!empty($history)): ?>
                                <div class="status-history-box">
                                    <strong>Status History:</strong>
                                    <ul style="margin: 4px 0 0 16px;">
                                        <?php foreach ($history as $h): ?>
                                            <li>
                                                <?php echo htmlspecialchars($h['formatted_created'] ?? date('d M Y')); ?>:
                                                Changed from <em><?php echo htmlspecialchars(get_status_display_label($h['old_status'] ?? '')); ?></em> to
                                                <strong><?php echo htmlspecialchars(get_status_display_label($h['new_status'] ?? '')); ?></strong>
                                                <?php if (!empty($h['notes'])): ?>
                                                    (<?php echo htmlspecialchars($h['notes']); ?>)
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    </main>

    <footer class="admin-footer">
        Shagun Ladies Tailor &copy; <?php echo date('Y'); ?> &bull; Bespoke Bridal & Occasion Tailoring &bull; Atelier Management System
    </footer>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const VALID_STATUSES = ['all', 'awaiting_confirmation', 'stitching_in_process', 'completed', 'delivered'];
        const statusCards = document.querySelectorAll('.metric-card-interactive');
        const sections = {
            'awaiting_confirmation': document.getElementById('section-awaiting-confirmation'),
            'stitching_in_process': document.getElementById('section-stitching-in-process'),
            'completed': document.getElementById('section-completed'),
            'delivered': document.getElementById('section-delivered')
        };

        function applyStatusFilter(targetStatus, shouldScroll = false, pushState = true) {
            if (!VALID_STATUSES.includes(targetStatus)) {
                targetStatus = 'all';
            }

            // 1. Update active styling and aria-pressed on metric cards
            statusCards.forEach(function(card) {
                const cardTarget = card.getAttribute('data-status-target');
                const isActive = (cardTarget === targetStatus);
                card.classList.toggle('is-active', isActive);
                card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            // 2. Display / filter the sections
            if (targetStatus === 'all') {
                Object.values(sections).forEach(function(sec) {
                    if (sec) sec.classList.remove('is-filtered-out');
                });
                if (shouldScroll) {
                    const firstSec = document.getElementById('section-awaiting-confirmation');
                    if (firstSec) {
                        firstSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            } else {
                Object.entries(sections).forEach(function([secKey, sec]) {
                    if (!sec) return;
                    if (secKey === targetStatus) {
                        sec.classList.remove('is-filtered-out');
                        if (shouldScroll) {
                            sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    } else {
                        sec.classList.add('is-filtered-out');
                    }
                });
            }

            // 3. Update URL with history.pushState (never mutates DB)
            if (pushState) {
                try {
                    const url = new URL(window.location);
                    url.searchParams.set('status', targetStatus);
                    window.history.pushState({ status: targetStatus }, '', url.toString());
                } catch (e) {
                    // Safe fallback
                }
            }
        }

        // Attach click and keyboard listeners to all metric cards
        statusCards.forEach(function(card) {
            const target = card.getAttribute('data-status-target');

            card.addEventListener('click', function(e) {
                e.preventDefault();
                applyStatusFilter(target, true, true);
            });

            // Keyboard accessibility: Enter and Space
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    applyStatusFilter(target, true, true);
                }
            });
        });

        // Handle browser back/forward navigation
        window.addEventListener('popstate', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const st = urlParams.get('status') || 'all';
            applyStatusFilter(st, false, false);
        });

        // Initial page load: read and validate URL status parameter
        const initialParams = new URLSearchParams(window.location.search);
        const initialStatus = initialParams.get('status') || 'all';
        applyStatusFilter(initialStatus, false, false);
    });
    </script>

</body>
</html>
