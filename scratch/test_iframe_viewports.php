<?php
/**
 * Test responsive review page in headless Chrome using exact-width iframes
 * to simulate physical mobile screens: 320px, 360px, 393px, 430px.
 */

$baseUrl = 'http://localhost/shagun-ladies-tailor';
$chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
$cookieFile = __DIR__ . '/cookie_iframe_test_' . time() . '.txt';

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

// Inline style.css
$cssPath = dirname(__DIR__) . '/assets/css/style.css';
if (file_exists($cssPath)) {
    $cssContent = file_get_contents($cssPath);
    $html = str_replace('</head>', "<style id=\"inlined-main-styles\">\n" . $cssContent . "\n</style>\n</head>", $html);
}

$testHtmlFile = __DIR__ . '/rendered_review_test.html';
file_put_contents($testHtmlFile, $html);
@unlink($cookieFile);

$widths = [320, 360, 393, 430];

foreach ($widths as $w) {
    $harnessHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { margin: 0; padding: 0; background: #222; }
  iframe { border: none; width: {$w}px; height: 852px; display: block; }
</style>
</head>
<body>
<iframe id="frame" src="rendered_review_test.html"></iframe>
<script>
const frame = document.getElementById('frame');
frame.onload = function() {
    setTimeout(function() {
        const doc = frame.contentDocument;
        const win = frame.contentWindow;
        
        // Find all elements overflowing viewport
        const overflowing = [];
        doc.querySelectorAll('*').forEach(el => {
            const r = el.getBoundingClientRect();
            if (r.right > win.innerWidth + 1 || el.scrollWidth > win.innerWidth + 1) {
                overflowing.push({
                    tag: el.tagName,
                    id: el.id,
                    className: el.className,
                    width: r.width,
                    right: r.right,
                    scrollWidth: el.scrollWidth
                });
            }
        });

        const report = {
            viewportWidth: win.innerWidth,
            viewportHeight: win.innerHeight,
            scrollWidth: doc.documentElement.scrollWidth,
            bodyScrollWidth: doc.body.scrollWidth,
            hasHorizontalOverflow: doc.documentElement.scrollWidth > win.innerWidth || doc.body.scrollWidth > win.innerWidth,
            overflowingCount: overflowing.length,
            overflowingElements: overflowing.slice(0, 10),
            sections: {}
        };

        const personCard = doc.querySelector('.luxe-review-person-card');
        const summaryCard = doc.querySelector('.luxe-review-summary-card');
        const garmentsSection = doc.querySelector('.luxe-review-garments-section');
        const advanceCard = doc.querySelector('.luxe-review-advance-card');
        const checkbox = doc.querySelector('#declaration-checkbox');
        const payBtn = doc.querySelector('#confirm-pay-btn');

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
        reportEl.id = 'iframe-audit-result';
        reportEl.setAttribute('data-report', JSON.stringify(report));
        reportEl.textContent = JSON.stringify(report);
        document.body.appendChild(reportEl);
    }, 200);
};
</script>
</body>
</html>
HTML;

    $harnessFile = __DIR__ . "/harness_{$w}px.html";
    file_put_contents($harnessFile, $harnessHtml);

    $dumpFile = __DIR__ . "/iframe_dump_{$w}px.html";
    $shotFile = __DIR__ . "/iframe_shot_{$w}px.png";
    @unlink($dumpFile);
    @unlink($shotFile);

    $harnessUrl = $baseUrl . "/scratch/harness_{$w}px.html";
    $cmd = "\"{$chromePath}\" --headless=new --disable-gpu --virtual-time-budget=3000 --window-size=1200,1000 --screenshot=\"{$shotFile}\" --dump-dom \"{$harnessUrl}\" > \"{$dumpFile}\" 2>&1";
    exec($cmd);

    echo "======================================================================\n";
    echo "EXACT IFRAME VIEWPORT TEST: {$w}px x 852px\n";
    echo "======================================================================\n";

    if (file_exists($dumpFile)) {
        $dom = file_get_contents($dumpFile);
        if (preg_match('/id="iframe-audit-result"[^>]*data-report=\'([^\']+)\'/i', $dom, $m) ||
            preg_match('/id="iframe-audit-result"[^>]*data-report="([^"]+)"/i', $dom, $m)) {
            $data = json_decode(html_entity_decode($m[1]), true);
            if ($data) {
                echo "  Inner Viewport Width: {$data['viewportWidth']}px\n";
                echo "  Document scrollWidth: {$data['scrollWidth']}px\n";
                echo "  Body scrollWidth:     {$data['bodyScrollWidth']}px\n";
                echo "  Horizontal Overflow:  " . ($data['hasHorizontalOverflow'] ? "YES (FAILED)" : "NO (PASSED)") . "\n";
                if (!empty($data['overflowingElements'])) {
                    echo "  Top Overflowing Elements:\n";
                    foreach ($data['overflowingElements'] as $idx => $el) {
                        echo "    [{$idx}] <{$el['tag']} id='{$el['id']}' class='{$el['className']}'> width={$el['width']}px, right={$el['right']}px, scrollWidth={$el['scrollWidth']}px\n";
                    }
                }
                
                $sec = $data['sections'];
                if (isset($sec['personCard'], $sec['summaryCard'], $sec['garmentsSection'], $sec['advanceCard'])) {
                    echo "  Section Positions (Top Offsets):\n";
                    echo "    1. Customer Details: top = {$sec['personCard']['top']}px, width = {$sec['personCard']['width']}px\n";
                    echo "    2. Order Summary:    top = {$sec['summaryCard']['top']}px, width = {$sec['summaryCard']['width']}px\n";
                    echo "    3. Garments:         top = {$sec['garmentsSection']['top']}px, width = {$sec['garmentsSection']['width']}px\n";
                    echo "    4. Advance Payment:  top = {$sec['advanceCard']['top']}px, width = {$sec['advanceCard']['width']}px\n";

                    $isStackedInOrder = ($sec['personCard']['top'] < $sec['summaryCard']['top']) &&
                                        ($sec['summaryCard']['top'] < $sec['garmentsSection']['top']) &&
                                        ($sec['garmentsSection']['top'] < $sec['advanceCard']['top']);
                    echo "  Stacked in Required Order: " . ($isStackedInOrder ? "YES (PASSED)" : "NO (FAILED)") . "\n";
                }
                if (isset($sec['payBtn'])) {
                    echo "    Payment Button:      width = {$sec['payBtn']['width']}px, height = {$sec['payBtn']['height']}px\n";
                    echo "    Button Tappable & Full-width: " . ($sec['payBtn']['width'] >= ($data['viewportWidth'] * 0.75) && $sec['payBtn']['height'] >= 44 ? "YES (PASSED)" : "NO") . "\n";
                }
            } else {
                echo "  Failed to decode JSON report.\n";
            }
        } else {
            echo "  iframe-audit-result not found in DOM dump.\n";
        }
    }
}
