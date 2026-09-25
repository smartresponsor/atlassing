<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$manifestPath = $root . '/config/atlas_behavioral_coverage.yaml';
$outputPath = $root . '/var/coverage/behavioral-ui.json';
$dimensions = ['functional', 'behavioral', 'ui', 'critical'];

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Behavioral coverage manifest is missing.\n");
    exit(1);
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest) || !is_array($manifest['dimensions'] ?? null)) {
    fwrite(STDERR, "Behavioral coverage manifest is invalid.\n");
    exit(1);
}

$uiRoots = ['src/Controller', 'templates', 'assets'];
$uiFiles = [];
foreach ($uiRoots as $relativeRoot) {
    $directory = $root . '/' . $relativeRoot;
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $uiFiles[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }
}

$declaredUi = $manifest['dimensions']['ui'] ?? null;
if ([] !== $uiFiles && ([] === $declaredUi || null === $declaredUi)) {
    fwrite(STDERR, "Interactive UI files exist but the UI coverage inventory is empty.\n");
    foreach ($uiFiles as $file) {
        fwrite(STDERR, " - {$file}\n");
    }
    exit(1);
}

$evidenceDimensions = [];
foreach ($dimensions as $dimension) {
    $entries = $manifest['dimensions'][$dimension] ?? null;
    if (!is_array($entries) || !array_is_list($entries)) {
        fwrite(STDERR, "Dimension {$dimension} must be a list.\n");
        exit(1);
    }

    $eligible = [];
    $covered = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            fwrite(STDERR, "Dimension {$dimension} contains an invalid entry.\n");
            exit(1);
        }

        $id = $entry['id'] ?? null;
        $source = $entry['source'] ?? null;
        $evidence = $entry['evidence'] ?? null;
        $marker = $entry['marker'] ?? null;
        if (!is_string($id) || '' === trim($id) || !is_string($source) || !is_string($evidence) || !is_string($marker)) {
            fwrite(STDERR, "Dimension {$dimension} contains an incomplete entry.\n");
            exit(1);
        }
        if (in_array($id, $eligible, true)) {
            fwrite(STDERR, "Duplicate {$dimension} identifier: {$id}.\n");
            exit(1);
        }

        $eligible[] = $id;
        $sourcePath = $root . '/' . $source;
        $evidencePath = $root . '/' . $evidence;
        if (!is_file($sourcePath) || !is_file($evidencePath)) {
            continue;
        }

        $evidenceText = file_get_contents($evidencePath);
        if (false !== $evidenceText && str_contains($evidenceText, $marker)) {
            $covered[] = $id;
        }
    }

    $evidenceDimensions[$dimension] = [
        'eligible' => $eligible,
        'covered' => $covered,
    ];
}

$output = [
    'schema' => 'behavioral-ui-coverage-v2',
    'generatedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'producer' => [
        'kind' => 'repository_script',
        'script' => 'test:behavioral-coverage',
    ],
    'dimensions' => $evidenceDimensions,
];

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Cannot create behavioral coverage output directory.\n");
    exit(1);
}

file_put_contents($outputPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

foreach ($evidenceDimensions as $dimension => $metric) {
    printf("%s: %d/%d\n", $dimension, count($metric['covered']), count($metric['eligible']));
}
