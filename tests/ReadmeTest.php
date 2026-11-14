<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tools\Api;
use PolygonLab\PublicApisHealth\Tools\CurlTransport;
use PolygonLab\PublicApisHealth\Tools\DataFiles;
use PolygonLab\PublicApisHealth\Tools\JsonFile;
use PolygonLab\PublicApisHealth\Tools\ListPage;
use PolygonLab\PublicApisHealth\Tools\Readme;
use PolygonLab\PublicApisHealth\Tools\Sources;
use PolygonLab\PublicApisHealth\Tools\TestClass;

/**
 * Le démarrage rapide du README est le script d'exemple, et il produit la sortie annoncée (lancé ici sur une copie
 * locale fabriquée, sans appel réseau) ; les réglages, les classes de test, le crédit de la source et la notice des
 * tiers sont à jour.
 */
final class ReadmeTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private const EXAMPLE = self::ROOT . '/examples/read-the-list.php';

    public function testQuickStartCodeIsTheExampleScript(): void
    {
        self::assertSame(rtrim((string) file_get_contents(self::EXAMPLE), "\n"), self::block('php'));
    }

    public function testQuickStartOutputIsTheExpectedOutput(): void
    {
        $copy = sys_get_temp_dir() . '/public-apis-health-example-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($copy, json_encode([
            'source' => 'public-apis/public-apis',
            'source_page' => 'https://github.com/public-apis/public-apis',
            'source_date' => '2026-11-19',
            'count' => 3,
            'counts' => ['by_category' => ['Animals' => 2, 'Weather' => 1], 'by_test_result' => ['responds' => 2, 'not_found' => 1]],
            'host_count' => 3,
            'apis' => [
                self::exampleApi('Sample Cat Facts', 'https://cats.example.com/', 'Animals', 'No', 'responds', 200),
                self::exampleApi('Sample Dog Facts', 'https://dogs.example.org/api', 'Animals', 'No', 'not_found', 404),
                self::exampleApi('Sample Weather', 'https://weather.example.net/docs', 'Weather', 'apiKey', 'responds', 200),
            ],
        ]));
        try {
            $process = proc_open([PHP_BINARY, self::EXAMPLE, $copy], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        } finally {
            unlink($copy);
        }

        self::assertSame(self::block('text'), rtrim($output, "\n"));
    }

    public function testDirectAddressesTargetThePublishedFiles(): void
    {
        $readme = self::readme();

        foreach ([DataFiles::APIS_JSON, DataFiles::APIS_CSV, DataFiles::HOSTS_JSON, DataFiles::HOSTS_CSV, DataFiles::HISTORY_CSV, DataFiles::RUNS_CSV, DataFiles::SOURCE_JSON] as $file) {
            self::assertStringContainsString('https://raw.githubusercontent.com/polygonlab-ai/public-apis-health/main/data/' . $file, $readme);
            self::assertFileExists(self::ROOT . '/data/' . $file);
        }
        self::assertFileExists(self::ROOT . '/' . ListPage::FILE);
    }

    public function testTheGeneratedBlockHasItsTwoMarkersOnce(): void
    {
        $readme = self::readme();

        self::assertSame(1, substr_count($readme, Readme::START));
        self::assertSame(1, substr_count($readme, Readme::END));
        self::assertStringContainsString(Readme::START . "\nsample\n" . Readme::END, Readme::replace($readme, "\nsample\n"));
    }

    public function testEveryTestResultIsExplained(): void
    {
        $readme = self::readme();

        foreach (TestClass::ALL as $class) {
            self::assertStringContainsString('| `' . $class . '` |', $readme);
            self::assertArrayHasKey($class, TestClass::LABELS);
        }
        foreach (Api::EXPECTED as $values) {
            foreach ($values as $value) {
                self::assertStringContainsString('`' . $value . '`', $readme);
            }
        }
        self::assertStringContainsString('the link of an entry is most often the **documentation page**', $readme);
        self::assertStringContainsString('**not that the API works**', $readme);
        self::assertStringContainsString('once a week, from one single server located in France', $readme);
        self::assertStringContainsString('`' . CurlTransport::USER_AGENT . '`', $readme);
        self::assertSame('PolygonLab-actualisation-donnees (+https://github.com/polygonlab-ai/public-apis-health)', CurlTransport::USER_AGENT);
    }

    public function testTheSettingsTableIsUpToDate(): void
    {
        $readme = self::readme();
        $settings = JsonFile::read(Sources::FILE, 'tools/sources.json');

        self::assertIsArray($settings['test']);
        self::assertIsString($settings['list_url']);
        self::assertStringContainsString('| `list_url` | `' . $settings['list_url'] . '` |', $readme);
        foreach (['attempts', 'timeout_s', 'max_size', 'min_categories', 'max_drop_pct'] as $key) {
            self::assertIsInt($settings[$key]);
            self::assertStringContainsString('| `' . $key . '` | ' . $settings[$key] . ' |', $readme);
        }
        foreach ($settings['test'] as $key => $value) {
            self::assertIsInt($value);
            self::assertStringContainsString('| `test.' . $key . '` | ' . $value . ' |', $readme);
        }
        self::assertSame('collecte', $settings['mode']);
        self::assertTrue($settings['mots_interdits']);
        self::assertSame([ListPage::FILE], $settings['fichiers_generes'], 'la page générée est déclarée au script de publication');
    }

    public function testTheSourceIsCreditedAtTheTopOfTheReadme(): void
    {
        $readme = self::readme();
        $credit = strpos($readme, '## Source and credit');
        $why = strpos($readme, '## Why');

        self::assertIsInt($credit);
        self::assertIsInt($why);
        self::assertLessThan($why, $credit, 'la section du crédit vient en tête, avant toute autre');
        $section = substr($readme, $credit, $why - $credit);
        self::assertStringContainsString('[public-apis/public-apis](https://github.com/public-apis/public-apis)', $section);
        self::assertStringContainsString('MIT licence', $section);
        self::assertStringContainsString('**not affiliated with, nor endorsed by,**', $section);
        self::assertStringContainsString('**the result of a weekly test of each link**', $section);
        self::assertStringContainsString('open a pull request on the' . "\n" . 'original repository, not here', $section);
        self::assertStringContainsString('**PolygonLab does not recommend any API and does not guarantee that they work.**', $section);
        self::assertStringContainsString('[`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md)', $section);
    }

    public function testTheThirdPartyNoticesReproduceTheLicenceOfTheList(): void
    {
        $notices = (string) file_get_contents(self::ROOT . '/THIRD-PARTY-NOTICES.md');
        $licence = (string) file_get_contents(self::ROOT . '/LICENSE');

        self::assertStringContainsString("MIT License\n\nCopyright (c) 2022 public-apis\n\nPermission is hereby granted, free of charge,", $notices);
        self::assertStringContainsString('https://github.com/public-apis/public-apis', $notices);
        // Le texte de la licence MIT est le même que celui du fichier LICENSE du dépôt, à la ligne du titulaire près.
        $start = strpos($licence, 'Permission is hereby granted');
        $end = strpos($licence, 'SOFTWARE.');
        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertStringContainsString(substr($licence, $start, $end - $start + \strlen('SOFTWARE.')), $notices);
        self::assertStringContainsString('Copyright (c) 2026 PolygonLab', $licence);
    }

    public function testNoBackupOrTemporaryFileIsLeftInTheRepository(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/vendor/') || str_contains($path, '/.git/') || str_contains($path, '/.phpunit.cache/')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/\.bak|(?:~|\.orig|\.old|\.swp|\.tmp)\z/', $file->getFilename(), $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function exampleApi(string $name, string $url, string $category, string $auth, string $result, int $code): array
    {
        return [
            'name' => $name,
            'url' => $url,
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'description' => 'Sample entry',
            'category' => $category,
            'auth' => $auth,
            'https' => 'Yes',
            'cors' => 'Unknown',
            'test' => ['result' => $result, 'http_code' => $code, 'tested_on' => '2026-11-19', 'observed_availability' => null],
        ];
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::ROOT . '/README.md');
    }

    /**
     * Contenu du premier bloc de code du README dans ce langage.
     */
    private static function block(string $language): string
    {
        self::assertSame(1, preg_match('/```' . $language . '\n(.*?)\n```/s', self::readme(), $match), 'bloc ' . $language . ' introuvable');

        return $match[1];
    }
}
