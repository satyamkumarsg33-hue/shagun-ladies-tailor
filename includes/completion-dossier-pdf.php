<?php
/**
 * Shagun Ladies Tailor — Completion Dossier PDF Engine
 * 
 * Generates the official production-ready Completion Dossier PDF:
 * "SHAGUN — Completion Dossier" ("Official Garment Fulfillment Record")
 * 
 * Features:
 * - Pure PHP 1.4 compliant vector PDF generator using ShagunPdfDocument
 * - Header with atelier contact details from shop_settings database table
 * - Customer profile & sequence classification (New Customer, 2nd Time Customer, etc.)
 * - Order lifecycle status (Completed / Delivered) & timeline dates
 * - Complete physical garment specifications, customizations, work items, and materials
 * - Financial settlement summary (Total, Paid, Balance, Payment Status)
 * - 1 to 3 Completed Garment Photographs with preserved aspect ratio (strict exclusion of intake photos)
 * - Running headers & footers with "Page X of Y" and official fulfillment notice
 * - Secure versioned indexing in order_documents table and storage in uploads/dossiers/
 */

declare(strict_types=1);

require_once __DIR__ . '/dossier-pdf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/order-status.php';

class ShagunCompletionDossierPdf {

    /**
     * Fetch atelier shop settings from permanent database.
     */
    public static function getShopSettings(): array {
        $defaults = [
            'shop_name' => 'Shagun Ladies Tailor',
            'shop_tagline' => 'Bespoke Bridal & Occasion Tailoring',
            'shop_address_line' => 'Velankanni Road, Electronic City Phase 1',
            'shop_city' => 'Bengaluru',
            'shop_state' => 'Karnataka',
            'shop_postal_code' => '560100',
            'shop_phone' => '+91 7019179423',
            'shop_email' => 'orders@shagunladiestailor.com',
            'shop_operating_hours' => 'Mon-Sat: 10:00 AM - 8:30 PM, Sun: 11:00 AM - 6:00 PM'
        ];

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM shop_settings");
            $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($dbSettings) && is_array($dbSettings)) {
                return array_merge($defaults, $dbSettings);
            }
        } catch (\Throwable $e) {
            error_log('[Completion Dossier Shop Settings Error] ' . $e->getMessage());
        }

        return $defaults;
    }

    /**
     * Generate the Completion Dossier PDF binary string.
     * 
     * @param array $orderData Order details, customer info, payments, garments, and completed photos
     * @return string Raw PDF 1.4 binary
     */
    public static function generate(array $orderData): string {
        $shop = self::getShopSettings();

        $orderRef = (string)($orderData['order_ref'] ?? 'LT-ORDER');
        $rawBooked = $orderData['booked_date'] ?? date('Y-m-d');
        $rawReq = $orderData['requested_ready_date'] ?? $rawBooked;
        $rawDel = $orderData['admin_delivery_date'] ?? $rawReq;

        $bookedDate = strtotime((string)$rawBooked) ? date('d F Y', strtotime((string)$rawBooked)) : (string)$rawBooked;
        $requestedDate = strtotime((string)$rawReq) ? date('d F Y', strtotime((string)$rawReq)) : (string)$rawReq;
        $deliveryDate = strtotime((string)$rawDel) ? date('d F Y', strtotime((string)$rawDel)) : (string)$rawDel;
        $generationDate = date('d F Y');

        $orderId = (int)($orderData['id'] ?? 0);
        $userId = (int)($orderData['user_id'] ?? 0);

        if (($userId <= 0 || $orderId <= 0) && !empty($orderData['order_ref'])) {
            try {
                $pdo = get_db_connection();
                $refLookupStmt = $pdo->prepare("SELECT id, user_id FROM orders WHERE order_ref = :ref LIMIT 1");
                $refLookupStmt->execute([':ref' => $orderData['order_ref']]);
                $row = $refLookupStmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if ($orderId <= 0) $orderId = (int)$row['id'];
                    if ($userId <= 0) $userId = (int)$row['user_id'];
                }
            } catch (\Throwable $e) {}
        }

        $custProfile = null;
        if ($userId > 0 && function_exists('get_customer_profile')) {
            $custProfile = get_customer_profile($userId);
        }

        $customerName = trim((string)($orderData['customer_name'] ?? ''));
        if ($customerName === '' || $customerName === 'Customer' || $customerName === 'Valued Customer' || $customerName === 'Customer name not provided') {
            if ($custProfile && !empty($custProfile['name'])) {
                $customerName = trim((string)$custProfile['name']);
            } else {
                $customerName = 'Valued Customer';
            }
        }

        $customerPhone = trim((string)($orderData['customer_phone'] ?? ''));
        if ($customerPhone === '' && $custProfile && !empty($custProfile['phone'])) {
            $customerPhone = trim((string)$custProfile['phone']);
        }

        $customerEmail = trim((string)($orderData['customer_email'] ?? ''));
        if ($customerEmail === '' && $custProfile && !empty($custProfile['email'])) {
            $customerEmail = trim((string)$custProfile['email']);
        }

        $customerAddress = trim((string)($orderData['customer_address'] ?? ''));
        if ($customerAddress === '' && $custProfile && !empty($custProfile['address'])) {
            $customerAddress = trim((string)$custProfile['address']);
        }

        $phoneDisplay = $customerPhone !== '' ? $customerPhone : 'Not provided';
        $emailDisplay = $customerEmail !== '' ? $customerEmail : 'Not provided';
        $addressDisplay = $customerAddress !== '' ? $customerAddress : 'Not provided';

        $custSeq = (int)($orderData['customer_sequence'] ?? 1);
        $custSeqLabel = $orderData['customer_sequence_label'] ?? get_customer_sequence_label($custSeq);

        $canonicalStatus = get_canonical_status((string)($orderData['status'] ?? ($orderData['canonical_status'] ?? '')));
        if ($canonicalStatus !== 'completed' && $canonicalStatus !== 'delivered') {
            throw new \InvalidArgumentException("Completion Dossier is only available for orders with status 'completed' or 'delivered'. Current status: '{$canonicalStatus}'.");
        }
        $statusLabel = get_status_display_label($canonicalStatus);

        $orderType = (string)($orderData['order_type'] ?? 'Bespoke Tailoring');
        $occasion = (string)($orderData['occasion'] ?? '');

        // Financials
        $totalAmt = (float)($orderData['total_amount'] ?? ($orderData['grand_total'] ?? 0));
        $paidAmt = (float)($orderData['amount_paid'] ?? ($orderData['advance_amount'] ?? 0));
        $balanceAmt = (float)($orderData['remaining_balance'] ?? ($orderData['balance_amount'] ?? max(0.0, $totalAmt - $paidAmt)));
        $paymentStatus = (string)($orderData['payment_status'] ?? (($paidAmt >= $totalAmt && $totalAmt > 0) ? 'fully_paid' : (($paidAmt > 0) ? 'partially_paid' : 'unpaid')));

        // Intake photos (stage = awaiting_confirmation, max 3)
        $intakePhotos = $orderData['intake_photos'] ?? null;
        if ($intakePhotos === null && $orderId > 0) {
            try {
                $pdo = get_db_connection();
                $inStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'awaiting_confirmation' ORDER BY created_at ASC LIMIT 3");
                $inStmt->execute([':oid' => $orderId]);
                $intakePhotos = $inStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $intakePhotos = [];
            }
        }
        if (!is_array($intakePhotos)) {
            $intakePhotos = [];
        }

        // Completed photos (stage = completed, 1 to 3 required)
        $completedPhotos = $orderData['completed_photos'] ?? null;
        if ((empty($completedPhotos) || !is_array($completedPhotos)) && $orderId > 0) {
            try {
                $pdo = get_db_connection();
                $compStmt = $pdo->prepare("SELECT * FROM order_gallery_photos WHERE order_id = :oid AND stage = 'completed' ORDER BY created_at ASC LIMIT 3");
                $compStmt->execute([':oid' => $orderId]);
                $completedPhotos = $compStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $completedPhotos = [];
            }
        }
        if (empty($completedPhotos) || !is_array($completedPhotos) || count($completedPhotos) === 0) {
            throw new \InvalidArgumentException("At least one completed garment photo is required to generate the Completion Dossier.");
        }

        // Garments & People
        $people = $orderData['people'] ?? [];
        $standardItems = $orderData['standard_items'] ?? [];
        $customerNotes = trim((string)($orderData['customer_notes'] ?? ($orderData['notes'] ?? '')));

        // Count physical garments
        $totalPhysicalGarments = (int)($orderData['total_physical_garments'] ?? 0);
        if ($totalPhysicalGarments === 0) {
            $stdCount = count($standardItems);
            $luxeCount = 0;
            foreach ($people as $p) {
                if (!empty($p['garments']) && is_array($p['garments'])) {
                    $luxeCount += count($p['garments']);
                }
            }
            $totalPhysicalGarments = $stdCount + $luxeCount;
            if ($totalPhysicalGarments === 0) {
                $totalPhysicalGarments = 1;
            }
        }

        // Initialize PDF Document
        $doc = new ShagunPdfDocument(false);

        // Running Header Callback
        $doc->headerCallback = function (ShagunPdfDocument $d, $pageNum) use ($shop, $orderRef, $generationDate) {
            $left = $d->getMarginLeft();
            $width = $d->getPrintableWidth();
            $right = $left + $width;

            if ($pageNum === 1) {
                // PAGE 1: GRAND COMPLETION HEADER
                $d->text($left, 30, "S H A G U N", 'F5', 20, ShagunPdfDocument::$COLOR_BURGUNDY);
                $d->text($left, 42, "L A D I E S   T A I L O R", 'F4', 9.5, ShagunPdfDocument::$COLOR_BURGUNDY);
                $d->text($left, 52, "OFFICIAL COMPLETION DOSSIER — GARMENT FULFILLMENT RECORD", 'F3', 6.8, ShagunPdfDocument::$COLOR_GOLD);

                // Shop Contact Info from shop_settings
                $shopContact = $shop['shop_phone'] . "  •  " . $shop['shop_email'];
                $shopAddr = $shop['shop_address_line'] . ", " . $shop['shop_city'] . " " . $shop['shop_postal_code'];
                $d->textRight($right, 30, $shopContact, 'F1', 7.2, ShagunPdfDocument::$COLOR_MUTED);
                $d->textRight($right, 38, $shopAddr, 'F1', 7, ShagunPdfDocument::$COLOR_MUTED);
                $d->textRight($right, 48, "ORDER REF: " . $orderRef, 'F2', 9, ShagunPdfDocument::$COLOR_BURGUNDY);
                $d->textRight($right, 56, "COMPLETED ON: " . $generationDate, 'F1', 7.5, ShagunPdfDocument::$COLOR_DARK);

                // Decorative Divider
                $d->line($left, 62, $right, 62, ShagunPdfDocument::$COLOR_BURGUNDY, 1.0);
                $d->line($left, 64, $right, 64, ShagunPdfDocument::$COLOR_GOLD, 0.5);
            } else {
                // PAGE 2+: COMPACT RUNNING HEADER
                $d->text($left, 24, "SHAGUN LADIES TAILOR  •  COMPLETION DOSSIER  •  REF: " . $orderRef, 'F2', 8, ShagunPdfDocument::$COLOR_BURGUNDY);
                $d->textRight($right, 24, "PAGE " . $pageNum, 'F1', 8, ShagunPdfDocument::$COLOR_MUTED);
                $d->line($left, 28, $right, 28, ShagunPdfDocument::$COLOR_DIVIDER, 0.5);
            }
        };

        // Running Footer Callback
        $doc->footerCallback = function (ShagunPdfDocument $d, $pageNum, $pageCount) use ($orderRef, $generationDate) {
            $left = $d->getMarginLeft();
            $width = $d->getPrintableWidth();
            $right = $left + $width;
            $y = 808.0;

            $d->line($left, $y, $right, $y, ShagunPdfDocument::$COLOR_DIVIDER, 0.5);
            $d->text($left, $y + 11, "Shagun Ladies Tailor • Atelier Fulfillment Record • Order #" . $orderRef, 'F1', 6.8, ShagunPdfDocument::$COLOR_MUTED);
            $d->textCenter($left + ($width / 2), $y + 11, "Generated on " . $generationDate . " from workshop database", 'F3', 6.5, ShagunPdfDocument::$COLOR_MUTED);
            $d->textRight($right, $y + 11, "Page " . $pageNum . " of " . $pageCount, 'F2', 7, ShagunPdfDocument::$COLOR_BURGUNDY);
        };

        // Add first page
        $doc->addPage();
        $left = $doc->getMarginLeft();
        $width = $doc->getPrintableWidth();
        $right = $left + $width;

        // -------------------------------------------------------------
        // SECTION 1: OVERVIEW CARDS (Customer & Order Metadata)
        // -------------------------------------------------------------
        $doc->checkPageBreak(85);
        $cardW = ($width - 12) / 2;
        $cardH = 82;
        $topY = $doc->getY();

        // Card 1: Customer Details
        $doc->roundedRect($left, $topY, $cardW, $cardH, 4, 'B', ShagunPdfDocument::$COLOR_CARD_BG, ShagunPdfDocument::$COLOR_BORDER, 0.5);
        $doc->rect($left, $topY, $cardW, 18, 'F', ShagunPdfDocument::$COLOR_LIGHT_BURGUNDY);
        $doc->drawIcon('user', $left + 8, $topY + 5, 8, ShagunPdfDocument::$COLOR_BURGUNDY);
        $doc->text($left + 22, $topY + 12, "CUSTOMER INFORMATION", 'F2', 7.8, ShagunPdfDocument::$COLOR_BURGUNDY);

        // Sequence badge inside header
        $doc->roundedRect($left + $cardW - 75, $topY + 3, 68, 12, 2, 'F', ShagunPdfDocument::$COLOR_LIGHT_GOLD);
        $doc->textCenter($left + $cardW - 41, $topY + 11, $custSeqLabel, 'F2', 6.2, ShagunPdfDocument::$COLOR_GOLD);

        $doc->text($left + 10, $topY + 30, "Full Name:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->text($left + 65, $topY + 30, $customerName, 'F2', 8.2, ShagunPdfDocument::$COLOR_DARK);

        $doc->text($left + 10, $topY + 43, "Phone:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->text($left + 65, $topY + 43, $phoneDisplay, 'F1', 7.8, ShagunPdfDocument::$COLOR_DARK);

        $doc->text($left + 10, $topY + 56, "Email:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->text($left + 65, $topY + 56, $emailDisplay, 'F1', 7.5, ShagunPdfDocument::$COLOR_DARK);

        $doc->text($left + 10, $topY + 69, "Address:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $addrTrunc = strlen($addressDisplay) > 55 ? substr($addressDisplay, 0, 52) . '...' : $addressDisplay;
        $doc->text($left + 65, $topY + 69, $addrTrunc, 'F1', 7.2, ShagunPdfDocument::$COLOR_DARK);

        // Card 2: Order & Fulfillment Details
        $card2X = $left + $cardW + 12;
        $doc->roundedRect($card2X, $topY, $cardW, $cardH, 4, 'B', ShagunPdfDocument::$COLOR_CARD_BG, ShagunPdfDocument::$COLOR_BORDER, 0.5);
        $doc->rect($card2X, $topY, $cardW, 18, 'F', ShagunPdfDocument::$COLOR_LIGHT_BURGUNDY);
        $doc->drawIcon('package', $card2X + 8, $topY + 5, 8, ShagunPdfDocument::$COLOR_BURGUNDY);
        $doc->text($card2X + 22, $topY + 12, "FULFILLMENT SUMMARY", 'F2', 7.8, ShagunPdfDocument::$COLOR_BURGUNDY);

        // Status pill
        $doc->roundedRect($card2X + $cardW - 65, $topY + 3, 58, 12, 2, 'F', ShagunPdfDocument::$COLOR_LIGHT_GREEN);
        $doc->textCenter($card2X + $cardW - 36, $topY + 11, strtoupper($statusLabel), 'F2', 6.5, ShagunPdfDocument::$COLOR_GREEN);

        $doc->text($card2X + 10, $topY + 30, "Order Ref:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->text($card2X + 70, $topY + 30, $orderRef . ($occasion ? " ({$occasion})" : ""), 'F2', 8, ShagunPdfDocument::$COLOR_DARK);

        $doc->text($card2X + 10, $topY + 43, "Booked Date:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->text($card2X + 70, $topY + 43, $bookedDate, 'F1', 7.8, ShagunPdfDocument::$COLOR_DARK);

        $doc->text($card2X + 10, $topY + 56, "Delivery Date:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->text($card2X + 70, $topY + 56, $deliveryDate, 'F2', 8, ShagunPdfDocument::$COLOR_BURGUNDY);

        $doc->text($card2X + 10, $topY + 69, "Total Garments:", 'F2', 7.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->text($card2X + 70, $topY + 69, "{$totalPhysicalGarments} Physical Garment(s)", 'F2', 7.8, ShagunPdfDocument::$COLOR_DARK);

        $doc->setY($topY + $cardH + 12);

        // -------------------------------------------------------------
        // SECTION 2: FINANCIAL SUMMARY BAR
        // -------------------------------------------------------------
        $doc->checkPageBreak(50);
        $finY = $doc->getY();
        $doc->roundedRect($left, $finY, $width, 38, 4, 'B', ShagunPdfDocument::$COLOR_CARD_ALT, ShagunPdfDocument::$COLOR_BORDER, 0.5);

        $colW = $width / 4;
        // Col 1: Total
        $doc->textCenter($left + ($colW * 0.5), $finY + 13, "TOTAL ORDER VALUE", 'F2', 6.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->textCenter($left + ($colW * 0.5), $finY + 27, "Rs. " . number_format((int)$totalAmt), 'F5', 11, ShagunPdfDocument::$COLOR_BURGUNDY);
        $doc->line($left + $colW, $finY + 6, $left + $colW, $finY + 32, ShagunPdfDocument::$COLOR_DIVIDER, 0.5);

        // Col 2: Paid
        $doc->textCenter($left + ($colW * 1.5), $finY + 13, "AMOUNT PAID", 'F2', 6.5, ShagunPdfDocument::$COLOR_MUTED);
        $doc->textCenter($left + ($colW * 1.5), $finY + 27, "Rs. " . number_format((int)$paidAmt), 'F5', 11, ShagunPdfDocument::$COLOR_GREEN);
        $doc->line($left + ($colW * 2), $finY + 6, $left + ($colW * 2), $finY + 32, ShagunPdfDocument::$COLOR_DIVIDER, 0.5);

        // Col 3: Balance
        $doc->textCenter($left + ($colW * 2.5), $finY + 13, "BALANCE DUE", 'F2', 6.5, ShagunPdfDocument::$COLOR_MUTED);
        $balanceColor = $balanceAmt > 0 ? ShagunPdfDocument::$COLOR_BURGUNDY : ShagunPdfDocument::$COLOR_GREEN;
        $balanceText = $balanceAmt > 0 ? "Rs. " . number_format((int)$balanceAmt) : "Cleared (Rs. 0)";
        $doc->textCenter($left + ($colW * 2.5), $finY + 27, $balanceText, 'F5', 11, $balanceColor);
        $doc->line($left + ($colW * 3), $finY + 6, $left + ($colW * 3), $finY + 32, ShagunPdfDocument::$COLOR_DIVIDER, 0.5);

        // Col 4: Payment Status
        $doc->textCenter($left + ($colW * 3.5), $finY + 13, "SETTLEMENT STATUS", 'F2', 6.5, ShagunPdfDocument::$COLOR_MUTED);
        $payLabel = match ($paymentStatus) {
            'fully_paid' => 'Fully Paid',
            'partially_paid' => 'Partially Paid',
            default => 'Unpaid'
        };
        $payStatusColor = match ($paymentStatus) {
            'fully_paid' => ShagunPdfDocument::$COLOR_GREEN,
            'partially_paid' => ShagunPdfDocument::$COLOR_GOLD,
            default => ShagunPdfDocument::$COLOR_BURGUNDY
        };
        $doc->textCenter($left + ($colW * 3.5), $finY + 27, $payLabel, 'F2', 9, $payStatusColor);

        $doc->setY($finY + 50);

        // -------------------------------------------------------------
        // SECTION 3A: INTAKE / RECEIVED GARMENT PHOTOS (0 TO 3 IMAGES)
        // -------------------------------------------------------------
        if (!empty($intakePhotos) && is_array($intakePhotos)) {
            $inPhotoCount = min(3, count($intakePhotos));
            $inGalleryH = ($inPhotoCount === 1) ? 175 : 145;

            $doc->checkPageBreak($inGalleryH + 30);
            $inY = $doc->getY();

            // Section Header
            $doc->rect($left, $inY, $width, 16, 'F', ShagunPdfDocument::$COLOR_BURGUNDY);
            $doc->drawIcon('camera', $left + 8, $inY + 4, 8, ShagunPdfDocument::$COLOR_WHITE);
            $doc->text($left + 22, $inY + 11, "INTAKE / RECEIVED GARMENT PHOTOS (" . $inPhotoCount . " of 3)", 'F2', 7.8, ShagunPdfDocument::$COLOR_WHITE);
            $doc->textRight($right - 8, $inY + 11, "ORIGINAL SPECIMEN PROOF", 'F3', 6.8, ShagunPdfDocument::$COLOR_LIGHT_GOLD);

            $inY += 22;
            $gap = 12;
            $colWidth = ($width - ($gap * ($inPhotoCount - 1))) / $inPhotoCount;
            $boxHeight = ($inPhotoCount === 1) ? 135 : 105;

            $pIdx = 0;
            foreach ($intakePhotos as $photo) {
                if ($pIdx >= 3) break;

                $boxX = $left + ($pIdx * ($colWidth + $gap));
                $doc->roundedRect($boxX, $inY, $colWidth, $boxHeight, 3, 'B', ShagunPdfDocument::$COLOR_CARD_BG, ShagunPdfDocument::$COLOR_BORDER, 0.5);

                $photoRelPath = ltrim((string)$photo['photo_url'], '/\\');
                $photoFullPath = dirname(__DIR__) . '/' . $photoRelPath;

                if (file_exists($photoFullPath) && is_file($photoFullPath)) {
                    $imgInfo = @getimagesize($photoFullPath);
                    if ($imgInfo && $imgInfo[0] > 0 && $imgInfo[1] > 0) {
                        $origW = $imgInfo[0];
                        $origH = $imgInfo[1];
                        $aspect = $origW / $origH;

                        // Target dimensions inside card with 4pt margin
                        $availW = $colWidth - 8;
                        $availH = $boxHeight - 24; // reserve space for caption

                        if ($aspect >= ($availW / $availH)) {
                            $drawW = $availW;
                            $drawH = $availW / $aspect;
                        } else {
                            $drawH = $availH;
                            $drawW = $availH * $aspect;
                        }

                        $drawX = $boxX + 4 + (($availW - $drawW) / 2);
                        $drawY = $inY + 4 + (($availH - $drawH) / 2);

                        $doc->addImage($drawX, $drawY, $drawW, $drawH, $photoFullPath);
                    }
                }

                // Caption Box at bottom of card
                $captionY = $inY + $boxHeight - 16;
                $doc->rect($boxX + 0.5, $captionY, $colWidth - 1, 15.5, 'F', ShagunPdfDocument::$COLOR_CARD_ALT);
                $doc->line($boxX, $captionY, $boxX + $colWidth, $captionY, ShagunPdfDocument::$COLOR_BORDER, 0.5);

                $caption = trim((string)($photo['caption'] ?? ''));
                if ($caption === '') {
                    $caption = "Intake Specimen #" . ($pIdx + 1);
                }
                $maxCapLen = ($inPhotoCount === 1) ? 65 : (($inPhotoCount === 2) ? 45 : 32);
                if (strlen($caption) > $maxCapLen) {
                    $caption = substr($caption, 0, $maxCapLen - 2) . '...';
                }
                $doc->textCenter($boxX + ($colWidth / 2), $captionY + 11, $caption, 'F2', 6.5, ShagunPdfDocument::$COLOR_DARK);

                $pIdx++;
            }

            $doc->setY($inY + $boxHeight + 14);
        }

        // -------------------------------------------------------------
        // SECTION 3B: COMPLETED GARMENT PHOTOGRAPHS (1 TO 3 IMAGES)
        // -------------------------------------------------------------
        if (!empty($completedPhotos)) {
            // Calculate needed height for photos
            $photoCount = min(3, count($completedPhotos));
            $galleryH = ($photoCount === 1) ? 175 : 145;

            $doc->checkPageBreak($galleryH + 30);
            $gY = $doc->getY();

            // Section Header
            $doc->rect($left, $gY, $width, 16, 'F', ShagunPdfDocument::$COLOR_BURGUNDY);
            $doc->drawIcon('camera', $left + 8, $gY + 4, 8, ShagunPdfDocument::$COLOR_WHITE);
            $doc->text($left + 22, $gY + 11, "COMPLETED GARMENT PHOTOGRAPHS (" . $photoCount . " of 3)", 'F2', 7.8, ShagunPdfDocument::$COLOR_WHITE);
            $doc->textRight($right - 8, $gY + 11, "ATELIER FINISHING PROOF", 'F3', 6.8, ShagunPdfDocument::$COLOR_LIGHT_GOLD);

            $gY += 22;
            $gap = 12;
            $colWidth = ($width - ($gap * ($photoCount - 1))) / $photoCount;
            $boxHeight = ($photoCount === 1) ? 135 : 105;

            $pIdx = 0;
            foreach ($completedPhotos as $photo) {
                if ($pIdx >= 3) break;

                $boxX = $left + ($pIdx * ($colWidth + $gap));
                $doc->roundedRect($boxX, $gY, $colWidth, $boxHeight, 3, 'B', ShagunPdfDocument::$COLOR_CARD_BG, ShagunPdfDocument::$COLOR_BORDER, 0.5);

                $photoRelPath = ltrim((string)$photo['photo_url'], '/\\');
                $photoFullPath = dirname(__DIR__) . '/' . $photoRelPath;

                if (file_exists($photoFullPath) && is_file($photoFullPath)) {
                    $imgInfo = @getimagesize($photoFullPath);
                    if ($imgInfo && $imgInfo[0] > 0 && $imgInfo[1] > 0) {
                        $origW = $imgInfo[0];
                        $origH = $imgInfo[1];
                        $aspect = $origW / $origH;

                        // Target dimensions inside card with 4pt margin
                        $availW = $colWidth - 8;
                        $availH = $boxHeight - 24; // reserve space for caption

                        if ($aspect >= ($availW / $availH)) {
                            $drawW = $availW;
                            $drawH = $availW / $aspect;
                        } else {
                            $drawH = $availH;
                            $drawW = $availH * $aspect;
                        }

                        $drawX = $boxX + 4 + (($availW - $drawW) / 2);
                        $drawY = $gY + 4 + (($availH - $drawH) / 2);

                        $doc->addImage($drawX, $drawY, $drawW, $drawH, $photoFullPath);
                    }
                }

                // Caption Box at bottom of card
                $captionY = $gY + $boxHeight - 16;
                $doc->rect($boxX + 0.5, $captionY, $colWidth - 1, 15.5, 'F', ShagunPdfDocument::$COLOR_CARD_ALT);
                $doc->line($boxX, $captionY, $boxX + $colWidth, $captionY, ShagunPdfDocument::$COLOR_BORDER, 0.5);

                $caption = trim((string)($photo['caption'] ?? ''));
                if ($caption === '') {
                    $caption = "Completed Garment #" . ($pIdx + 1);
                }
                $maxCapLen = ($photoCount === 1) ? 65 : (($photoCount === 2) ? 45 : 32);
                if (strlen($caption) > $maxCapLen) {
                    $caption = substr($caption, 0, $maxCapLen - 2) . '...';
                }
                $doc->textCenter($boxX + ($colWidth / 2), $captionY + 11, $caption, 'F2', 6.5, ShagunPdfDocument::$COLOR_DARK);

                $pIdx++;
            }

            $doc->setY($gY + $boxHeight + 14);
        }

        // -------------------------------------------------------------
        // SECTION 4: PHYSICAL GARMENT SPECIFICATIONS BREAKDOWN
        // -------------------------------------------------------------
        $doc->checkPageBreak(60);
        $garmentY = $doc->getY();

        $doc->rect($left, $garmentY, $width, 16, 'F', ShagunPdfDocument::$COLOR_BURGUNDY);
        $doc->drawIcon('dress', $left + 8, $garmentY + 4, 8, ShagunPdfDocument::$COLOR_WHITE);
        $doc->text($left + 22, $garmentY + 11, "GARMENT & TAILORING SPECIFICATIONS", 'F2', 7.8, ShagunPdfDocument::$COLOR_WHITE);
        $doc->textRight($right - 8, $garmentY + 11, "AUTHENTIC ATELIER CRAFT", 'F3', 6.8, ShagunPdfDocument::$COLOR_LIGHT_GOLD);

        $doc->setY($garmentY + 22);

        // Render Luxe People & Garments
        $renderedCount = 0;
        if (!empty($people) && is_array($people)) {
            foreach ($people as $personIdx => $person) {
                $pName = (string)($person['name'] ?? 'Customer');
                $pRole = (string)($person['role'] ?? 'Member');
                $pMeasure = (string)($person['measurement_method'] ?? 'visit_shop');
                $garments = $person['garments'] ?? [];

                if (empty($garments) || !is_array($garments)) continue;

                foreach ($garments as $gIdx => $g) {
                    $renderedCount++;
                    $doc->checkPageBreak(55);

                    $curY = $doc->getY();
                    $doc->roundedRect($left, $curY, $width, 46, 3, 'B', ShagunPdfDocument::$COLOR_CARD_BG, ShagunPdfDocument::$COLOR_BORDER, 0.5);

                    // Header strip for garment
                    $doc->rect($left, $curY, $width, 14, 'F', ShagunPdfDocument::$COLOR_CARD_ALT);
                    $doc->text($left + 8, $curY + 10, "GARMENT #{$renderedCount}: " . strtoupper((string)($g['name'] ?? 'Blouse')) . " — " . ((string)($g['style_name'] ?? 'Custom Style')), 'F2', 7.5, ShagunPdfDocument::$COLOR_BURGUNDY);
                    $doc->textRight($right - 8, $curY + 10, "PERSON: {$pName} ({$pRole})", 'F2', 7, ShagunPdfDocument::$COLOR_GOLD);

                    // Details row 1
                    $doc->text($left + 8, $curY + 23, "Measurement Method:", 'F2', 7, ShagunPdfDocument::$COLOR_MUTED);
                    $gMeasure = (string)($g['measurement_method'] ?? $pMeasure);
                    $measureLabel = $gMeasure === 'reference_blouse' ? 'Sample Reference Garment Provided' : 'In-Person Workshop Measurement';
                    $doc->text($left + 110, $curY + 23, $measureLabel, 'F1', 7, ShagunPdfDocument::$COLOR_DARK);

                    // Work Details
                    $workType = (string)($g['work_type'] ?? 'no_work');
                    $workLabel = match ($workType) {
                        'machine' => 'Machine Embroidery Work',
                        'hand' => 'Hand Embroidery / Aari Work',
                        'both' => 'Hand & Machine Embroidery Work',
                        default => 'Plain Stitching (No Embroidery)'
                    };
                    $doc->text($left + 8, $curY + 34, "Embroidery Work:", 'F2', 7, ShagunPdfDocument::$COLOR_MUTED);
                    $doc->text($left + 110, $curY + 34, $workLabel, 'F1', 7, ShagunPdfDocument::$COLOR_DARK);

                    // Customizations summary
                    $custSummary = [];
                    if (!empty($g['choice_summary']) && is_array($g['choice_summary'])) {
                        foreach ($g['choice_summary'] as $cs) {
                            $custSummary[] = ($cs['field'] ?? '') . ': ' . ($cs['label'] ?? '');
                        }
                    }
                    $custStr = !empty($custSummary) ? implode(', ', $custSummary) : 'Standard tailored fit';
                    if (strlen($custStr) > 55) {
                        $custStr = substr($custStr, 0, 52) . '...';
                    }
                    $doc->text($left + 8, $curY + 44, "Customizations:", 'F2', 6.8, ShagunPdfDocument::$COLOR_MUTED);
                    $doc->text($left + 110, $curY + 44, $custStr, 'F1', 6.8, ShagunPdfDocument::$COLOR_DARK);

                    $doc->setY($curY + 52);
                }
            }
        }

        // Render Standard Items if present
        if (!empty($standardItems) && is_array($standardItems)) {
            foreach ($standardItems as $stdIdx => $item) {
                $renderedCount++;
                $doc->checkPageBreak(45);

                $curY = $doc->getY();
                $doc->roundedRect($left, $curY, $width, 38, 3, 'B', ShagunPdfDocument::$COLOR_CARD_BG, ShagunPdfDocument::$COLOR_BORDER, 0.5);

                $doc->rect($left, $curY, $width, 14, 'F', ShagunPdfDocument::$COLOR_CARD_ALT);
                $doc->text($left + 8, $curY + 10, "GARMENT #{$renderedCount}: " . strtoupper((string)($item['garment'] ?? 'Standard Garment')) . " — " . ((string)($item['style_name'] ?? 'Daily Stitching')), 'F2', 7.5, ShagunPdfDocument::$COLOR_BURGUNDY);
                $doc->textRight($right - 8, $curY + 10, "CATEGORY: STANDARD STITCHING", 'F2', 7, ShagunPdfDocument::$COLOR_MUTED);

                $doc->text($left + 8, $curY + 24, "Customizations:", 'F2', 7, ShagunPdfDocument::$COLOR_MUTED);
                $doc->text($left + 90, $curY + 24, "Standard custom tailoring with precision measurements", 'F1', 7, ShagunPdfDocument::$COLOR_DARK);

                $doc->setY($curY + 44);
            }
        }

        // Customer Notes if present
        if ($customerNotes !== '') {
            $doc->checkPageBreak(30);
            $noteY = $doc->getY();
            $doc->roundedRect($left, $noteY, $width, 24, 3, 'B', ShagunPdfDocument::$COLOR_CARD_ALT, ShagunPdfDocument::$COLOR_BORDER, 0.5);
            $doc->text($left + 8, $noteY + 10, "CUSTOMER NOTES:", 'F2', 7, ShagunPdfDocument::$COLOR_BURGUNDY);
            $doc->text($left + 100, $noteY + 10, '"' . substr($customerNotes, 0, 75) . '"', 'F3', 7.2, ShagunPdfDocument::$COLOR_DARK);
            $doc->setY($noteY + 30);
        }

        // Quality Sign-off Banner
        $doc->checkPageBreak(36);
        $qY = $doc->getY();
        $doc->roundedRect($left, $qY, $width, 28, 3, 'B', ShagunPdfDocument::$COLOR_LIGHT_BURGUNDY, ShagunPdfDocument::$COLOR_BORDER, 0.5);
        $doc->drawIcon('check', $left + 12, $qY + 8, 10, ShagunPdfDocument::$COLOR_GREEN);
        $doc->text($left + 28, $qY + 12, "QUALITY VERIFIED & FULFILLED", 'F2', 7.5, ShagunPdfDocument::$COLOR_BURGUNDY);
        $doc->text($left + 28, $qY + 20, "Inspected by Master Tailor according to exact boutique specifications.", 'F3', 6.5, ShagunPdfDocument::$COLOR_MUTED);

        $doc->textRight($right - 12, $qY + 12, "Shagun Ladies Tailor", 'F5', 8.5, ShagunPdfDocument::$COLOR_BURGUNDY);
        $doc->textRight($right - 12, $qY + 20, "Atelier Excellence Guaranteed", 'F3', 6.5, ShagunPdfDocument::$COLOR_GOLD);

        return $doc->renderFinal();
    }

    /**
     * Generate, securely store, and record Completion Dossier in database, then stream download.
     * 
     * @param array $orderData
     * @param string|null $overrideFilename
    /**
     * Save generated Completion Dossier to uploads/dossiers/ and record in order_documents.
     *
     * @param array $orderData Order details
     * @param string $generatedBy 'admin_action' | 'customer_action' | 'automated'
     * @return array|null Saved document record or null on failure
     */
    public static function saveToStorage(array $orderData, string $generatedBy = 'automated'): ?array {
        $pdfContent = self::generate($orderData);
        $orderRef = (string)($orderData['order_ref'] ?? 'LT-ORDER');
        $safeRef = preg_replace('/[^a-zA-Z0-9_\-]/', '', $orderRef);
        $filename = "Shagun-Completion-Dossier-{$safeRef}.pdf";

        try {
            $pdo = get_db_connection();
            $orderId = (int)($orderData['id'] ?? 0);
            if ($orderId <= 0 && !empty($orderData['order_ref'])) {
                $chkStmt = $pdo->prepare("SELECT id FROM orders WHERE order_ref = :ref LIMIT 1");
                $chkStmt->execute([':ref' => $orderData['order_ref']]);
                $orderId = (int)$chkStmt->fetchColumn();
            }

            if ($orderId > 0) {
                $checksum = hash('sha256', $pdfContent);
                $fileSize = strlen($pdfContent);

                // Check if an existing document for this order matches the checksum and file exists
                $existStmt = $pdo->prepare("
                    SELECT * FROM order_documents 
                    WHERE order_id = :oid AND document_type = 'completion_dossier' 
                    ORDER BY version DESC LIMIT 1
                ");
                $existStmt->execute([':oid' => $orderId]);
                $existingDoc = $existStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingDoc) {
                    $root = dirname(__DIR__);
                    $existingRelPath = (string)$existingDoc['file_path'];
                    $existingAbsPath = str_starts_with($existingRelPath, '/') || (strlen($existingRelPath) > 1 && $existingRelPath[1] === ':')
                        ? $existingRelPath 
                        : $root . '/' . ltrim($existingRelPath, '/\\');

                    if ($existingDoc['checksum'] === $checksum && file_exists($existingAbsPath)) {
                        // Re-use existing document version without creating duplicate
                        return [
                            'id' => (int)$existingDoc['id'],
                            'document_id' => (int)$existingDoc['id'],
                            'order_id' => $orderId,
                            'document_type' => 'completion_dossier',
                            'version' => (int)$existingDoc['version'],
                            'file_path' => $existingAbsPath,
                            'relative_path' => $existingRelPath,
                            'file_name' => (string)($existingDoc['file_name'] ?? $filename),
                            'file_size_bytes' => (int)$existingDoc['file_size_bytes'],
                            'checksum' => $checksum,
                            'generated_by' => (string)($existingDoc['generated_by'] ?? $generatedBy),
                            'pdf_content' => $pdfContent,
                            'reused' => true
                        ];
                    }
                }

                // Determine next version when content changed or no previous document
                $verStmt = $pdo->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM order_documents WHERE order_id = :oid AND document_type = 'completion_dossier'");
                $verStmt->execute([':oid' => $orderId]);
                $version = (int)$verStmt->fetchColumn();

                $storageDir = dirname(__DIR__) . '/uploads/dossiers';
                if (!is_dir($storageDir)) {
                    mkdir($storageDir, 0755, true);
                }

                $hash = substr($checksum, 0, 12);
                $storageFileName = "completion_dossier_{$orderId}_v{$version}_{$hash}.pdf";
                $storageFullPath = $storageDir . '/' . $storageFileName;
                $relativeStoragePath = 'uploads/dossiers/' . $storageFileName;

                // Save physical file
                file_put_contents($storageFullPath, $pdfContent);

                // Insert into order_documents
                $insDoc = $pdo->prepare("
                    INSERT INTO order_documents 
                    (order_id, document_type, version, file_path, file_name, file_size_bytes, checksum, generated_by, created_at)
                    VALUES (:oid, 'completion_dossier', :ver, :fpath, :fname, :fsize, :csum, :gen, NOW())
                ");
                $insDoc->execute([
                    ':oid' => $orderId,
                    ':ver' => $version,
                    ':fpath' => $relativeStoragePath,
                    ':fname' => $filename,
                    ':fsize' => $fileSize,
                    ':csum' => $checksum,
                    ':gen' => in_array($generatedBy, ['admin_action', 'customer_action', 'automated'], true) ? $generatedBy : 'automated'
                ]);

                $docId = (int)$pdo->lastInsertId();

                return [
                    'id' => $docId,
                    'document_id' => $docId,
                    'order_id' => $orderId,
                    'document_type' => 'completion_dossier',
                    'version' => $version,
                    'file_path' => $storageFullPath,
                    'relative_path' => $relativeStoragePath,
                    'file_name' => $filename,
                    'file_size_bytes' => $fileSize,
                    'checksum' => $checksum,
                    'generated_by' => $generatedBy,
                    'pdf_content' => $pdfContent,
                    'reused' => false
                ];
            }
        } catch (\Throwable $e) {
            error_log('[Completion Dossier Storage Error] ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Generate and stream Completion Dossier directly to client browser for download.
     * 
     * @param array $orderData
     * @param string|null $overrideFilename
     */
    public static function download(array $orderData, ?string $overrideFilename = null): void {
        $genBy = is_admin_logged_in() ? 'admin_action' : 'customer_action';
        $saved = self::saveToStorage($orderData, $genBy);

        $pdfContent = $saved['pdf_content'] ?? self::generate($orderData);
        $orderRef = (string)($orderData['order_ref'] ?? 'LT-ORDER');
        $safeRef = preg_replace('/[^a-zA-Z0-9_\-]/', '', $orderRef);
        $filename = $overrideFilename ?: ($saved['file_name'] ?? "Shagun-Completion-Dossier-{$safeRef}.pdf");

        // Output PDF to browser
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $pdfContent;
        exit;
    }
}
