<?php
$lines = file('C:/Users/Satyam kumar/.gemini/antigravity/brain/3fa3eb12-7f67-49a8-8e8e-91e9e362ea88/.system_generated/logs/transcript.jsonl');
$lastUserInput = null;
foreach ($lines as $line) {
    $data = json_decode($line, true);
    if ($data && isset($data['type']) && $data['type'] === 'USER_INPUT') {
        $lastUserInput = $data;
    }
}
if ($lastUserInput) {
    echo "Step: " . $lastUserInput['step_index'] . "\n";
    if (isset($lastUserInput['truncated_fields']) && in_array('content', $lastUserInput['truncated_fields'])) {
        echo "TRUNCATED, checking transcript_full.jsonl...\n";
        $fullLines = file('C:/Users/Satyam kumar/.gemini/antigravity/brain/3fa3eb12-7f67-49a8-8e8e-91e9e362ea88/.system_generated/logs/transcript_full.jsonl');
        foreach ($fullLines as $fline) {
            $fdata = json_decode($fline, true);
            if ($fdata && isset($fdata['step_index']) && $fdata['step_index'] === $lastUserInput['step_index']) {
                file_put_contents(__DIR__ . '/last_user_prompt.txt', $fdata['content']);
                echo "Wrote full prompt to last_user_prompt.txt (" . strlen($fdata['content']) . " bytes)\n";
                break;
            }
        }
    } else {
        file_put_contents(__DIR__ . '/last_user_prompt.txt', $lastUserInput['content']);
        echo "Wrote prompt to last_user_prompt.txt (" . strlen($lastUserInput['content']) . " bytes)\n";
    }
} else {
    echo "No user input found.\n";
}
