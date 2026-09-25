<?php
require_once __DIR__ . '/../includes/dossier-pdf.php';

// 1. Test JPEG
$testJpg = __DIR__ . '/test_sample.jpg';
$minimalJpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
file_put_contents($testJpg, $minimalJpg);

// 2. Test PNG
$testPng = __DIR__ . '/test_sample.png';
$minimalPngHex = '89504e470d0a1a0a0000000d4948445200000001000000010802000000907753de0000000c49444154789c63f8cfc000000301010018dd8d600000000049454e44ae426082';
file_put_contents($testPng, hex2bin($minimalPngHex));

$doc = new ShagunPdfDocument(true);
$okJpg = $doc->addImage(50, 100, 100, 100, $testJpg);
$okPng = $doc->addImage(200, 100, 100, 100, $testPng);
echo "JPEG addImage result: " . ($okJpg ? "SUCCESS" : "FAILED") . "\n";
echo "PNG addImage result: " . ($okPng ? "SUCCESS" : "FAILED") . "\n";

$pdf = $doc->renderFinal();
echo "PDF generated length: " . strlen($pdf) . " bytes\n";
assert(strpos($pdf, '%PDF-1.4') === 0);
assert(strpos($pdf, '/DCTDecode') !== false);
assert(strpos($pdf, '/FlateDecode') !== false);
echo "ALL PDF IMAGE ASSERTIONS PASSED!\n";

@unlink($testJpg);
@unlink($testPng);
