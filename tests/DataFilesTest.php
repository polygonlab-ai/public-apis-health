<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Apis;
use PolygonLab\PublicApisHealth\Tools\Api;
use PolygonLab\PublicApisHealth\Tools\DataFiles;
use PolygonLab\PublicApisHealth\Tools\JsonFile;
use PolygonLab\PublicApisHealth\Tools\ListPage;
use PolygonLab\PublicApisHealth\Tools\Readme;
use PolygonLab\PublicApisHealth\Tools\SourceError;
use PolygonLab\PublicApisHealth\Tools\Sources;
use PolygonLab\PublicApisHealth\Tools\TestClass;

/**
 * Les fichiers de data/ et les pages générées : rendu des entrées et des hôtes, passages et disponibilité observée,
 * historique, bloc du README et page LIST.md.
 */
final class DataFilesTest extends TestCase
{
    public function testAnEntryIsPublishedWithItsTest(): void
    {
        $api = Apis::make(1, TestClass::DENIED, auth: 'apiKey', httpCode: 401);
        $list = JsonFile::decode(DataFiles::apisJson(Sources::load(), '2026-11-19', [$api], [$api->url => 0.88]), 'apis.json');

        self::assertSame('public-apis/public-apis', $list['source']);
        self::assertSame('https://github.com/public-apis/public-apis', $list['source_page']);
        self::assertSame('2026-11-19', $list['source_date']);
        self::assertSame(1, $list['count']);
        self::assertSame(1, $list['host_count']);
        self::assertIsArray($list['counts']);
        self::assertSame(['Sample category' => 1], $list['counts']['by_category']);
        $tests = array_fill_keys(TestClass::ALL, 0);
        $tests[TestClass::DENIED] = 1;
        self::assertSame($tests, $list['counts']['by_test_result']);
        self::assertIsArray($list['apis']);
        $record = $list['apis'][0];
        self::assertIsArray($record);
        self::assertSame(['name', 'url', 'host', 'description', 'category', 'auth', 'https', 'cors', 'test'], array_keys($record));
        self::assertSame(
            ['name' => 'Sample API 1', 'url' => 'https://docs.example.com/api-1/', 'host' => 'docs.example.com', 'description' => 'Sample description', 'category' => 'Sample category', 'auth' => 'apiKey', 'https' => 'Yes', 'cors' => 'Unknown'],
            \array_slice($record, 0, 8),
        );
        self::assertSame(['result' => 'access_denied', 'http_code' => 401, 'tested_on' => '2026-11-19', 'observed_availability' => 0.88], $record['test']);
    }

    public function testAnUntestedEntryHasNoTestDate(): void
    {
        $list = JsonFile::decode(DataFiles::apisJson(Sources::load(), '2026-11-19', [Apis::make(1, TestClass::UNTESTED)], []), 'apis.json');

        self::assertIsArray($list['apis']);
        self::assertIsArray($list['apis'][0]);
        self::assertSame(['result' => 'not_tested', 'http_code' => null, 'tested_on' => null, 'observed_availability' => null], $list['apis'][0]['test']);
    }

    public function testAnEmptyListWritesAnEmptyObjectOfCategories(): void
    {
        $json = DataFiles::apisJson(Sources::load(), null, [], []);

        self::assertStringContainsString('"by_category": {}', $json, 'un objet vide, jamais une liste vide');
        self::assertStringContainsString('"apis": []', $json);
    }

    public function testEntriesAreSortedByCategoryNameThenLink(): void
    {
        $apis = [
            Apis::make(1, category: 'Weather', name: 'Alpha'),
            Apis::make(2, category: 'animals', name: 'Zebra'),
            Apis::make(3, category: 'Animals', name: 'zebra'),
            Apis::make(4, category: 'Animals', name: 'Éléphant'),
            Apis::make(6, category: 'Animals', name: 'Cat Facts'),
            Apis::make(5, category: 'Animals', name: 'Cat Facts'),
        ];

        self::assertSame(
            ['api-5', 'api-6', 'api-4', 'api-3', 'api-2', 'api-1'],
            array_map(static fn(Api $api): string => basename($api->url), DataFiles::sorted($apis)),
            'catégorie et nom sans tenir compte des majuscules ni des accents ; deux noms identiques : par adresse',
        );
    }

    public function testTheCsvFileHasNoBomAndQuotesWhatNeedsIt(): void
    {
        $api = Apis::make(1, description: 'Stocks, bonds and "quotes"');
        $csv = DataFiles::apisCsv('2026-11-19', [$api], [$api->url => 1.0]);

        self::assertStringStartsWith('name,url,host,description,category,auth,https,cors,test_result,test_http_code,tested_on,observed_availability' . "\n", $csv);
        $lines = explode("\n", trim($csv));
        self::assertCount(2, $lines);
        self::assertStringContainsString(',"Stocks, bonds and ""quotes""",', $lines[1]);
        $cells = str_getcsv($lines[1], DataFiles::SEPARATOR, '"', '');
        self::assertCount(\count(DataFiles::APIS_COLUMNS), $cells);
        self::assertSame('Stocks, bonds and "quotes"', $cells[3]);
        self::assertSame(['responds', '200', '2026-11-19', '1'], \array_slice($cells, 8));
    }

    public function testHostsGiveTheirBestResultAndTheirCounts(): void
    {
        $apis = [
            ...Apis::range(1, 3, TestClass::UNREACHABLE, 'portal.example.org'),
            Apis::make(4, TestClass::DENIED, 'Weather', 'portal.example.org'),
            Apis::make(5, TestClass::RESPONDS, 'Animals', 'portal.example.org'),
            Apis::make(6, TestClass::SERVER_ERROR, host: 'docs.example.com'),
            Apis::make(7, TestClass::NOT_FOUND, host: 'docs.example.com'),
        ];

        self::assertSame([
            ['host' => 'portal.example.org', 'api_count' => 5, 'categories' => ['Animals', 'Sample category', 'Weather'], 'result' => 'responds', 'responding_count' => 1],
            ['host' => 'docs.example.com', 'api_count' => 2, 'categories' => ['Sample category'], 'result' => 'not_found', 'responding_count' => 0],
        ], DataFiles::hosts($apis));
        self::assertSame(
            "host,api_count,categories,result,responding_count\nportal.example.org,5,Animals | Sample category | Weather,responds,1\ndocs.example.com,2,Sample category,not_found,0\n",
            DataFiles::hostsCsv($apis),
        );
        $hosts = JsonFile::decode(DataFiles::hostsJson(Sources::load(), '2026-11-19', $apis), 'hosts.json');
        self::assertSame(2, $hosts['count']);
        self::assertSame(DataFiles::hosts($apis), $hosts['hosts']);
    }

    public function testCategoriesGiveTheirCounts(): void
    {
        $apis = [
            Apis::make(1, TestClass::RESPONDS, 'Weather'),
            Apis::make(2, TestClass::NOT_FOUND, 'Weather'),
            Apis::make(3, TestClass::RESPONDS, 'Animals'),
            Apis::make(4, TestClass::DENIED, 'art & Design'),
        ];

        self::assertSame(
            ['Animals' => ['count' => 1, 'responding' => 1], 'art & Design' => ['count' => 1, 'responding' => 0], 'Weather' => ['count' => 2, 'responding' => 1]],
            DataFiles::categories($apis),
        );
    }

    public function testRunsKeepTheLastEightAndReplaceTheSameDay(): void
    {
        $runs = [];
        for ($day = 1; $day <= 10; $day++) {
            $runs = DataFiles::withRun($runs, sprintf('2026-11-%02d', $day), [Apis::make(1)], 8);
        }
        self::assertSame(['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10'], array_keys($runs));

        $runs = DataFiles::withRun($runs, '2026-11-10', [Apis::make(1, TestClass::UNREACHABLE), Apis::make(2)], 8);
        self::assertCount(8, $runs);
        self::assertSame(['https://docs.example.com/api-1/' => 'unreachable', 'https://docs.example.com/api-2/' => 'responds'], $runs['2026-11-10']);

        self::assertStringStartsWith("date,url,result\n2026-11-03,https://docs.example.com/api-1/,responds\n", DataFiles::runsCsv($runs));
        self::assertSame($runs, DataFiles::readRuns(DataFiles::runsCsv($runs)), 'le fichier des passages se relit à l\'identique');
    }

    public function testAnAddressWithACommaIsReadBackFromTheRuns(): void
    {
        $api = new Api('Comma', 'https://docs.example.com/a,b?c="d"', null, 'Sample category', 'No', 'Yes', 'Yes', TestClass::RESPONDS, 200);
        $runs = DataFiles::withRun([], '2026-11-19', [$api], 8);

        self::assertSame($runs, DataFiles::readRuns(DataFiles::runsCsv($runs)));
    }

    public function testObservedAvailabilityNeedsThreeTestedRuns(): void
    {
        $runs = [
            '2026-11-05' => ['a' => 'responds', 'b' => 'responds', 'c' => 'not_tested', 'd' => 'unreachable'],
            '2026-11-12' => ['a' => 'access_denied', 'b' => 'server_error', 'c' => 'responds', 'd' => 'not_found'],
        ];
        self::assertSame([], DataFiles::availability($runs, 3), 'moins de trois passages : aucune valeur');

        $runs['2026-11-19'] = ['a' => 'unreachable', 'b' => 'responds', 'c' => 'responds', 'd' => 'client_error', 'e' => 'responds'];
        self::assertSame(['a' => 0.67, 'b' => 0.67, 'd' => 0.0], DataFiles::availability($runs, 3), '« responds » et « access_denied » comptent ; une entrée non testée ou trop récente n\'a pas de valeur');
    }

    public function testAnUnexpectedRunsFileIsRefused(): void
    {
        $this->expectException(SourceError::class);
        DataFiles::readRuns(DataFiles::runsHeader() . "2026-11-05,a,perfect\n");
    }

    public function testHistoryOnlyGrowsAndGivesTheDiscardedEntries(): void
    {
        $history = DataFiles::appendHistory('', [
            ['date' => '2026-11-05', 'event' => DataFiles::EVENT_DISCARDED, 'url' => 'https://a.example.com/', 'name' => 'API a', 'detail' => 'discarded: forbidden word'],
            ['date' => '2026-11-05', 'event' => DataFiles::EVENT_DISCARDED, 'url' => 'https://b.example.com/', 'name' => 'API, b', 'detail' => 'discarded: IPv4 address'],
        ]);
        $history = DataFiles::appendHistory($history, [['date' => '2026-11-12', 'event' => DataFiles::EVENT_ADDED, 'url' => 'https://b.example.com/', 'name' => 'API, b', 'detail' => 'Sample category, test responds']]);

        self::assertStringStartsWith(DataFiles::historyHeader(), $history);
        self::assertSame("date,event,url,name,detail\n", DataFiles::historyHeader());
        self::assertStringContainsString("2026-11-05,discarded,https://b.example.com/,\"API, b\",discarded: IPv4 address\n", $history);
        self::assertSame(3, substr_count($history, "\n") - 1);
        self::assertSame(['https://a.example.com/'], DataFiles::discardedUrls($history));

        $this->expectException(SourceError::class);
        DataFiles::appendHistory("other,header\n", []);
    }

    public function testNumbersAreWrittenShort(): void
    {
        self::assertSame('1', DataFiles::number(1.0));
        self::assertSame('0.67', DataFiles::number(0.67));
        self::assertSame('0.5', DataFiles::number(0.5));
        self::assertSame('0', DataFiles::number(0.0));
        self::assertNull(DataFiles::number(null));
    }

    public function testTheReadmeBlockGivesTheFigures(): void
    {
        $apis = [
            ...Apis::range(1, 3),
            Apis::make(4, TestClass::UNREACHABLE, 'Art | <b>Design</b>', 'portal.example.org'),
            Apis::make(5, TestClass::UNTESTED, 'Weather'),
        ];

        $block = Readme::block('2026-11-19', $apis);

        self::assertStringContainsString('List read and tested on 2026-11-19: 5 APIs on 2 hosts, in 3 categories.', $block);
        self::assertStringContainsString('[`LIST.md`](LIST.md)', $block);
        self::assertStringContainsString("| Test result | Value | APIs |\n|---|---|---:|\n| Responds | `responds` | 3 |\n| Access denied | `access_denied` | 0 |", $block);
        self::assertStringContainsString('| Unreachable | `unreachable` | 1 |', $block);
        self::assertStringContainsString('| Not tested | `not_tested` | 1 |', $block);
        self::assertStringContainsString("| Category | APIs | Responding |\n|---|---:|---:|\n| Art \\| &lt;b&gt;Design&lt;/b&gt; | 1 | 0 |\n| Sample category | 3 | 3 |\n| Weather | 1 | 0 |\n", $block, 'catégories dans l\'ordre alphabétique, nom échappé');
    }

    public function testTheReadmeIsOnlyRewrittenBetweenItsMarkers(): void
    {
        $readme = "Before\n" . Readme::START . "\nold\n" . Readme::END . "\nAfter\n";

        self::assertSame("Before\n" . Readme::START . "\nnew\n" . Readme::END . "\nAfter\n", Readme::replace($readme, "\nnew\n"));

        $this->expectException(SourceError::class);
        Readme::replace('No marker', "\nnew\n");
    }

    public function testTheListPageGroupsTheEntriesByCategory(): void
    {
        $apis = [
            Apis::make(1, TestClass::RESPONDS, 'Weather', name: 'Rain [beta]', description: 'Rain | snow <now>', auth: 'apiKey'),
            Apis::make(2, TestClass::NOT_FOUND, 'Animals', name: 'Cat Facts', description: null, auth: null),
            Apis::make(3, TestClass::DENIED, 'Animals', name: 'Axolotl'),
            new Api('Wiki', 'https://wiki.example.org/API_(v2)', 'Parentheses in the link', 'Weather', 'No', 'Yes', 'Yes', TestClass::RESPONDS, 200),
            new Api('Odd link', 'http://odd.example.org/a|b', 'Link that cannot be written safely', 'Weather', 'No', 'No', 'Yes', TestClass::UNREACHABLE),
        ];

        $page = ListPage::render('2026-11-19', $apis);

        self::assertStringStartsWith('# List of APIs by category', $page);
        self::assertStringContainsString('[public-apis/public-apis](https://github.com/public-apis/public-apis) (MIT licence)', $page);
        self::assertStringContainsString('List read and tested on 2026-11-19: 5 APIs in 2 categories.', $page);
        self::assertStringContainsString(
            "## Animals (2)\n\n| API | Description | Auth | Test |\n|---|---|---|---|\n"
            . "| [Axolotl](https://docs.example.com/api-3/) | Sample description | No | `access_denied` |\n"
            . "| [Cat Facts](https://docs.example.com/api-2/) |  |  | `not_found` |\n",
            $page,
        );
        self::assertStringContainsString(
            "## Weather (3)\n\n| API | Description | Auth | Test |\n|---|---|---|---|\n"
            . "| Odd link | Link that cannot be written safely | No | `unreachable` |\n"
            . "| [Rain \\[beta\\]](https://docs.example.com/api-1/) | Rain \\| snow &lt;now&gt; | apiKey | `responds` |\n"
            . "| [Wiki](https://wiki.example.org/API_%28v2%29) | Parentheses in the link | No | `responds` |\n",
            $page,
        );
        self::assertGreaterThan(strpos($page, '## Animals'), strpos($page, '## Weather'));
    }

    public function testEmptyPagesSayThatTheListIsComing(): void
    {
        self::assertStringContainsString('after the first reading of the list', Readme::block(null, []));
        self::assertStringContainsString('after the first reading of the source', ListPage::render(null, []));
        self::assertStringContainsString('after the first reading of the list', Readme::block('2026-11-19', []));
    }
}
