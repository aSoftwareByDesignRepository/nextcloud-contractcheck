#!/usr/bin/env php
<?php
declare(strict_types=1);
$app = basename(dirname(__DIR__));
$base = __DIR__ . '/../l10n';
foreach (glob($base . '/*.json') as $jsonPath) {
    $lang = basename($jsonPath, '.json');
    $cat = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
    $translations = $cat['translations'] ?? [];
    $lines = ["OC.L10N.register(\n", "\t\"{$app}\",\n", "\t{\n"];
    foreach ($translations as $key => $val) {
        $k = json_encode($key, JSON_UNESCAPED_UNICODE);
        $v = json_encode($val, JSON_UNESCAPED_UNICODE);
        $lines[] = "\t" . $k . ' : ' . $v . ",\n";
    }
    $last = array_pop($lines);
    $lines[] = rtrim($last, ",\n") . "\n\t}\n);\n";
    file_put_contents($base . '/' . $lang . '.js', implode('', $lines));
}
echo "done\n";
