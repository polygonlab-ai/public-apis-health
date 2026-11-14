<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Workspace;
use PolygonLab\PublicApisHealth\Tools\Command;
use PolygonLab\PublicApisHealth\Tools\DataFiles;
use PolygonLab\PublicApisHealth\Tools\JsonFile;
use PolygonLab\PublicApisHealth\Tools\ProbeReplay;
use PolygonLab\PublicApisHealth\Tools\ReplayClient;
use PolygonLab\PublicApisHealth\Tools\Screening;
use PolygonLab\PublicApisHealth\Tools\Text;

/**
 * La commande d'actualisation, sur une collecte rejouée (lignes réelles de la liste et faux serveurs, aucun appel
 * réseau) : usage, collecte et manifeste, empreinte, actualisation, garde-fous, rien d'écrit en cas de refus.
 */
final class CommandTest extends TestCase
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

    public function testInvalidArgumentsGiveTheUsage(): void
    {
        foreach ([['--unknown'], ['--data'], ['--summary='], ['--collect', $this->workspace->path . '/collect'], ['--data', $this->workspace->path . '/missing']] as $arguments) {
            [$code, , $errors] = $this->command($arguments, false);
            self::assertSame(Command::USAGE, $code, implode(' ', $arguments));
            self::assertStringContainsString('Usage: php tools/actualiser.php', $errors);
        }
        foreach (['--help', '-h'] as $option) {
            [$code, $output] = $this->command([$option], false);
            self::assertSame(Command::SUCCESS, $code);
            self::assertStringContainsString('--forbidden-words', $output);
        }
    }

    public function testTheFirstRunWritesTheDataTheListAndTheFigures(): void
    {
        [$code, $output, $errors] = $this->command(['--replay', Workspace::LIST, '--forbidden-words', $this->workspace->forbiddenWords]);

        self::assertSame(Command::SUCCESS, $code, $errors);
        self::assertStringContainsString('Collection: 24 line(s) read in 8 categories, 3 set aside (unreadable or duplicate), 21 API(s), 20 address(es) tested in ', $output);
        self::assertStringContainsString('(22 request(s)).', $output, 'vingt adresses appelées, une redirection suivie, un second essai');
        self::assertStringContainsString('First reading of the list on 2026-11-19: 21 APIs, 21 hosts', $output);
        self::assertStringContainsString('Files of data/, LIST.md and the figures of the README rewritten.', $output);

        $apis = DataFiles::readApis($this->workspace->read(DataFiles::APIS_JSON));
        self::assertCount(21, $apis);
        $tests = [];
        foreach ($apis as $url => $api) {
            if ($api->test !== 'responds' || $api->httpCode !== 200) {
                $tests[$url] = [$api->test, $api->httpCode];
            }
        }
        ksort($tests);
        self::assertSame([
            'https://alexwohlbruck.github.io/cat-facts/' => ['not_found', 404],
            'https://aniapi.com/docs/' => ['unreachable', null],
            'https://ddownload.com/api' => ['client_error', 429],
            'https://dev.blockscout.com/' => ['server_error', 500],
            'https://discord.com/developers/docs/intro' => ['access_denied', 401],
            'https://hub.openapihub.com/' => ['not_tested', null],
            'https://wiki.anidb.net/HTTP_API_Definition' => ['access_denied', 403],
        ], $tests);
        self::assertSame(['responds', 200], [$apis['https://breedhealthscore.com/developers/']->test, $apis['https://breedhealthscore.com/developers/']->httpCode], 'redirection suivie');

        $list = JsonFile::decode($this->workspace->read(DataFiles::APIS_JSON), 'apis.json');
        self::assertIsArray($list['counts']);
        self::assertSame(['responds' => 14, 'access_denied' => 2, 'not_found' => 1, 'client_error' => 1, 'server_error' => 1, 'unreachable' => 1, 'not_tested' => 1], $list['counts']['by_test_result']);
        self::assertSame(['Animals' => 7, 'Anime' => 2, 'Blockchain' => 2, 'Cloud Storage & File Sharing' => 3, 'Development' => 2, 'Finance' => 2, 'Science & Math' => 1, 'Social' => 2], $list['counts']['by_category']);

        self::assertStringContainsString("Cat Facts,https://alexwohlbruck.github.io/cat-facts/,alexwohlbruck.github.io,Daily cat facts,Animals,No,Yes,No,not_found,404,2026-11-19,\n", $this->workspace->read(DataFiles::APIS_CSV));
        self::assertStringContainsString("Cat Facts,https://catfact.ninja/,catfact.ninja,Random cat facts,Animals,No,Yes,Yes,responds,200,2026-11-19,\n", $this->workspace->read(DataFiles::APIS_CSV));
        self::assertStringContainsString("OpenAPIHub,https://hub.openapihub.com/,hub.openapihub.com,The All-in-one API Platform,Development,X-Mashape-Key,Yes,Unknown,not_tested,,,\n", $this->workspace->read(DataFiles::APIS_CSV));
        self::assertStringContainsString("github.com,1,Social,responds,1\n", $this->workspace->read(DataFiles::HOSTS_CSV));
        self::assertStringContainsString("2026-11-19,added,https://api.nasa.gov/,NASA,\"Science & Math, test responds\"\n", $this->workspace->read(DataFiles::HISTORY_CSV));
        $readme = (string) file_get_contents($this->workspace->readme);
        self::assertStringContainsString('List read and tested on 2026-11-19: 21 APIs on 21 hosts, in 8 categories.', $readme);
        self::assertStringContainsString("| Responds | `responds` | 14 |\n", $readme);
        self::assertStringContainsString("| Animals | 7 | 6 |\n", $readme);
        self::assertStringContainsString("| Science &amp; Math | 1 | 1 |\n", $readme);
        self::assertStringContainsString("## Anime (2)\n\n| API | Description | Auth | Test |\n|---|---|---|---|\n| [AniAPI](https://aniapi.com/docs/) | Anime discovery, streaming &amp; syncing with trackers | OAuth | `unreachable` |\n| [AniDB](https://wiki.anidb.net/HTTP_API_Definition) | Anime Database | apiKey | `access_denied` |\n", (string) file_get_contents($this->workspace->list));
        self::assertSame([], glob($this->workspace->data . '/.*.tmp'), 'aucun fichier temporaire laissé');
    }

    public function testLinesSetAsideAreListedInTheSummaryAndInTheSource(): void
    {
        [$code, $output] = $this->command(['--replay', Workspace::LIST]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertMatchesRegularExpression('/- Line \d+, isitdownstatus: duplicate link set aside, same link as "DownStatus" \(line \d+\)\n/', $output);
        self::assertMatchesRegularExpression('/- Line \d+, Metal Sentinel: unreadable line set aside, 6 cells where 5 are expected\n/', $output);
        self::assertMatchesRegularExpression('/- Line \d+, NASA InSight: duplicate link set aside, same link as "NASA" \(line \d+\)\n/', $output);
        self::assertStringContainsString("- Unreadable lines set aside: 1\n- Duplicate links set aside: 2\n", $output);
        $source = JsonFile::decode($this->workspace->read(DataFiles::SOURCE_JSON), 'source.json');
        self::assertIsArray($source['fichiers']);
        self::assertIsArray($source['fichiers'][0]);
        self::assertIsArray($source['fichiers'][0]['set_aside']);
        self::assertSame(['isitdownstatus', 'Metal Sentinel', 'NASA InSight'], array_column($source['fichiers'][0]['set_aside'], 'name'));
        self::assertSame(hash_file('sha256', Workspace::LIST . '/' . ReplayClient::FILE), $source['fichiers'][0]['source_sha256'], 'empreinte du fichier lu');
    }

    public function testASecondRunOnTheSameListChangesNothing(): void
    {
        $this->command(['--replay', Workspace::LIST, '--forbidden-words', $this->workspace->forbiddenWords]);
        $before = $this->workspace->state();

        [$code, $output] = $this->command(['--replay', Workspace::LIST, '--forbidden-words', $this->workspace->forbiddenWords], true, '2026-11-26');

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('List unchanged: no file modified.', $output);
        self::assertSame($before, $this->workspace->state());
    }

    public function testNoEmailAddressIsEverWritten(): void
    {
        $replay = $this->workspace->replay();
        $this->addLines($replay, "| [Sample desk](https://desk.example.com/) | Write to desk@example.com to get a key | `apiKey` | Yes | Yes |\n| [Sample mail link](https://mail.example.com/?to=someone@example.com) | Link that holds an address | No | Yes | Yes |\n");

        [$code, $output, $errors] = $this->command(['--replay', $replay]);

        self::assertSame(Command::SUCCESS, $code, $errors);
        $written = $output;
        foreach ([...(glob($this->workspace->data . '/*') ?: []), $this->workspace->readme, $this->workspace->list] as $file) {
            $written .= (string) file_get_contents($file);
        }
        self::assertDoesNotMatchRegularExpression(Text::EMAIL, $written);
        self::assertStringContainsString('Write to ' . Text::EMAIL_REPLACEMENT . ' to get a key', $this->workspace->read(DataFiles::APIS_CSV));
        self::assertStringContainsString('- E-mail address removed (description): Sample desk', $output, 'adresse retirée et signalée');
        self::assertStringContainsString('- Sample mail link ([url withheld ', $output, 'lien qui contient une adresse : entrée écartée');
        self::assertStringContainsString(',discarded,[url withheld ', $this->workspace->read(DataFiles::HISTORY_CSV));
    }

    public function testAForbiddenWordOrAnIpv4AddressDiscardsTheEntryWithoutStoppingTheRun(): void
    {
        $replay = $this->workspace->replay();
        $this->addLines($replay, '| [Sample ' . Workspace::FORBIDDEN_WORD . "](https://word.example.com/) | Name with a forbidden word | No | Yes | Yes |\n"
            . '| [Sample lookup](https://lookup.example.com/ip/' . implode('.', [192, 0, 2, 44]) . "/) | Address in the link | No | Yes | Unknown |\n"
            . '| [Sample tolerated](https://tolerated.example.com/) | Works with ' . Workspace::FORBIDDEN_WORD . "-allowed | No | Yes | Yes |\n");

        [$code, $output, $errors] = $this->command(['--replay', $replay, '--forbidden-words', $this->workspace->forbiddenWords]);

        self::assertSame(Command::SUCCESS, $code, $errors);
        self::assertStringContainsString('First reading of the list on 2026-11-19: 22 APIs, 22 hosts', $output);
        self::assertStringContainsString('discarded: forbidden word (field "name")', $output);
        self::assertStringContainsString('- Sample lookup ([url withheld ', $output);
        $published = '';
        foreach ([DataFiles::APIS_JSON, DataFiles::APIS_CSV, DataFiles::HOSTS_JSON, DataFiles::HOSTS_CSV, DataFiles::HISTORY_CSV, DataFiles::RUNS_CSV] as $file) {
            $published .= $this->workspace->read($file);
        }
        $published .= (string) file_get_contents($this->workspace->readme) . (string) file_get_contents($this->workspace->list);
        self::assertStringNotContainsString('Sample ' . Workspace::FORBIDDEN_WORD, $published . $output);
        self::assertStringNotContainsString('word.example.com', $this->workspace->read(DataFiles::APIS_JSON));
        self::assertDoesNotMatchRegularExpression(Screening::IPV4, $published);
        self::assertStringContainsString('https://tolerated.example.com/', $this->workspace->read(DataFiles::APIS_JSON), 'écriture tolérée gardée');
    }

    public function testCollectThenUpdateFromTheManifest(): void
    {
        $directory = $this->workspace->path . '/collect';
        $manifest = $this->workspace->path . '/manifest.json';
        $before = $this->workspace->state();

        [$code, $output, $errors] = $this->command(['--collecter', $directory, '--manifeste', $manifest, '--replay', Workspace::LIST, '--sources', $this->workspace->settings], false);

        self::assertSame(Command::SUCCESS, $code, $errors);
        self::assertStringContainsString('Manifest written: ' . $manifest, $output);
        self::assertSame($before, $this->workspace->state(), 'la collecte seule ne touche pas aux données');
        $entry = self::manifestEntry($manifest);
        self::assertSame('list', $entry['cle']);
        self::assertSame('https://raw.githubusercontent.com/public-apis/public-apis/master/README.md', $entry['url']);
        self::assertSame($directory . '/' . Command::COLLECTED_FILE, $entry['chemin']);
        self::assertSame(hash_file('sha256', $directory . '/' . Command::COLLECTED_FILE), $entry['sha256']);
        self::assertNull($entry['derniere_modification']);
        $collected = (string) file_get_contents($directory . '/' . Command::COLLECTED_FILE);
        foreach (['http_code', 'tested_on', 'observed_availability', '2026-'] as $volatile) {
            self::assertStringNotContainsString($volatile, $collected, 'le fichier qui porte l\'empreinte ne contient ni date ni code HTTP');
        }
        self::assertStringContainsString('"result": "not_found"', $collected, 'la classe du test fait partie de l\'empreinte');

        [$code, $output, $errors] = $this->command(['--manifeste', $manifest, '--resume', $this->workspace->path . '/summary.json', '--mots-interdits', $this->workspace->forbiddenWords]);

        self::assertSame(Command::SUCCESS, $code, $errors);
        $summary = JsonFile::read($this->workspace->path . '/summary.json', 'summary');
        self::assertSame(1, $summary['format']);
        self::assertTrue($summary['change']);
        self::assertSame(21, $summary['apres']);
        self::assertSame('First reading of the list on 2026-11-19: 21 APIs, 21 hosts', $summary['titre']);
        $source = JsonFile::decode($this->workspace->read(DataFiles::SOURCE_JSON), 'source.json');
        self::assertIsArray($source['fichiers']);
        self::assertIsArray($source['fichiers'][0]);
        self::assertSame($entry['sha256'], $source['fichiers'][0]['sha256'], 'data/source.json garde l\'empreinte de la collecte');
        self::assertSame(8, $source['fichiers'][0]['categories_read']);
        self::assertSame(24, $source['fichiers'][0]['lines_read']);
        self::assertSame(1, $source['fichiers'][0]['lines_set_aside']);
        self::assertSame(2, $source['fichiers'][0]['duplicate_links']);
        self::assertSame(21, $source['fichiers'][0]['apis_read']);
        self::assertSame(20, $source['fichiers'][0]['urls_tested']);
        self::assertSame(22, $source['fichiers'][0]['requests']);
        self::assertStringContainsString(',not_found,404,2026-11-19,', $this->workspace->read(DataFiles::APIS_CSV), 'code HTTP repris des compléments de la collecte');
    }

    public function testAnHttpCodeOfTheSameClassDoesNotChangeTheFingerprint(): void
    {
        $first = $this->collect(Workspace::LIST, 'one');

        $replay = $this->workspace->replay('replay-codes');
        $this->workspace->edit($replay . '/' . ProbeReplay::FILE, static function (array $probes): array {
            self::assertIsArray($probes['responses']);
            $probes['responses']['https://catfact.ninja/'] = ['code' => 204, 'redirect' => null, 'error' => null];
            $probes['responses']['https://dev.blockscout.com/'] = ['code' => 503, 'redirect' => null, 'error' => null];

            return $probes;
        });
        // Lignes de la liste dans un autre ordre : la catégorie « Anime » passe avant « Animals ».
        $markdown = Workspace::markdown();
        $animals = self::section($markdown, '### Animals', '### Anime');
        $anime = self::section($markdown, '### Anime', '### Blockchain');
        file_put_contents($replay . '/' . ReplayClient::FILE, str_replace($animals . $anime, $anime . $animals, $markdown));

        self::assertSame($first, $this->collect($replay, 'two'), 'ni un code de la même classe ni l\'ordre des lignes ne changent l\'empreinte');
    }

    public function testATestClassChangeChangesTheFingerprint(): void
    {
        $first = $this->collect(Workspace::LIST, 'one');

        $replay = $this->workspace->replay('replay-class');
        $this->workspace->edit($replay . '/' . ProbeReplay::FILE, static function (array $probes): array {
            self::assertIsArray($probes['responses']);
            $probes['responses']['https://catfact.ninja/'] = ['code' => 503, 'redirect' => null, 'error' => null];

            return $probes;
        });

        self::assertNotSame($first, $this->collect($replay, 'two'));
    }

    public function testAnEditedLineChangesTheFingerprint(): void
    {
        $first = $this->collect(Workspace::LIST, 'one');

        $replay = $this->workspace->replay('replay-edit');
        file_put_contents($replay . '/' . ReplayClient::FILE, str_replace('| Random cat facts | No | Yes | Yes |', '| Random cat facts | `apiKey` | Yes | Yes |', Workspace::markdown()));

        self::assertNotSame($first, $this->collect($replay, 'two'));
    }

    public function testTooManyUnreachableAddressesStopTheCollect(): void
    {
        $replay = $this->workspace->replay();
        $this->workspace->edit($replay . '/' . ProbeReplay::FILE, static function (array $probes): array {
            self::assertIsArray($probes['responses']);
            foreach (['https://catfact.ninja/', 'https://dog.ceo/dog-api/', 'https://api.nasa.gov/', 'https://deed.page/docs', 'https://segmara.com/syndicate', 'https://tradoshi.com/en/developers'] as $url) {
                unset($probes['responses'][$url]);
            }

            return $probes;
        });
        $before = $this->workspace->state();

        [$code, , $errors] = $this->command(['--replay', $replay]);

        self::assertSame(Command::REFUSED, $code);
        self::assertStringContainsString('Safeguard: 7 address(es) out of 20 are unreachable (35 %, threshold 30 %)', $errors);
        self::assertStringContainsString('No file was written.', $errors);
        self::assertSame($before, $this->workspace->state());
    }

    public function testAListThatHasChangedShapeWritesNothing(): void
    {
        $replay = $this->workspace->replay();
        file_put_contents($replay . '/' . ReplayClient::FILE, str_replace("### Anime\nAPI | Description | Auth | HTTPS | CORS |", "### Anime\nAPI | Description | Auth | HTTPS | CORS | Call this API |", Workspace::markdown()));
        $before = $this->workspace->state();

        [$code, $output, $errors] = $this->command(['--replay', $replay, '--summary', $this->workspace->path . '/summary.json']);

        self::assertSame(Command::REFUSED, $code);
        self::assertStringContainsString('Stopped: Line ', $errors);
        self::assertStringContainsString('category "Anime": table header "API | Description | Auth | HTTPS | CORS" expected', $errors);
        self::assertStringNotContainsString('rewritten', $output);
        self::assertSame($before, $this->workspace->state());
        self::assertFileDoesNotExist($this->workspace->path . '/summary.json');
    }

    public function testTheRealSettingsRefuseAListWithTooFewCategories(): void
    {
        $before = $this->workspace->state();

        [$code, , $errors] = $this->command(['--replay', Workspace::LIST, '--data', $this->workspace->data, '--readme', $this->workspace->readme, '--list', $this->workspace->list], false);

        self::assertSame(Command::REFUSED, $code);
        self::assertStringContainsString('Safeguard: 8 categories read where at least 30 are expected', $errors);
        self::assertSame($before, $this->workspace->state());
    }

    public function testAnEmptyListStopsTheCollect(): void
    {
        $replay = $this->workspace->replay();
        file_put_contents($replay . '/' . ReplayClient::FILE, "## Index\n\n* [Animals](#animals)\n\n## License\n");

        [$code, , $errors] = $this->command(['--replay', $replay]);

        self::assertSame(Command::REFUSED, $code);
        self::assertStringContainsString('Empty list: no API read after "## Index"', $errors);
    }

    public function testAListThatIsTooLargeStopsTheCollect(): void
    {
        $replay = $this->workspace->replay();
        file_put_contents($replay . '/' . ReplayClient::FILE, str_repeat("filler\n", 300), FILE_APPEND);

        [$code, , $errors] = $this->command(['--replay', $replay, '--sources', $this->workspace->settingsFile(['min_categories' => Workspace::MIN_CATEGORIES, 'max_size' => 5000])], true, '2026-11-19', false);

        self::assertSame(Command::REFUSED, $code);
        self::assertStringContainsString('The list is larger than 5000 bytes', $errors);
    }

    public function testAnAlteredCollectIsRefused(): void
    {
        $directory = $this->workspace->path . '/collect';
        $manifest = $this->workspace->path . '/manifest.json';
        $this->command(['--collect', $directory, '--manifest', $manifest, '--replay', Workspace::LIST, '--sources', $this->workspace->settings], false);
        file_put_contents($directory . '/' . Command::COLLECTED_FILE, ' ', FILE_APPEND);
        $before = $this->workspace->state();

        [$code, , $errors] = $this->command(['--manifest', $manifest]);

        self::assertSame(Command::REFUSED, $code);
        self::assertStringContainsString('SHA-256 fingerprint different from the one in the manifest', $errors);
        self::assertSame($before, $this->workspace->state());
    }

    public function testAReadmeWithoutMarkersStopsTheUpdate(): void
    {
        file_put_contents($this->workspace->readme, "# No marker\n");
        $before = $this->workspace->state();

        [$code, , $errors] = $this->command(['--replay', Workspace::LIST]);

        self::assertSame(Command::REFUSED, $code);
        self::assertStringContainsString('README.md: markers', $errors);
        self::assertSame($before, $this->workspace->state());
    }

    public function testTheCollectCanBeRecordedAndReplayed(): void
    {
        $record = $this->workspace->path . '/record';

        [$code, , $errors] = $this->command(['--replay', Workspace::LIST, '--record', $record]);
        self::assertSame(Command::SUCCESS, $code, $errors);
        self::assertSame(Workspace::markdown(), file_get_contents($record . '/' . ReplayClient::FILE), 'la liste lue est enregistrée telle quelle');
        self::assertFileExists($record . '/' . ProbeReplay::FILE);
        $before = $this->workspace->state();

        [$code, $output] = $this->command(['--replay', $record], true, '2026-11-26');
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('List unchanged', $output, 'la collecte rejouée depuis son enregistrement donne la même liste');
        self::assertSame($before, $this->workspace->state());
    }

    public function testTheSharedNamesOfTheOptionsAreAccepted(): void
    {
        $directory = $this->workspace->path . '/collect';
        $manifest = $this->workspace->path . '/manifest.json';

        [$code] = $this->command(['--collecter=' . $directory, '--manifeste=' . $manifest, '--replay', Workspace::LIST, '--sources', $this->workspace->settings, '--chargeur', __FILE__], false);
        self::assertSame(Command::SUCCESS, $code);
        self::assertSame(__FILE__, Command::loader(['--data', 'x', '--chargeur', __FILE__]));
        self::assertSame(__FILE__, Command::loader(['--loader=' . __FILE__]));
        self::assertNull(Command::loader(['--data', 'x']));
    }

    /**
     * Lance la commande ; les dossiers et fichiers d'essai, et les réglages d'essai, sont ajoutés aux arguments si
     * $defaults est vrai.
     *
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string} code de sortie, sortie, erreurs
     */
    private function command(array $arguments, bool $defaults = true, string $day = '2026-11-19', bool $settings = true): array
    {
        if ($defaults) {
            array_push($arguments, '--data', $this->workspace->data, '--readme', $this->workspace->readme, '--list', $this->workspace->list);
            if ($settings) {
                array_push($arguments, '--sources', $this->workspace->settings);
            }
        }
        $output = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        self::assertIsResource($errors);
        $code = Command::run($arguments, $output, $errors, new \DateTimeImmutable($day . 'T06:40:00+01:00'));
        rewind($output);
        rewind($errors);
        $result = [$code, (string) stream_get_contents($output), (string) stream_get_contents($errors)];
        fclose($output);
        fclose($errors);

        return $result;
    }

    /**
     * Ajoute des lignes fabriquées à la catégorie « Social » de la copie de la collecte d'essai.
     */
    private function addLines(string $replay, string $lines): void
    {
        $markdown = Workspace::markdown();
        $marker = "<br>\n\n## License";
        self::assertStringContainsString($marker, $markdown);
        file_put_contents($replay . '/' . ReplayClient::FILE, str_replace($marker, $lines . $marker, $markdown));
    }

    /**
     * Empreinte d'une collecte rejouée depuis ce dossier.
     */
    private function collect(string $replay, string $name): string
    {
        $manifest = $this->workspace->path . '/manifest-' . $name . '.json';
        [$code, , $errors] = $this->command(['--collect', $this->workspace->path . '/collect-' . $name, '--manifest', $manifest, '--replay', $replay, '--sources', $this->workspace->settings], false);
        self::assertSame(Command::SUCCESS, $code, $errors);
        $hash = self::manifestEntry($manifest)['sha256'];
        self::assertIsString($hash);

        return $hash;
    }

    /**
     * Partie d'un texte, d'un repère (compris) au suivant (non compris).
     */
    private static function section(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from . "\n");
        $end = strpos($text, $to . "\n");
        self::assertIsInt($start);
        self::assertIsInt($end);

        return substr($text, $start, $end - $start);
    }

    /**
     * @return array<mixed>
     */
    private static function manifestEntry(string $manifest): array
    {
        $data = JsonFile::read($manifest, 'manifest');
        self::assertSame('collecte', $data['mode']);
        self::assertIsArray($data['fichiers']);
        self::assertCount(1, $data['fichiers']);
        self::assertIsArray($data['fichiers'][0]);

        return $data['fichiers'][0];
    }
}
