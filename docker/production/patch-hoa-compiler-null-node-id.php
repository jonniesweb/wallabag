<?php

declare(strict_types=1);

// hoa/compiler 3.17.08.08 calls strpos() with null while loading its grammar.
// PHP 8.2 reports that as a deprecation, which becomes an ErrorException in
// production and interrupts Wallabag's origin-rule evaluation.
$ruleClass = dirname(__DIR__, 2) . '/vendor/hoa/compiler/Llk/Rule/Rule.php';

if (!is_file($ruleClass)) {
    throw new RuntimeException('Expected hoa/compiler Rule.php was not installed.');
}

$source = file_get_contents($ruleClass);

if (false === $source) {
    throw new RuntimeException('Could not read hoa/compiler Rule.php.');
}

$unpatched = "if (false !== \$pos = strpos(\$nodeId, ':')) {";
$patched = "if (null !== \$nodeId && false !== \$pos = strpos(\$nodeId, ':')) {";

if (str_contains($source, $patched)) {
    exit(0);
}

if (1 !== substr_count($source, $unpatched)) {
    throw new RuntimeException('hoa/compiler Rule.php no longer matches the expected source.');
}

$source = str_replace($unpatched, $patched, $source, $replacementCount);

if (1 !== $replacementCount || false === file_put_contents($ruleClass, $source)) {
    throw new RuntimeException('Could not patch hoa/compiler Rule.php.');
}
