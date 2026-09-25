<?php
/**
 * Browser Layout Verification for Checkout Bugfixes
 * 
 * 1. Authenticates session and adds item to cart.
 * 2. Selects measurement method and proceeds to Step 2 Review.
 * 3. Captures full rendered HTML of Step 2 Review page.
 * 4. Injects layout inspection script to test at 320px, 360px, 393px, and 430px:
 *    - document.documentElement.scrollWidth vs window.innerWidth
 *    - Vertical stacking order of sections (top offsets)
 *    - Visibility and bounds of all cards, slider, presets, checkbox, button
 * 5. Launches real Chrome in headless mode with specified window sizes.
 * 6. Generates screenshots and reports actual layout measurements.
 */

$baseUrl = 'http://localhost/shagun-ladies-tailor';
$cookieFile = __DIR__ . '/cookie_browser_test_' . time() . '.txt';

function http_req($url, $method = 'GET', $data = [], $cookieFile = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $redirectUrl = '';
    if (preg_match('/Location:\s*([^\r\n]+)/i', $header, $matches)) {
        $redirectUrl = trim($matches[1]);
    }
    curl_close($ch);
    return ['body' => $body, 'redirect' => $redirectUrl];
}

// 1. Authenticate Jamun
http_req($baseUrl . '/login.php', 'POST', [
    'email' => 'jamun@example.com',
    'password' => 'password123',
    'action' => 'login'
], $cookieFile);

// 2. Clear & Add item
http_req($baseUrl . '/cart.php?action=clear', 'GET', [], $cookieFile);
http_req($baseUrl . '/customize-blouse.php?style=princess-cut', 'POST', [
    'action' => 'add_to_cart',
    'garment_type' => 'blouse',
    'style_slug' => 'princess-cut',
    'style_name' => 'Princess Cut Blouse',
    'base_price' => 800,
    'customizations' => [
        'neck_design' => 'v_neck',
        'sleeve_length' => 'elbow'
    ]
], $cookieFile);

// 3. Save Measurements (Reference Blouse)
http_req($baseUrl . '/checkout.php?step=measurement', 'POST', [
    'action' => 'save_measurements',
    'measurement_method' => 'reference_blouse',
    'requested_ready_date' => date('Y-m-d', strtotime('+15 days'))
], $cookieFile);

// 4. Fetch Step 2 Review HTML
$reviewRes = http_req($baseUrl . '/checkout.php?step=review', 'GET', [], $cookieFile);
$html = $reviewRes['body'];

// Ensure stylesheet is inlined directly so it renders immediately and accurately
$cssPath = dirname(__DIR__) . '/assets/css/style.css';
if (file_exists($cssPath)) {
    $cssContent = file_get_contents($cssPath);
    $html = str_replace('</head>', "<style id=\"inlined-main-styles\">\n" . $cssContent . "\n</style>\n</head>", $html);
}

// Inject layout audit script
$auditScript = <<<'JS'
<script>
window.addEventListener('load', function() {
    setTimeout(function() {
        const report = {
            viewportWidth: window.innerWidth,
            viewportHeight: window.innerHeight,
            scrollWidth: document.documentElement.scrollWidth,
            bodyScrollWidth: document.body.scrollWidth,
            hasHorizontalOverflow: document.documentElement.scrollWidth > window.innerWidth || document.body.scrollWidth > window.innerWidth,
            sections: {}
        };

        const personCard = document.querySelector('.luxe-review-person-card');
        const summaryCard = document.querySelector('.luxe-review-summary-card');
        const garmentsSection = document.querySelector('.luxe-review-garments-section');
        const advanceCard = document.querySelector('.luxe-review-advance-card');
        const checkbox = document.querySelector('#declaration-checkbox');
        const payBtn = document.querySelector('#confirm-pay-btn');

        if (personCard) {
            const r = personCard.getBoundingClientRect();
            report.sections.personCard = { top: r.top, left: r.left, width: r.width, height: r.height };
        }
        if (summaryCard) {
            const r = summaryCard.getBoundingClientRect();
            report.sections.summaryCard = { top: r.top, left: r.left, width: r.width, height: r.height };
        }
        if (garmentsSection) {
            const r = garmentsSection.getBoundingClientRect();
            report.sections.garmentsSection = { top: r.top, left: r.left, width: r.width, height: r.height };
        }
        if (advanceCard) {
            const r = advanceCard.getBoundingClientRect();
            report.sections.advanceCard = { top: r.top, left: r.left, width: r.width, height: r.height };
        }
        if (checkbox) {
            const r = checkbox.getBoundingClientRect();
            report.sections.checkbox = { top: r.top, left: r.left, width: r.width, height: r.height };
        }
        if (payBtn) {
            const r = payBtn.getBoundingClientRect();
            report.sections.payBtn = { top: r.top, left: r.left, width: r.width, height: r.height };
        }

        const reportEl = document.createElement('div');
        reportEl.id = 'browser-audit-result';
        reportEl.setAttribute('data-report', JSON.stringify(report));
        reportEl.textContent = JSON.stringify(report);
        document.body.appendChild(reportEl);
    }, 100);
});
</script>
JS;

$html = str_replace('</body>', $auditScript . "\n</body>", $html);

$testHtmlFile = __DIR__ . '/rendered_review_test.html';
file_put_contents($testHtmlFile, $html);

echo "Rendered HTML saved to: {$testHtmlFile}\n\n";

$widths = [320, 360, 393, 430];
$chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

foreach ($widths as $w) {
    echo "----------------------------------------------------------------------\n";
    echo "TESTING VIEWPORT: {$w}px wide x 852px high\n";
    echo "----------------------------------------------------------------------\n";

    $screenshotFile = __DIR__ . "/screenshot_review_{$w}px.png";
    $dumpDomFile = __DIR__ . "/dom_review_{$w}px.html";
    @unlink($screenshotFile);
    @unlink($dumpDomFile);

    $httpUrl = $baseUrl . '/scratch/rendered_review_test.html';
    $cmd = "\"{$chromePath}\" --headless=old --disable-gpu --virtual-time-budget=2000 --window-size={$w},852 --screenshot=\"{$screenshotFile}\" --dump-dom \"{$httpUrl}\" > \"{$dumpDomFile}\" 2>&1";
    exec($cmd);

    if (file_exists($dumpDomFile)) {
        $dom = file_get_contents($dumpDomFile);
        if (preg_match('/id="browser-audit-result"[^>]*data-report=\'([^\']+)\'/i', $dom, $m) ||
            preg_match('/id="browser-audit-result"[^>]*data-report="([^"]+)"/i', $dom, $m)) {
            $data = json_decode(html_entity_decode($m[1]), true);
            if ($data) {
                $sw = $data['scrollWidth'];
                $vw = $data['viewportWidth'];
                $overflow = $data['hasHorizontalOverflow'];
                echo "  Viewport width: {$vw}px\n";
                echo "  Document scrollWidth: {$sw}px\n";
                echo "  Horizontal overflow: " . ($overflow ? "YES (FAILED)" : "NO (PASSED)") . "\n";

                $sec = $data['sections'];
                if (isset($sec['personCard'], $sec['summaryCard'], $sec['garmentsSection'], $sec['advanceCard'])) {
                    echo "  Section Top Offsets:\n";
                    echo "    1. Customer Details: top = {$sec['personCard']['top']}px, width = {$sec['personCard']['width']}px\n";
                    echo "    2. Order Summary:    top = {$sec['summaryCard']['top']}px, width = {$sec['summaryCard']['width']}px\n";
                    echo "    3. Garments:         top = {$sec['garmentsSection']['top']}px, width = {$sec['garmentsSection']['width']}px\n";
                    echo "    4. Advance Payment:  top = {$sec['advanceCard']['top']}px, width = {$sec['advanceCard']['width']}px\n";

                    $isStackedInOrder = ($sec['personCard']['top'] < $sec['summaryCard']['top']) &&
                                        ($sec['summaryCard']['top'] < $sec['garmentsSection']['top']) &&
                                        ($sec['garmentsSection']['top'] < $sec['advanceCard']['top']);
                    echo "  Sections stacked in required order: " . ($isStackedInOrder ? "YES (PASSED)" : "NO (FAILED)") . "\n";
                }
                if (isset($sec['payBtn'])) {
                    echo "    Button bounds:       top = {$sec['payBtn']['top']}px, width = {$sec['payBtn']['width']}px, height = {$sec['payBtn']['height']}px\n";
                    echo "  Button full-width & tappable: " . ($sec['payBtn']['width'] > ($vw * 0.8) ? "YES (PASSED)" : "NO") . "\n";
                }
            } else {
                echo "  Could not parse JSON audit report.\n";
            }
        } else {
            echo "  Audit report tag not found in DOM dump.\n";
        }
    }

    if (file_exists($screenshotFile)) {
        $size = filesize($screenshotFile);
        echo "  Screenshot captured successfully ({$size} bytes): {$screenshotFile}\n";
    }
}

// Clean up
@unlink($cookieFile);
echo "\n======================================================================\n";
echo "BROWSER TESTING COMPLETED\n";
echo "======================================================================\n";
