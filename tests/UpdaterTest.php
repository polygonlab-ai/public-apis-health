<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Apis;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Workspace;
use PolygonLab\PublicApisHealth\Tools\Api;
use PolygonLab\PublicApisHealth\Tools\DataFiles;
use PolygonLab\PublicApisHealth\Tools\ForbiddenWords;
use PolygonLab\PublicApisHealth\Tools\JsonFile;
use PolygonLab\PublicApisHealth\Tools\MarkdownList;
use PolygonLab\PublicApisHealth\Tools\Screening;
use PolygonLab\PublicApisHealth\Tools\SourceError;
use PolygonLab\PublicApisHealth\Tools\Sources;
use PolygonLab\PublicApisHealth\Tools\TestClass;
use PolygonLab\PublicApisHealth\Tools\Updater;

/**
 * La comparaison d'une collecte aux données en place : première lecture, liste inchangée, événements de
 * l'historique, disponibilité observée, lignes écartées, valeurs inattendues, garde-fous (liste vide, disparitions,
 * changements de test).
 *
 * @phpstan-import-type Collected from Updater
 */
final class UpdaterTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testTheFirstReadingFillsEveryFile(): void
    {
        $result = $this->update(Apis::collected([Apis::make(1), Apis::make(2, TestClass::UNREACHABLE, 'Weather')]), '2026-11-19');

        self::assertTrue($result['changed']);
        self::assertSame([DataFiles::APIS_JSON, DataFiles::APIS_CSV, DataFiles::HOSTS_JSON, DataFiles::HOSTS_CSV, DataFiles::HISTORY_CSV, DataFiles::RUNS_CSV, DataFiles::SOURCE_JSON], array_keys($result['files']));
        $summary = $result['summary'];
        self::assertSame(1, $summary['format']);
        self::assertSame('public-apis-health', $summary['depot']);
        self::assertTrue($summary['change']);
        self::assertTrue($summary['premiere_lecture']);
        self::assertSame(0, $summary['avant']);
        self::assertSame(2, $summary['apres']);
        self::assertSame(0, $summary['retraits']);
        self::assertSame('API', $summary['unite']);
        self::assertSame([['cle' => 'apis', 'libelle' => 'APIs', 'nombre' => 2]], $summary['listes']);
        self::assertSame('First reading of the list on 2026-11-19: 2 APIs, 1 host', $summary['titre']);
        self::assertStringContainsString("2026-11-19,added,https://docs.example.com/api-1/,Sample API 1,\"Sample category, test responds\"\n", $this->workspace->read(DataFiles::HISTORY_CSV));
        self::assertSame("date,url,result\n2026-11-19,https://docs.example.com/api-1/,responds\n2026-11-19,https://docs.example.com/api-2/,unreachable\n", $this->workspace->read(DataFiles::RUNS_CSV));
        $source = JsonFile::decode($this->workspace->read(DataFiles::SOURCE_JSON), 'source.json');
        self::assertSame('2026-11-19', $source['source_date']);
        self::assertSame('2026-11-19T06:40:00+01:00', $source['lu_le']);
        self::assertIsArray($source['fichiers']);
        self::assertCount(1, $source['fichiers']);
        $file = $source['fichiers'][0];
        self::assertIsArray($file);
        self::assertSame('list', $file['cle']);
        self::assertSame(Sources::load()->listUrl, $file['url']);
        self::assertSame(hash('sha256', 'source'), $file['source_sha256']);
        self::assertSame(2, $file['lines_read']);
        self::assertSame(2, $file['apis_read']);
        self::assertSame(0, $file['apis_discarded']);
        self::assertSame(2, $file['urls_tested']);
        self::assertSame(12, $file['test_duration_s']);
        self::assertSame([], $file['set_aside']);
        self::assertStringContainsString('List read and tested on 2026-11-19: 2 APIs on 1 host, in 2 categories.', $result['readme']);
        self::assertStringContainsString('## Sample category (1)', $result['list']);
    }

    public function testTheSameListChangesNothing(): void
    {
        $apis = [Apis::make(1), Apis::make(2)];
        $this->update(Apis::collected($apis), '2026-11-19');

        $result = $this->update(Apis::collected($apis), '2026-11-26');

        self::assertFalse($result['changed']);
        self::assertSame([], $result['files']);
        self::assertFalse($result['summary']['change']);
        self::assertSame('List of 2026-11-26: no change', $result['summary']['titre']);
    }

    public function testAnHttpCodeOfTheSameClassAloneChangesNothing(): void
    {
        $this->update(Apis::collected([Apis::make(1, httpCode: 200)]), '2026-11-19');

        $result = $this->update(Apis::collected([Apis::make(1, httpCode: 204)]), '2026-11-26');

        self::assertFalse($result['changed'], 'un code HTTP de la même classe ne donne pas de version');
    }

    public function testEveryChangeGivesItsEvent(): void
    {
        $this->update(Apis::collected([Apis::make(1), Apis::make(2), Apis::make(3), Apis::make(4), Apis::make(5), Apis::make(6)]), '2026-11-19');

        $result = $this->update(Apis::collected([
            Apis::make(1),
            Apis::make(2, TestClass::UNREACHABLE),
            Apis::make(3, auth: 'apiKey'),
            Apis::make(4, description: 'New description.', name: 'Renamed API 4'),
            Apis::make(5),
            Apis::make(7, TestClass::DENIED, 'Weather'),
        ]), '2026-11-26');

        self::assertTrue($result['changed']);
        $history = $this->workspace->read(DataFiles::HISTORY_CSV);
        self::assertStringContainsString("2026-11-26,test_result_changed,https://docs.example.com/api-2/,Sample API 2,responds -> unreachable\n", $history);
        self::assertStringContainsString("2026-11-26,added,https://docs.example.com/api-7/,Sample API 7,\"Weather, test access_denied\"\n", $history);
        self::assertStringContainsString("2026-11-26,removed,https://docs.example.com/api-6/,Sample API 6,no longer in the list\n", $history);
        self::assertSame(6 + 3, substr_count($history, "\n") - 1, 'l\'historique ne fait que s\'allonger');
        $summary = $result['summary'];
        self::assertSame('List of 2026-11-26: 1 added, 1 removed, 1 test result changed, 2 other changes', $summary['titre']);
        self::assertSame(6, $summary['avant']);
        self::assertSame(6, $summary['apres']);
        self::assertSame(1, $summary['retraits']);
        self::assertSame(['Added', 'Removed', 'Test result changes', 'Other changes', 'Lists'], array_column(self::sections($summary), 'titre'));
        self::assertIsString($summary['texte']);
        self::assertStringContainsString("Other changes\n- Renamed API 4 (https://docs.example.com/api-4/): name, description\n- Sample API 3 (https://docs.example.com/api-3/): auth\n", $summary['texte']);
        self::assertStringContainsString("- Test unreachable: 1\n", $summary['texte'] . "\n");
        self::assertStringContainsString("- Categories: 2\n", $summary['texte']);
    }

    public function testADiscardedEntryIsNotedOnceAndLeavesTheList(): void
    {
        $this->update(Apis::collected([Apis::make(1), Apis::make(2), Apis::make(3), Apis::make(4), Apis::make(5), Apis::make(6)]), '2026-11-19');

        $changed = [Apis::make(1), Apis::make(2, name: 'API ' . Workspace::FORBIDDEN_WORD), Apis::make(3), Apis::make(4), Apis::make(5), Apis::make(6), Apis::make(7, description: 'Server 192.0.2.44.')];
        $result = $this->update(Apis::collected($changed), '2026-11-26');

        $history = $this->workspace->read(DataFiles::HISTORY_CSV);
        self::assertStringContainsString('2026-11-26,discarded,https://docs.example.com/api-2/,[name withheld ' . substr(hash('sha256', 'https://docs.example.com/api-2/'), 0, 8) . "],\"discarded: forbidden word (field \"\"name\"\")\"\n", $history);
        self::assertStringContainsString("2026-11-26,discarded,https://docs.example.com/api-7/,Sample API 7,\"discarded: IPv4 address (field \"\"description\"\")\"\n", $history);
        self::assertStringNotContainsString(Workspace::FORBIDDEN_WORD, implode('', $result['files']) . $result['readme'] . $result['list'] . (string) json_encode($result['summary']));
        self::assertSame(1, $result['summary']['retraits']);
        self::assertSame(5, $result['summary']['apres']);
        self::assertSame('List of 2026-11-26: 2 discarded', $result['summary']['titre']);
        self::assertSame(['https://docs.example.com/api-2/', 'https://docs.example.com/api-7/'], DataFiles::discardedUrls($history));
        $file = self::sourceFile($this->workspace->read(DataFiles::SOURCE_JSON));
        self::assertSame(7, $file['apis_read']);
        self::assertSame(2, $file['apis_discarded']);

        $again = $this->update(Apis::collected($changed), '2026-12-03');
        self::assertFalse($again['changed'], 'une entrée déjà écartée n\'est pas notée une seconde fois');
    }

    public function testEmailRemovalsAreReportedForNewOrChangedEntries(): void
    {
        $emails = ['https://docs.example.com/api-1/' => ['name', 'description']];
        $first = $this->update(Apis::collected(Apis::range(1, 9), $emails), '2026-11-19');
        self::assertIsString($first['summary']['texte']);
        self::assertStringContainsString("Remarks\n- E-mail address removed (name, description): Sample API 1\n", $first['summary']['texte']);

        $second = $this->update(Apis::collected([Apis::make(1), Apis::make(2, TestClass::DENIED), ...Apis::range(3, 9)], $emails), '2026-11-26');
        self::assertTrue($second['changed']);
        self::assertIsString($second['summary']['texte']);
        self::assertStringNotContainsString('Remarks', $second['summary']['texte'], 'la remarque n\'est pas répétée à chaque passage');
    }

    public function testLinesSetAsideAreListedInTheSourceAndReportedOnce(): void
    {
        $notes = [
            ['kind' => MarkdownList::KIND_DUPLICATE, 'line' => 12, 'reason' => 'same link as "Sample API 1" (line 4)', 'name' => 'Twin', 'url' => 'https://docs.example.com/api-1/'],
            ['kind' => MarkdownList::KIND_UNREADABLE, 'line' => 20, 'reason' => '6 cells where 5 are expected', 'name' => 'Broken', 'url' => null],
            ['kind' => MarkdownList::KIND_UNREADABLE, 'line' => 31, 'reason' => 'no "[name](link)" in the first cell', 'name' => null, 'url' => null],
            ['kind' => MarkdownList::KIND_DUPLICATE, 'line' => 40, 'reason' => 'same link as "Sample API 2" (line 5)', 'name' => 'Twin of ' . Workspace::FORBIDDEN_WORD, 'url' => 'https://docs.example.com/api-2/'],
        ];
        $first = $this->update(Apis::collected(Apis::range(1, 9), [], $notes), '2026-11-19');

        self::assertIsString($first['summary']['texte']);
        self::assertStringContainsString(
            "Set aside at reading\n"
            . "- Line 12, Twin: duplicate link set aside, same link as \"Sample API 1\" (line 4)\n"
            . "- Line 20, Broken: unreadable line set aside, 6 cells where 5 are expected\n"
            . "- Line 31, name withheld: unreadable line set aside, no \"[name](link)\" in the first cell\n"
            . "- Line 40, name withheld: duplicate link set aside, same link as \"Sample API 2\" (line 5)\n",
            $first['summary']['texte'],
        );
        self::assertStringContainsString("- Unreadable lines set aside: 2\n- Duplicate links set aside: 2", $first['summary']['texte']);
        $file = self::sourceFile($this->workspace->read(DataFiles::SOURCE_JSON));
        self::assertSame(13, $file['lines_read']);
        self::assertSame(2, $file['lines_set_aside']);
        self::assertSame(2, $file['duplicate_links']);
        self::assertIsArray($file['set_aside']);
        self::assertSame(['kind' => 'duplicate', 'line' => 12, 'reason' => 'same link as "Sample API 1" (line 4)', 'name' => 'Twin', 'url' => 'https://docs.example.com/api-1/'], $file['set_aside'][0]);
        self::assertSame(['kind' => 'duplicate', 'line' => 40, 'reason' => 'same link as "Sample API 2" (line 5)', 'name' => null, 'url' => 'https://docs.example.com/api-2/'], $file['set_aside'][3], 'un nom refusé n\'est pas publié');
        self::assertStringNotContainsString(Workspace::FORBIDDEN_WORD, implode('', $first['files']) . (string) json_encode($first['summary']));

        // Passage suivant : les mêmes lignes, plus bas dans la liste, et une nouvelle. Seule la nouvelle est citée.
        $moved = [];
        foreach ($notes as $note) {
            $note['line'] += 3;
            $moved[] = $note;
        }
        $moved[] = ['kind' => MarkdownList::KIND_UNREADABLE, 'line' => 50, 'reason' => '4 cells where 5 are expected', 'name' => 'Newly broken', 'url' => null];
        $second = $this->update(Apis::collected([Apis::make(1, TestClass::DENIED), ...Apis::range(2, 9)], [], $moved), '2026-11-26');

        self::assertTrue($second['changed']);
        self::assertIsString($second['summary']['texte']);
        self::assertStringContainsString("Set aside at reading\n- Line 50, Newly broken: unreadable line set aside, 4 cells where 5 are expected\n\n", $second['summary']['texte']);
        self::assertStringNotContainsString('Twin', $second['summary']['texte']);
        self::assertStringContainsString('- Unreadable lines set aside: 3', $second['summary']['texte']);
        self::assertCount(5, (array) self::sourceFile($this->workspace->read(DataFiles::SOURCE_JSON))['set_aside']);
    }

    public function testUnexpectedDeclaredValuesAreKeptAndCounted(): void
    {
        $apis = [
            Apis::make(1),
            new Api('Odd 1', 'https://odd.example.com/1', null, 'Sample category', 'Bearer token', 'Partly', 'Maybe', TestClass::RESPONDS, 200),
            new Api('Odd 2', 'https://odd.example.com/2', null, 'Sample category', 'Bearer token', 'Yes', null, TestClass::RESPONDS, 200),
        ];

        self::assertSame(['auth "Bearer token": 2', 'cors "Maybe": 1', 'cors (empty): 1', 'https "Partly": 1'], Updater::unexpectedValues($apis));
        self::assertSame([], Updater::unexpectedValues(Apis::range(1, 3)));

        $result = $this->update(Apis::collected($apis), '2026-11-19');

        self::assertIsString($result['summary']['texte']);
        self::assertStringContainsString("- Unexpected value, auth \"Bearer token\": 2\n- Unexpected value, cors \"Maybe\": 1\n", $result['summary']['texte']);
        $record = self::record($this->workspace->read(DataFiles::APIS_JSON), 'https://odd.example.com/1');
        self::assertSame(['Bearer token', 'Partly', 'Maybe'], [$record['auth'], $record['https'], $record['cors']], 'valeurs gardées telles quelles');
    }

    public function testObservedAvailabilityAppearsAtTheThirdRun(): void
    {
        $stable = Apis::range(2, 9);
        $this->update(Apis::collected([Apis::make(1), ...$stable]), '2026-11-05');
        $this->update(Apis::collected([Apis::make(1, TestClass::UNREACHABLE), ...$stable]), '2026-11-12');
        self::assertNull(self::entryTest($this->workspace->read(DataFiles::APIS_JSON), 'https://docs.example.com/api-1/')['observed_availability']);

        $this->update(Apis::collected([Apis::make(1, TestClass::DENIED), ...$stable]), '2026-11-19');

        self::assertSame(0.67, self::entryTest($this->workspace->read(DataFiles::APIS_JSON), 'https://docs.example.com/api-1/')['observed_availability']);
        self::assertSame(1.0, self::entryTest($this->workspace->read(DataFiles::APIS_JSON), 'https://docs.example.com/api-2/')['observed_availability']);
        self::assertStringContainsString(',access_denied,200,2026-11-19,0.67' . "\n", $this->workspace->read(DataFiles::APIS_CSV));
    }

    public function testOnlyTheLastEightRunsAreKept(): void
    {
        $stable = Apis::range(2, 9);
        for ($week = 1; $week <= 10; $week++) {
            $this->update(Apis::collected([Apis::make(1, $week % 2 === 0 ? TestClass::RESPONDS : TestClass::SERVER_ERROR), ...$stable]), sprintf('2026-09-%02d', $week));
        }

        $runs = DataFiles::readRuns($this->workspace->read(DataFiles::RUNS_CSV));

        self::assertCount(8, $runs);
        self::assertSame('2026-09-03', array_key_first($runs));
        self::assertSame(0.5, self::entryTest($this->workspace->read(DataFiles::APIS_JSON), 'https://docs.example.com/api-1/')['observed_availability']);
    }

    public function testAnEmptyListStopsTheUpdate(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Empty list: no publishable API among the 1 read in the list (1 discarded).');
        $this->update(Apis::collected([Apis::make(1, name: 'API ' . Workspace::FORBIDDEN_WORD)]), '2026-11-19');
    }

    public function testMoreThanTwentyPercentOfRemovalsStopTheUpdate(): void
    {
        $this->update(Apis::collected(Apis::range(1, 10)), '2026-11-19');
        $before = $this->workspace->state();

        $twoExits = $this->update(Apis::collected(Apis::range(1, 8)), '2026-11-26', false);
        self::assertTrue($twoExits['changed'], '20 % exactement : accepté');

        try {
            $this->update(Apis::collected(Apis::range(1, 7)), '2026-11-26');
            self::fail('garde-fou non déclenché');
        } catch (SourceError $error) {
            self::assertSame('Safeguard: 3 APIs out of 10 disappear at once (30 %, threshold 20 %).', $error->getMessage());
        }
        self::assertSame($before, $this->workspace->state());
    }

    public function testMoreThanAQuarterOfTestChangesStopTheUpdate(): void
    {
        $this->update(Apis::collected(Apis::range(1, 8)), '2026-11-19');

        $two = $this->update(Apis::collected([...Apis::range(1, 2, TestClass::UNREACHABLE), ...Apis::range(3, 8)]), '2026-11-26', false);
        self::assertTrue($two['changed'], '25 % exactement : accepté');

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Safeguard: 3 APIs out of 8 change test result from one run to the next (37 %, threshold 25 %)');
        $this->update(Apis::collected([...Apis::range(1, 3, TestClass::UNREACHABLE), ...Apis::range(4, 8)]), '2026-11-26');
    }

    public function testALongListOfChangesIsCutInTheSummaryAndTheTitleStaysShort(): void
    {
        $this->update(Apis::collected(Apis::range(1, 5000)), '2026-11-19');

        $next = [
            ...Apis::range(1, 3798),
            ...Apis::range(3799, 4998, TestClass::SERVER_ERROR),
            ...Apis::range(5001, 6500),
            Apis::make(6501, name: 'API ' . Workspace::FORBIDDEN_WORD . ' 1'),
            Apis::make(6502, name: 'API ' . Workspace::FORBIDDEN_WORD . ' 2'),
        ];
        for ($number = 1; $number <= 1000; $number++) {
            $next[$number - 1] = Apis::make($number, description: 'Other.');
        }
        $result = $this->update(Apis::collected(array_values($next)), '2026-11-26');

        // « List of 2026-11-26: 1500 added, 2 removed, 1200 test results changed, 2 discarded, 1000 other changes »
        // dépasse 100 caractères : le titre donne le nombre de changements.
        $summary = $result['summary'];
        self::assertSame('List of 2026-11-26: 3704 changes', $summary['titre']);
        $sections = self::sections($summary);
        self::assertSame('Added (1500)', $sections[0]['titre']);
        self::assertCount(101, $sections[0]['elements']);
        self::assertSame('… and 1400 more: see data/history.csv', $sections[0]['elements'][100]);
        self::assertSame('Other changes (1000)', $sections[4]['titre']);
        self::assertSame('… and 900 more', $sections[4]['elements'][100]);
        self::assertSame(1500 + 2 + 1200 + 2, substr_count($this->workspace->read(DataFiles::HISTORY_CSV), "\n2026-11-26,"), 'l\'historique, lui, garde tous les événements');
    }

    public function testATitleOfAHundredCharactersAtMostIsKeptInFull(): void
    {
        $this->update(Apis::collected(Apis::range(1, 1000)), '2026-11-19');

        $result = $this->update(Apis::collected([...Apis::range(1, 878), ...Apis::range(881, 1000, TestClass::SERVER_ERROR), ...Apis::range(1001, 1150)]), '2026-11-26');

        self::assertSame('List of 2026-11-26: 150 added, 2 removed, 120 test results changed', $result['summary']['titre']);
    }

    public function testEmptyFilesAreValidAndEmpty(): void
    {
        $files = Updater::emptyFiles(Sources::load());

        self::assertSame([], DataFiles::readApis($files[DataFiles::APIS_JSON]));
        self::assertSame(DataFiles::historyHeader(), $files[DataFiles::HISTORY_CSV]);
        self::assertSame(DataFiles::runsHeader(), $files[DataFiles::RUNS_CSV]);
        self::assertSame([], JsonFile::decode($files[DataFiles::SOURCE_JSON], 'source.json')['fichiers']);
        self::assertSame("host,api_count,categories,result,responding_count\n", $files[DataFiles::HOSTS_CSV]);
    }

    /**
     * Lance la comparaison et, comme la commande, écrit les fichiers rendus dans le dossier d'essai.
     *
     * @param Collected $collected
     *
     * @return array{changed: bool, files: array<string, string>, readme: string, list: string, summary: array<string, mixed>}
     */
    private function update(array $collected, string $day, bool $write = true): array
    {
        $screening = new Screening(ForbiddenWords::load($this->workspace->forbiddenWords));
        $result = (new Updater(Sources::load(), $screening, $this->workspace->data))->run($collected, new \DateTimeImmutable($day . 'T06:40:00+01:00'));
        if ($write) {
            foreach ($result['files'] as $name => $content) {
                file_put_contents($this->workspace->data . '/' . $name, $content);
            }
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    private static function record(string $json, string $url): array
    {
        $list = JsonFile::decode($json, 'apis.json')['apis'];
        self::assertIsArray($list);
        foreach ($list as $record) {
            if (\is_array($record) && ($record['url'] ?? null) === $url) {
                return $record;
            }
        }

        self::fail('entrée absente de apis.json : ' . $url);
    }

    /**
     * @return array<mixed>
     */
    private static function entryTest(string $json, string $url): array
    {
        $test = self::record($json, $url)['test'];
        self::assertIsArray($test);

        return $test;
    }

    /**
     * @return array<mixed>
     */
    private static function sourceFile(string $json): array
    {
        $files = JsonFile::decode($json, 'source.json')['fichiers'];
        self::assertIsArray($files);
        self::assertIsArray($files[0]);

        return $files[0];
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return list<array{titre: string, elements: list<string>}>
     */
    private static function sections(array $summary): array
    {
        $sections = [];
        self::assertIsArray($summary['sections']);
        foreach ($summary['sections'] as $section) {
            self::assertIsArray($section);
            self::assertIsString($section['titre']);
            self::assertIsArray($section['elements']);
            $elements = [];
            foreach ($section['elements'] as $element) {
                self::assertIsString($element);
                $elements[] = $element;
            }
            $sections[] = ['titre' => $section['titre'], 'elements' => $elements];
        }

        return $sections;
    }
}
