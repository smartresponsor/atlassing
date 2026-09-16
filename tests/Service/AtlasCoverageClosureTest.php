<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DependencyInjection\AtlassingExtension;
use App\Service\Assessment\AtlasPythonEngineService;
use App\Service\Atlas\AtlasDocumentationCoverageService;
use App\Service\Registry\AtlasRepositoryRegistryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AtlasCoverageClosureTest extends TestCase
{
    public function testRegistryAndDocumentationCoverageHandleScalarPayloads(): void
    {
        $root = self::tempDirectory('scalar');
        mkdir($root . '/repository', 0777, true);
        file_put_contents($root . '/repository/ecosystem-repositories.yaml', "scalar\n");

        self::assertSame(
            ['repositories' => []],
            (new AtlasRepositoryRegistryService($root))->loadRegistry(),
        );

        $index = $root . '/index.json';
        file_put_contents($index, json_encode(['articles' => ['scalar']], JSON_THROW_ON_ERROR));
        $summary = (new AtlasDocumentationCoverageService())->summarizeFromDocumentatingIndex($index);

        self::assertTrue($summary['available']);
        self::assertSame(1, $summary['articleCount']);
        self::assertSame(0, $summary['componentCount']);
    }

    public function testPythonEngineExecutesExistingScript(): void
    {
        $root = self::tempDirectory('python');
        $engine = $root . '/engine';
        mkdir($engine, 0777, true);
        file_put_contents($engine . '/ok.py', "print('atlas-ok')\n");

        $result = (new AtlasPythonEngineService($root, $engine))->runScript('ok.py');

        self::assertTrue($result['successful'], $result['errorOutput']);
        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('atlas-ok', $result['output']);
    }

    public function testExtensionLoadsDefaultAndCustomAtlasConfiguration(): void
    {
        $extension = new AtlassingExtension();

        $defaults = new ContainerBuilder();
        $extension->load([], $defaults);
        self::assertSame('atlas_', $defaults->getParameter('atlassing.database_prefix'));
        self::assertTrue($defaults->getParameter('atlassing.standalone'));

        $custom = new ContainerBuilder();
        $extension->load([['atlas' => [
            'database_prefix' => 'custom_',
            'standalone' => false,
        ]]], $custom);
        self::assertSame('custom_', $custom->getParameter('atlassing.database_prefix'));
        self::assertFalse($custom->getParameter('atlassing.standalone'));
    }

    private static function tempDirectory(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/atlassing-' . $suffix . '-' . bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return str_replace('\\', '/', $path);
    }
}
