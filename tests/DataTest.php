<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Workspace;
use PolygonLab\PublicApisHealth\Tools\Api;
use PolygonLab\PublicApisHealth\Tools\Command;
use PolygonLab\PublicApisHealth\Tools\DataFiles;
use PolygonLab\PublicApisHealth\Tools\JsonFile;
use PolygonLab\PublicApisHealth\Tools\ListPage;
use PolygonLab\PublicApisHealth\Tools\Readme;
use PolygonLab\PublicApisHealth\Tools\Screening;
use PolygonLab\PublicApisHealth\Tools\Sources;
use PolygonLab\PublicApisHealth\Tools\TestClass;
use PolygonLab\PublicApisHealth\Tools\Text;
use PolygonLab\PublicApisHealth\Tools\Updater;

/**
 * Les fichiers publiés de data/ : conformes aux schémas, cohérents entre eux, avec le README et avec LIST.md, sans
 * adresse e-mail ni adresse IPv4. Rejoué par l'intégration continue à chaque publication.
 */
final class DataTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private const DATA = self::ROOT . '/data';

    private const SCHEMAS = [DataFiles::APIS_JSON => self::ROOT . '/schema/apis.schema.json', DataFiles::HOSTS_JSON => self::ROOT . '/schema/hosts.schema.json'];

    public function testPublishedFilesMatchTheirSchemas(): void
    {
        foreach (self::SCHEMAS as $file => $schema) {
            self::assertSchemaValid((string) file_get_contents(self::DATA . '/' . $file), $schema);
        }
    }

    public function testGeneratedFilesMatchTheirSchemas(): void
    {
        $workspace = new Workspace();
        try {
            $process = proc_open([PHP_BINARY, self::ROOT . '/tools/actualiser.php', '--replay', Workspace::LIST, '--sources', $workspace->settings, '--data', $workspace->data, '--readme', $workspace->readme, '--list', $workspace->list, '--forbidden-words', $workspace->forbiddenWords], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(Command::SUCCESS, proc_close($process), $output);
            $generated = [DataFiles::APIS_JSON => $workspace->read(DataFiles::APIS_JSON), DataFiles::HOSTS_JSON => $workspace->read(DataFiles::HOSTS_JSON)];
        } finally {
            $workspace->remove();
        }

        $empty = Updater::emptyFiles(Sources::load());
        foreach (self::SCHEMAS as $file => $schema) {
            self::assertStringContainsString('"result": "responds"', $generated[$file]);
            self::assertSchemaValid($generated[$file], $schema);
            self::assertSchemaValid($empty[$file], $schema);
        }
    }

    public function testASchemaRefusesAnUnknownTestResult(): void
    {
        $list = (string) json_encode(['source' => 'x', 'source_page' => 'https://www.example.com', 'source_date' => null, 'count' => 1, 'hosts' => [['host' => 'docs.example.com', 'api_count' => 1, 'categories' => ['Sample'], 'result' => 'perfect', 'responding_count' => 0]]]);
        $validator = new Validator();
        $data = json_decode($list);
        $validator->validate($data, json_decode((string) file_get_contents(self::SCHEMAS[DataFiles::HOSTS_JSON])), Constraint::CHECK_MODE_NORMAL);

        self::assertFalse($validator->isValid());
    }

    public function testPublishedFilesAreConsistent(): void
    {
        $apis = DataFiles::readApis((string) file_get_contents(self::DATA . '/' . DataFiles::APIS_JSON));
        $list = JsonFile::read(self::DATA . '/' . DataFiles::APIS_JSON, 'apis.json');
        $hosts = JsonFile::read(self::DATA . '/' . DataFiles::HOSTS_JSON, 'hosts.json');
        $source = JsonFile::read(self::DATA . '/' . DataFiles::SOURCE_JSON, 'source.json');
        $date = \is_string($list['source_date']) ? $list['source_date'] : null;
        $ordered = array_values($apis);

        // Le JSON est trié par catégorie, nom puis adresse ; les compteurs additionnent le total.
        self::assertSame(array_map(static fn(Api $api): string => $api->url, DataFiles::sorted($ordered)), array_map(strval(...), array_keys($apis)));
        self::assertSame(\count($apis), $list['count']);
        self::assertIsArray($list['counts']);
        self::assertIsArray($list['counts']['by_category']);
        self::assertIsArray($list['counts']['by_test_result']);
        self::assertSame($list['count'], array_sum($list['counts']['by_category']));
        self::assertSame($list['count'], array_sum($list['counts']['by_test_result']));
        self::assertSame(TestClass::ALL, array_keys($list['counts']['by_test_result']));
        self::assertSame(array_map(strval(...), array_keys(DataFiles::categories($ordered))), array_map(strval(...), array_keys($list['counts']['by_category'])));

        // La lecture par hôte est celle des mêmes entrées.
        self::assertSame(DataFiles::hosts($ordered), $hosts['hosts']);
        self::assertSame(\count(DataFiles::hosts($ordered)), $list['host_count']);
        self::assertSame($list['host_count'], $hosts['count']);
        self::assertSame(DataFiles::hostsCsv($ordered), file_get_contents(self::DATA . '/' . DataFiles::HOSTS_CSV));

        // Le CSV est le rendu exact du JSON.
        $availability = [];
        self::assertIsArray($list['apis']);
        foreach ($list['apis'] as $record) {
            self::assertIsArray($record);
            self::assertIsString($record['url']);
            self::assertIsArray($record['test']);
            $observed = $record['test']['observed_availability'];
            if (\is_float($observed) || \is_int($observed)) {
                $availability[$record['url']] = (float) $observed;
            }
        }
        self::assertSame(DataFiles::apisCsv($date, $ordered, $availability), file_get_contents(self::DATA . '/' . DataFiles::APIS_CSV));

        // La source et les hôtes portent la même date ; une seule entrée, celle de la liste, une fois les données
        // remplies.
        self::assertSame($list['source_date'], $source['source_date']);
        self::assertSame($list['source_date'], $hosts['source_date']);
        self::assertIsArray($source['fichiers']);
        if ($apis !== []) {
            self::assertSame(['list'], array_column($source['fichiers'], 'cle'));
            self::assertSame([Sources::load()->listUrl], array_column($source['fichiers'], 'url'));
            foreach ([...array_column($source['fichiers'], 'sha256'), ...array_column($source['fichiers'], 'source_sha256')] as $hash) {
                self::assertIsString($hash);
                self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
            }
            self::assertIsString($source['lu_le']);
            self::assertSame($date, substr($source['lu_le'], 0, 10), 'la date de la source est le jour de la lecture');
        }

        // L'historique et les passages commencent par leur en-tête ; les passages donnent la disponibilité observée.
        self::assertStringStartsWith(DataFiles::historyHeader(), (string) file_get_contents(self::DATA . '/' . DataFiles::HISTORY_CSV));
        $runs = DataFiles::readRuns((string) file_get_contents(self::DATA . '/' . DataFiles::RUNS_CSV));
        self::assertLessThanOrEqual(Sources::load()->runs, \count($runs));
        self::assertSame(array_intersect_key(DataFiles::availability($runs, Sources::load()->minRuns), $apis), $availability);

        // Les chiffres du README et la page LIST.md sont ceux des données publiées.
        self::assertStringContainsString(Readme::START . Readme::block($date, $ordered) . Readme::END, (string) file_get_contents(self::ROOT . '/README.md'));
        self::assertSame(ListPage::render($date, $ordered), file_get_contents(self::ROOT . '/' . ListPage::FILE));
    }

    /**
     * Aucune adresse e-mail ni adresse IPv4 ne doit arriver dans data/, ni dans les pages générées.
     */
    public function testPublishedFilesContainNoEmailAndNoIpv4Address(): void
    {
        foreach ([...(glob(self::DATA . '/*') ?: []), self::ROOT . '/' . ListPage::FILE] as $file) {
            $content = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(Text::EMAIL, $content, basename($file) . ' contient une adresse e-mail');
            self::assertDoesNotMatchRegularExpression(Screening::IPV4, $content, basename($file) . ' contient une adresse IPv4');
        }
    }

    /**
     * Les fichiers CSV se lisent avec un séparateur virgule, sans marque d'ordre des octets.
     */
    public function testCsvFilesAreUtf8WithoutBomAndCommaSeparated(): void
    {
        foreach ([DataFiles::APIS_CSV => DataFiles::APIS_COLUMNS, DataFiles::HOSTS_CSV => DataFiles::HOSTS_COLUMNS, DataFiles::HISTORY_CSV => DataFiles::HISTORY_COLUMNS, DataFiles::RUNS_CSV => DataFiles::RUNS_COLUMNS] as $file => $columns) {
            $content = (string) file_get_contents(self::DATA . '/' . $file);
            self::assertStringStartsWith(implode(',', $columns) . "\n", $content, $file);
            self::assertSame(1, preg_match('//u', $content), $file . ' : UTF-8 attendu');
            foreach (explode("\n", rtrim($content, "\n")) as $line) {
                self::assertCount(\count($columns), str_getcsv($line, ',', '"', ''), $file . ' : ' . $line);
            }
        }
    }

    private static function assertSchemaValid(string $json, string $schemaFile): void
    {
        $data = json_decode($json);
        $schema = json_decode((string) file_get_contents($schemaFile));
        self::assertIsObject($schema, 'schéma illisible');

        $validator = new Validator();
        $validator->validate($data, $schema, Constraint::CHECK_MODE_NORMAL);

        self::assertTrue($validator->isValid(), (string) json_encode($validator->getErrors(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
