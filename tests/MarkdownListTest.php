<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Workspace;
use PolygonLab\PublicApisHealth\Tools\Api;
use PolygonLab\PublicApisHealth\Tools\MarkdownList;
use PolygonLab\PublicApisHealth\Tools\SourceError;
use PolygonLab\PublicApisHealth\Tools\Text;

/**
 * La lecture du fichier Markdown de la liste : sur des lignes réelles de la liste public-apis (tests/Fixtures/list),
 * puis sur des lignes fabriquées pour chaque cas particulier.
 */
final class MarkdownListTest extends TestCase
{
    private const HEADER = "API | Description | Auth | HTTPS | CORS |\n|:---|:---|:---|:---|:---|\n";

    public function testTheSampleListIsRead(): void
    {
        $reading = MarkdownList::read(Workspace::markdown(), Workspace::MIN_CATEGORIES);

        self::assertCount(21, $reading['apis']);
        self::assertSame(24, $reading['lines'], 'lignes d\'API lues : entrées, lignes illisibles et liens en double');
        self::assertSame(8, $reading['categories']);
        self::assertSame([], $reading['emails']);
        $urls = array_map(static fn(Api $api): string => $api->url, $reading['apis']);
        $sorted = $urls;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $urls, 'entrées triées par adresse');
        self::assertSame(
            ['Animals' => 7, 'Anime' => 2, 'Blockchain' => 2, 'Cloud Storage & File Sharing' => 3, 'Development' => 2, 'Finance' => 2, 'Science & Math' => 1, 'Social' => 2],
            self::perCategory($reading['apis']),
        );
    }

    public function testTheSectionsBeforeTheIndexAreNeverRead(): void
    {
        $markdown = Workspace::markdown();
        self::assertStringContainsString('## APILayer APIs', $markdown);
        self::assertStringContainsString('## MCP Servers', $markdown);
        self::assertStringContainsString('| [IPstack MCP](https://ipstack.com/mcp) |', $markdown);

        $reading = MarkdownList::read($markdown, Workspace::MIN_CATEGORIES);

        foreach ($reading['apis'] as $api) {
            self::assertStringNotContainsString('IPstack', $api->name);
            self::assertStringNotContainsString('ipstack.com', $api->url);
            self::assertNotSame('APIs Covered Under APILayer Suite!', $api->category);
        }
    }

    public function testNothingIsReadAfterTheEndOfTheIndexSection(): void
    {
        $markdown = Workspace::markdown() . "\n### Late category\n" . self::HEADER . "| [Late](https://late.example.com/) | After the licence | No | Yes | Yes |\n";

        $reading = MarkdownList::read($markdown, Workspace::MIN_CATEGORIES);

        self::assertCount(21, $reading['apis']);
        self::assertSame(8, $reading['categories']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tableHeads(): array
    {
        return [
            'sans barre au début, espace à la fin' => ["API | Description | Auth | HTTPS | CORS \n", "|:---|:---|:---|:---|:---|\n"],
            'barre à la fin seulement' => ["API | Description | Auth | HTTPS | CORS |\n", "|:---|:---|:---|:---|:---|\n"],
            'barres aux deux bouts' => ["| API | Description | Auth | HTTPS | CORS |\n", "|---|:---|:---|:---|:---|\n"],
            'séparation sans deux-points' => ["API | Description | Auth | HTTPS | CORS |\n", "|---|---|---|---|---|\n"],
            'séparation centrée, minuscules' => ["| api | description | auth | https | cors |\n", "| :---: | :---: | :---: | :---: | ---: |\n"],
            'cellule vide en trop' => ["| API | Description | Auth | HTTPS | CORS | |\n", "|---|---|---|---|---| |\n"],
        ];
    }

    #[DataProvider('tableHeads')]
    public function testEveryFormOfTableHeadIsRead(string $header, string $delimiter): void
    {
        $markdown = "## Index\n### Sample\n" . $header . $delimiter . "| [One](https://one.example.com/) | First | No | Yes | Yes |\n";

        $reading = MarkdownList::read($markdown, 1);

        self::assertCount(1, $reading['apis']);
        self::assertSame('One', $reading['apis'][0]->name);
        self::assertSame(1, $reading['lines']);
        self::assertSame([], $reading['notes']);
    }

    public function testTheHeadFormsOfTheSampleListAreTheRealOnes(): void
    {
        $markdown = Workspace::markdown();

        self::assertStringContainsString("### Animals\nAPI | Description | Auth | HTTPS | CORS \n|:---|:---|:---|:---|:---|\n", $markdown);
        self::assertStringContainsString("### Anime\nAPI | Description | Auth | HTTPS | CORS |\n|:---|:---|:---|:---|:---|\n", $markdown);
        self::assertStringContainsString("### Blockchain\n| API | Description | Auth | HTTPS | CORS |\n|---|:---|:---|:---|:---|\n", $markdown);
        self::assertStringContainsString("### Social\nAPI | Description | Auth | HTTPS | CORS |\n|---|---|---|---|---|\n", $markdown);
    }

    public function testTwoEntriesMayShareAName(): void
    {
        $byUrl = self::byUrl(MarkdownList::read(Workspace::markdown(), Workspace::MIN_CATEGORIES)['apis']);

        self::assertSame('Cat Facts', $byUrl['https://alexwohlbruck.github.io/cat-facts/']->name);
        self::assertSame('Cat Facts', $byUrl['https://catfact.ninja/']->name);
        self::assertSame('Daily cat facts', $byUrl['https://alexwohlbruck.github.io/cat-facts/']->description);
        self::assertSame('Random cat facts', $byUrl['https://catfact.ninja/']->description);
    }

    public function testASecondLineWithTheSameLinkIsSetAsideAndNoted(): void
    {
        $reading = MarkdownList::read(Workspace::markdown(), Workspace::MIN_CATEGORIES);
        $byUrl = self::byUrl($reading['apis']);
        $duplicates = array_values(array_filter($reading['notes'], static fn(array $note): bool => $note['kind'] === MarkdownList::KIND_DUPLICATE));

        // Même lien, deux noms : la première ligne est gardée.
        self::assertSame('DownStatus', $byUrl['https://isitdownstatus.com/']->name);
        self::assertSame('Real-time status for GitHub, AWS, Discord and 90+ services', $byUrl['https://isitdownstatus.com/']->description);
        // « https://api.nasa.gov » et « https://api.nasa.gov/ » sont la même adresse une fois normalisées.
        self::assertSame('NASA', $byUrl['https://api.nasa.gov/']->name);

        self::assertCount(2, $duplicates);
        self::assertSame(['isitdownstatus', 'NASA InSight'], array_column($duplicates, 'name'));
        self::assertSame(['https://isitdownstatus.com/', 'https://api.nasa.gov/'], array_column($duplicates, 'url'));
        self::assertMatchesRegularExpression('/\Asame link as "DownStatus" \(line \d+\)\z/', $duplicates[0]['reason']);
        self::assertMatchesRegularExpression('/\Asame link as "NASA" \(line \d+\)\z/', $duplicates[1]['reason']);
        $lines = explode("\n", Workspace::markdown());
        foreach ($duplicates as $note) {
            self::assertStringContainsString('[' . (string) $note['name'] . '](', $lines[$note['line'] - 1], 'le numéro de ligne est celui du fichier lu');
        }
    }

    public function testDeclaredValuesAreKeptWithoutTheirBackticks(): void
    {
        $byUrl = self::byUrl(MarkdownList::read(Workspace::markdown(), Workspace::MIN_CATEGORIES)['apis']);
        $markdown = Workspace::markdown();

        self::assertStringContainsString('| `OAuth` | Yes | Yes |', $markdown);
        self::assertSame(['OAuth', 'Yes', 'Yes'], self::declared($byUrl['https://aniapi.com/docs/']));
        self::assertSame(['apiKey', 'No', 'Unknown'], self::declared($byUrl['https://wiki.anidb.net/HTTP_API_Definition']), 'HTTPS « No », CORS « Unknown »');
        self::assertSame(['No', 'Yes', 'No'], self::declared($byUrl['https://theaxolotlapi.netlify.app/']), 'sans accent grave');
        self::assertStringContainsString('| `No` | Yes | Yes | |', $markdown);
        self::assertSame(['No', 'Yes', 'Yes'], self::declared($byUrl['https://tradoshi.com/en/developers']), '« No » entre accents graves');
        self::assertSame('X-Mashape-Key', $byUrl['https://hub.openapihub.com/']->auth);
        self::assertSame('User-Agent', $byUrl['https://segmara.com/syndicate']->auth);
    }

    public function testEmptyCellsInExcessAtTheEndOfALineAreIgnored(): void
    {
        $reading = MarkdownList::read(Workspace::markdown(), Workspace::MIN_CATEGORIES);
        $byUrl = self::byUrl($reading['apis']);
        $markdown = Workspace::markdown();

        self::assertStringContainsString('| `OAuth` | Yes | Unknown | |' . "\n", $markdown, 'une cellule vide en trop');
        self::assertSame(['OAuth', 'Yes', 'Unknown'], self::declared($byUrl['https://developer.box.com/']));
        self::assertStringContainsString('| `OAuth` | Yes | Unknown | | |' . "\n", $markdown, 'deux cellules vides en trop');
        self::assertSame(['OAuth', 'Yes', 'Unknown'], self::declared($byUrl['https://discord.com/developers/docs/intro']));
    }

    public function testALineWithAFilledCellInExcessIsSetAsideAndNoted(): void
    {
        $reading = MarkdownList::read(Workspace::markdown(), Workspace::MIN_CATEGORIES);
        $unreadable = array_values(array_filter($reading['notes'], static fn(array $note): bool => $note['kind'] === MarkdownList::KIND_UNREADABLE));

        self::assertCount(1, $unreadable);
        self::assertSame('Metal Sentinel', $unreadable[0]['name']);
        self::assertSame('6 cells where 5 are expected', $unreadable[0]['reason']);
        self::assertNull($unreadable[0]['url']);
        self::assertArrayNotHasKey('https://metal-sentinel.com/endpoints', self::byUrl($reading['apis']));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function unreadableLines(): array
    {
        return [
            'quatre cellules' => ['| [Short](https://short.example.com/) | No CORS cell | No | Yes |', '4 cells where 5 are expected', 'Short'],
            'sixième cellule remplie' => ['| [Long](https://long.example.com/) | One cell too many | No | Yes | Yes | Yes', '6 cells where 5 are expected', 'Long'],
            'barre verticale dans la description' => ['| [Pipe](https://pipe.example.com/) | Stocks | bonds | No | Yes | Yes |', '6 cells where 5 are expected', 'Pipe'],
            'pas de lien' => ['| Plain name | No link at all | No | Yes | Yes |', 'no "[name](link)" in the first cell', null],
            'lien sans nom' => ['| [](https://noname.example.com/) | Link without a name | No | Yes | Yes |', 'no "[name](link)" in the first cell', null],
            'texte après le lien' => ['| [First](https://first.example.com/) and more | Text after the link | No | Yes | Yes |', 'no "[name](link)" in the first cell', null],
            'lien relatif' => ['| [Relative](/docs/api) | Relative link | No | Yes | Yes |', 'link is not an http or https address', 'Relative'],
            'autre protocole' => ['| [Ftp](ftp://files.example.com/api) | Other protocol | No | Yes | Yes |', 'link is not an http or https address', 'Ftp'],
            'courriel' => ['| [Mail](mailto:contact@example.com) | Mail link | No | Yes | Yes |', 'link is not an http or https address', 'Mail'],
        ];
    }

    #[DataProvider('unreadableLines')]
    public function testAnUnreadableLineIsSetAsideWithoutStoppingTheReading(string $line, string $reason, ?string $name): void
    {
        $markdown = "## Index\n### Sample\n" . self::HEADER
            . "| [Before](https://before.example.com/) | Line before | No | Yes | Yes |\n"
            . $line . "\n"
            . "| [After](https://after.example.com/) | Line after | No | Yes | Yes |\n";

        $reading = MarkdownList::read($markdown, 1);

        self::assertSame(['After', 'Before'], array_map(static fn(Api $api): string => $api->name, $reading['apis']));
        self::assertSame(3, $reading['lines']);
        self::assertSame([['kind' => MarkdownList::KIND_UNREADABLE, 'line' => 6, 'reason' => $reason, 'name' => $name, 'url' => null]], $reading['notes']);
    }

    /**
     * @return array<string, array{0: string, 1: non-empty-string}>
     */
    public static function refusedLists(): array
    {
        $row = "| [One](https://one.example.com/) | First | No | Yes | Yes |\n";

        return [
            'titre « Index » absent' => ["## Contents\n### Sample\n" . self::HEADER . $row, 'Heading "## Index" not found'],
            'catégorie vide' => ["## Index\n### Sample\n" . self::HEADER . $row . "### Empty\n" . self::HEADER . "### Last\n" . self::HEADER . $row, 'Category "Empty" (line 6): no readable line in its table.'],
            'catégorie vide en fin de liste' => ["## Index\n### Sample\n" . self::HEADER . $row . "### Empty\n" . self::HEADER . "\n## License\n", 'Category "Empty" (line 6): no readable line in its table.'],
            'catégorie aux seules lignes illisibles' => ["## Index\n### Broken\n" . self::HEADER . "| No link | Description | No | Yes | Yes |\n", 'Category "Broken" (line 2): no readable line in its table.'],
            'catégorie sans tableau' => ["## Index\n### Sample\n" . self::HEADER . $row . "### Bare\nComing soon.\n", 'Category "Bare" (line 6): no table found under its heading.'],
            'catégorie sans nom' => ["## Index\n### \n" . self::HEADER . $row, 'Line 2: category without a name.'],
            'colonne en plus' => ["## Index\n### Sample\n| API | Description | Auth | HTTPS | CORS | Call this API |\n|---|---|---|---|---|---|\n" . $row, 'Line 3, category "Sample": table header "API | Description | Auth | HTTPS | CORS" expected, found "API | Description | Auth | HTTPS | CORS | Call this API".'],
            'colonnes dans un autre ordre' => ["## Index\n### Sample\n| API | Auth | Description | HTTPS | CORS |\n|---|---|---|---|---|\n" . $row, 'Line 3, category "Sample": table header'],
            'ligne avant l\'en-tête' => ["## Index\n### Sample\n" . $row . self::HEADER, 'Line 3, category "Sample": table header'],
            'aucune catégorie' => ["## Index\n\n* [Animals](#animals)\n\n## License\n", 'Empty list: no API read after "## Index"'],
            'trop peu de catégories' => ["## Index\n### Sample\n" . self::HEADER . $row, 'Safeguard: 1 categories read where at least 2 are expected'],
        ];
    }

    /**
     * @param non-empty-string $message
     */
    #[DataProvider('refusedLists')]
    public function testAListThatHasChangedShapeStopsTheReading(string $markdown, string $message): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage($message);

        MarkdownList::read($markdown, str_contains($message, 'Safeguard') ? 2 : 1);
    }

    public function testTheSampleListIsRefusedWithTheRealMinimumOfCategories(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Safeguard: 8 categories read where at least 30 are expected');

        MarkdownList::read(Workspace::markdown(), 30);
    }

    public function testNoEmailAddressIsRead(): void
    {
        $markdown = "## Index\n### Sample\n" . self::HEADER
            . "| [Mail desk](https://desk.example.com/) | Write to desk@example.com for a key | `apiKey` | Yes | Yes |\n"
            . "| [Help at help@example.com](https://help.example.com/) | Help desk | No | Yes | Yes |\n"
            . "| [Clean](https://clean.example.com/) | Nothing to remove | No | Yes | Yes |\n";

        $reading = MarkdownList::read($markdown, 1);
        $byUrl = self::byUrl($reading['apis']);

        self::assertSame('Write to ' . Text::EMAIL_REPLACEMENT . ' for a key', $byUrl['https://desk.example.com/']->description);
        self::assertSame('Help at ' . Text::EMAIL_REPLACEMENT, $byUrl['https://help.example.com/']->name);
        self::assertSame(['https://desk.example.com/' => ['description'], 'https://help.example.com/' => ['name']], $reading['emails']);
        foreach ($reading['apis'] as $api) {
            self::assertDoesNotMatchRegularExpression(Text::EMAIL, implode(' ', $api->texts()));
        }
    }

    public function testAnUnexpectedDeclaredValueIsKeptAsItIs(): void
    {
        $markdown = "## Index\n### Sample\n" . self::HEADER
            . "| [Odd](https://odd.example.com/) | Unexpected values | `Bearer token` | Partly | Maybe |\n"
            . "| [Blank](https://blank.example.com/) |  |  |  |  |\n";

        $byUrl = self::byUrl(MarkdownList::read($markdown, 1)['apis']);

        self::assertSame(['Bearer token', 'Partly', 'Maybe'], self::declared($byUrl['https://odd.example.com/']));
        self::assertSame([null, null, null], self::declared($byUrl['https://blank.example.com/']));
        self::assertNull($byUrl['https://blank.example.com/']->description);
    }

    public function testLinksAreNormalisedAndTextsCleaned(): void
    {
        $markdown = "## Index\r\n### Sample  \r\n" . str_replace("\n", "\r\n", self::HEADER)
            . "|  [Mixed Case](HTTPS://Docs.Example.COM)  |  Spaces   around,\ttabs inside  | `apiKey` | Yes | Unknown |\r\n"
            . "| [Escaped](https://escaped.example.com/a_(b)) | Stocks \\| bonds | No | Yes | Yes |\r\n"
            . "| [Anchor](https://docs.example.com/#section ) | Same page, other section | No | Yes | Yes |\r\n"
            . '| [Long](https://long.example.com/) | ' . str_repeat('word ', 80) . "| No | Yes | Yes |\r\n";

        $reading = MarkdownList::read($markdown, 1);
        $byUrl = self::byUrl($reading['apis']);

        self::assertSame([], $reading['notes']);
        self::assertSame('Sample', $byUrl['https://docs.example.com/']->category);
        self::assertSame('Mixed Case', $byUrl['https://docs.example.com/']->name);
        self::assertSame('Spaces around, tabs inside', $byUrl['https://docs.example.com/']->description);
        self::assertSame('docs.example.com', $byUrl['https://docs.example.com/']->host());
        self::assertSame('Stocks | bonds', $byUrl['https://escaped.example.com/a_(b)']->description, 'barre verticale échappée');
        self::assertSame('https://docs.example.com/', $byUrl['https://docs.example.com/#section']->testUrl(), 'deux entrées, une seule adresse appelée');
        $long = $byUrl['https://long.example.com/']->description;
        self::assertNotNull($long);
        self::assertLessThanOrEqual(Api::DESCRIPTION_LENGTH, mb_strlen($long));
        self::assertStringEndsWith(Text::ELLIPSIS, $long);
    }

    public function testACategoryMayAppearTwice(): void
    {
        $markdown = "## Index\n### Sample\n" . self::HEADER . "| [One](https://one.example.com/) | First | No | Yes | Yes |\n"
            . "### Other\n" . self::HEADER . "| [Two](https://two.example.com/) | Second | No | Yes | Yes |\n"
            . "### Sample\n" . self::HEADER . "| [Three](https://three.example.com/) | Third | No | Yes | Yes |\n";

        $reading = MarkdownList::read($markdown, 2);

        self::assertSame(2, $reading['categories']);
        self::assertSame(['Other' => 1, 'Sample' => 2], self::perCategory($reading['apis']));
    }

    /**
     * @param list<Api> $apis
     *
     * @return array<string, Api>
     */
    private static function byUrl(array $apis): array
    {
        $byUrl = [];
        foreach ($apis as $api) {
            $byUrl[$api->url] = $api;
        }

        return $byUrl;
    }

    /**
     * @param list<Api> $apis
     *
     * @return array<string, int>
     */
    private static function perCategory(array $apis): array
    {
        $counts = [];
        foreach ($apis as $api) {
            $counts[$api->category] = ($counts[$api->category] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private static function declared(Api $api): array
    {
        return [$api->auth, $api->https, $api->cors];
    }
}
