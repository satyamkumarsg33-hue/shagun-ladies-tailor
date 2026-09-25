<?php
// Minimal 1x1 24-bit RGB PNG:
// 1x1 red pixel
$pngHex = '89504e470d0a1a0a0000000d4948445200000001000000010802000000907753de0000000c49444154789c63f8cfc000000301010018dd8d600000000049454e44ae426082';
$testPng = __DIR__ . '/test_sample.png';
file_put_contents($testPng, hex2bin($pngHex));

$info = getimagesize($testPng);
echo "PNG info: w={$info[0]}, h={$info[1]}, mime={$info['mime']}\n";

// Parse PNG chunks
$fh = fopen($testPng, 'rb');
fread($fh, 8); // Skip signature
$idat = '';
while (!feof($fh)) {
    $lenBytes = fread($fh, 4);
    if (strlen($lenBytes) < 4) break;
    $len = unpack('N', $lenBytes)[1];
    $type = fread($fh, 4);
    $data = fread($fh, $len);
    $crc = fread($fh, 4);
    if ($type === 'IDAT') {
        $idat .= $data;
    }
}
fclose($fh);
echo "IDAT length: " . strlen($idat) . "\n";
@unlink($testPng);
