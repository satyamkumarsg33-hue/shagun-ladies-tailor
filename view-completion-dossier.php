<?php
/**
 * Shagun Ladies Tailor — Public Completion Dossier Viewer
 * 
 * Secure, capability-based public endpoint for viewing order Completion Dossiers:
 * - Validates 64-hex bearer token against SHA-256 hash in database
 * - Validates non-revocation, expiration date, order status, and completed photos
 * - Updates access count and last accessed timestamp
 * - Streams PDF binary directly to the browser (inline viewing or download)
 * - Renders branded, friendly error page if token is invalid, expired, or revoked
 * - Does not require customer login or session, preventing mobile auth roadblocks
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/document-shares.php';

$rawToken = trim((string)($_GET['token'] ?? ''));
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

if ($rawToken === '') {
    http_response_code(404);
    render_error_page(
        404,
        'Document Link Missing',
        'No access token was provided. Please use the complete link provided by Shagun Ladies Tailor.'
    );
    exit;
}

try {
    $pdo = get_db_connection();
    $result = validate_order_document_share_token($pdo, $rawToken);

    if (!$result['valid']) {
        http_response_code($result['http_code'] ?? 404);
        render_error_page(
            $result['http_code'] ?? 404,
            $result['error_title'] ?? 'Access Denied',
            $result['error_message'] ?? 'This document link cannot be accessed.'
        );
        exit;
    }

    $filePath = (string)$result['file_path'];
    $fileName = (string)$result['file_name'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        render_error_page(
            404,
            'File Not Found',
            'The requested Completion Dossier PDF is currently unavailable. Please contact the workshop.'
        );
        exit;
    }

    // Stream PDF
    if (ob_get_level()) {
        ob_end_clean();
    }

    $disposition = $isDownload ? 'attachment' : 'inline';

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
    header('Content-Length: ' . (string)filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    readfile($filePath);
    exit;

} catch (\Throwable $e) {
    error_log('[Completion Dossier Viewer Error] ' . $e->getMessage());
    http_response_code(500);
    render_error_page(
        500,
        'Server Error',
        'An error occurred while loading your Completion Dossier. Please try again shortly or contact the atelier.'
    );
    exit;
}

/**
 * Render a branded, friendly HTML error page with database-backed shop details.
 */
function render_error_page(int $statusCode, string $title, string $message): void {
    $shop = [
        'shop_name' => 'Shagun Ladies Tailor',
        'shop_phone' => '',
        'shop_email' => '',
        'shop_address' => '',
        'shop_hours' => ''
    ];

    try {
        $pdo = get_db_connection();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM shop_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($settings)) {
            $addrParts = array_filter([
                $settings['shop_address_line'] ?? '',
                $settings['shop_city'] ?? '',
                $settings['shop_state'] ?? '',
                $settings['shop_postal_code'] ?? ''
            ]);
            $shop = [
                'shop_name' => (string)($settings['shop_name'] ?? 'Shagun Ladies Tailor'),
                'shop_phone' => trim((string)($settings['shop_phone'] ?? '')),
                'shop_email' => trim((string)($settings['shop_email'] ?? '')),
                'shop_address' => !empty($addrParts) ? implode(', ', $addrParts) : '',
                'shop_hours' => trim((string)($settings['shop_operating_hours'] ?? ''))
            ];
        }
    } catch (\Throwable $e) {
        error_log('[Viewer Shop Settings Error] ' . $e->getMessage());
    }

    $shopPhone = $shop['shop_phone'] !== '' ? $shop['shop_phone'] : 'Contact details unavailable';
    $shopAddress = $shop['shop_address'] !== '' ? $shop['shop_address'] : 'Contact details unavailable';
    $shopEmail = $shop['shop_email'];
    $shopHours = $shop['shop_hours'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> — <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --burgundy: #722F37;
            --burgundy-dark: #4A1D24;
            --gold: #C5A059;
            --gold-light: #F4E8D1;
            --cream: #FAF8F5;
            --text-dark: #2C2C2C;
            --text-muted: #666666;
            --border: #E8E2D9;
            --card-bg: #FFFFFF;
            --danger: #B91C1C;
            --danger-bg: #FEF2F2;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--cream);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .container {
            max-width: 520px;
            width: 100%;
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border);
            overflow: hidden;
            text-align: center;
        }
        .header-bar {
            background: linear-gradient(135deg, var(--burgundy) 0%, var(--burgundy-dark) 100%);
            padding: 28px 24px;
            color: #FFFFFF;
        }
        .logo-title {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #FFFFFF;
        }
        .logo-subtitle {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            color: var(--gold-light);
            margin-top: 4px;
            font-weight: 500;
        }
        .body-content {
            padding: 36px 28px;
        }
        .icon-circle {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background-color: var(--danger-bg);
            color: var(--danger);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .error-code {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--danger);
            margin-bottom: 8px;
        }
        h1 {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            color: var(--text-dark);
            margin-bottom: 12px;
            font-weight: 600;
        }
        p {
            font-size: 14.5px;
            line-height: 1.6;
            color: var(--text-muted);
            margin-bottom: 24px;
        }
        .contact-box {
            background: #FBF9F6;
            border: 1px dashed var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: left;
        }
        .contact-box-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--burgundy);
            margin-bottom: 8px;
        }
        .contact-detail {
            font-size: 13.5px;
            color: var(--text-dark);
            line-height: 1.5;
        }
        .contact-detail a {
            color: var(--burgundy);
            font-weight: 600;
            text-decoration: none;
        }
        .contact-detail a:hover {
            text-decoration: underline;
        }
        .btn-home {
            display: inline-block;
            background-color: var(--burgundy);
            color: #FFFFFF;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.2s ease;
        }
        .btn-home:hover {
            background-color: var(--burgundy-dark);
        }
        .footer-text {
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <div class="logo-title"><?php echo htmlspecialchars($shop['shop_name']); ?></div>
            <div class="logo-subtitle">Haute Couture Atelier & Bespoke Tailoring</div>
        </div>
        <div class="body-content">
            <div class="icon-circle">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <div class="error-code">Notice &bull; <?php echo (int)$statusCode; ?></div>
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <p><?php echo htmlspecialchars($message); ?></p>

            <div class="contact-box">
                <div class="contact-box-title">Need Assistance? Contact Workshop</div>
                <div class="contact-detail">
                    <strong>Phone / WhatsApp:</strong> 
                    <?php if ($shop['shop_phone'] !== ''): ?>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^\d+]/', '', $shop['shop_phone'])); ?>">
                            <?php echo htmlspecialchars($shop['shop_phone']); ?>
                        </a>
                    <?php else: ?>
                        <span>Contact details unavailable</span>
                    <?php endif; ?><br>
                    <?php if ($shopEmail !== ''): ?>
                        <strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($shopEmail); ?>"><?php echo htmlspecialchars($shopEmail); ?></a><br>
                    <?php endif; ?>
                    <strong>Atelier:</strong> <?php echo htmlspecialchars($shopAddress); ?><br>
                    <?php if ($shopHours !== ''): ?>
                        <strong>Hours:</strong> <?php echo htmlspecialchars($shopHours); ?>
                    <?php endif; ?>
                </div>
            </div>

            <a href="index.php" class="btn-home">Visit <?php echo htmlspecialchars($shop['shop_name']); ?></a>
        </div>
    </div>
    <div class="footer-text">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($shop['shop_name']); ?>. All rights reserved.
    </div>
</body>
</html>
    <?php
}
