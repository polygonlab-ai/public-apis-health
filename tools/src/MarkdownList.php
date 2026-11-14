<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Lecture du fichier Markdown de la liste public-apis.
 *
 *   - seule la partie placée après le titre « ## Index » est lue, jusqu'au titre de même niveau qui suit : les
 *     sections placées avant (annonces, autres tableaux) ne sont jamais reprises ;
 *   - chaque titre « ### » ouvre une catégorie, dont le tableau a pour colonnes API, Description, Auth, HTTPS, CORS
 *     (avec ou sans barre verticale au début et à la fin) ; un autre en-tête arrête la lecture ;
 *   - une ligne du tableau donne une entrée : nom et lien dans la première cellule, puis la description et les trois
 *     valeurs déclarées, gardées telles quelles (sans les accents graves) ;
 *   - des cellules vides en trop à la fin d'une ligne sont ignorées, comme le fait l'affichage du tableau ;
 *   - une ligne illisible (nombre de cellules inattendu, lien absent, lien qui n'est pas une adresse http ou https)
 *     est écartée et notée, sans arrêter la lecture ;
 *   - la clé d'une entrée est son adresse normalisée (voir Address) : deux lignes de même adresse, la première est
 *     gardée, l'autre est notée ;
 *   - aucune adresse e-mail n'est reprise : dans un texte elle est remplacée par « [address removed] ».
 *
 * Arrêtent la lecture (SourceError) : titre « ## Index » absent, catégorie sans nom, sans tableau ou sans aucune
 * ligne lisible, en-tête de tableau inattendu, moins de catégories que le minimum attendu, liste vide.
 *
 * @phpstan-type Note array{kind: string, line: int, reason: string, name: string|null, url: string|null}
 * @phpstan-type Reading array{apis: list<Api>, lines: int, categories: int, notes: list<Note>, emails: array<string, list<string>>}
 */
final class MarkdownList
{
    public const INDEX = 'Index';

    public const COLUMNS = ['API', 'Description', 'Auth', 'HTTPS', 'CORS'];

    public const KIND_UNREADABLE = 'unreadable';

    public const KIND_DUPLICATE = 'duplicate';

    /** Première cellule d'une ligne : « [nom](adresse) », rien d'autre. */
    private const LINK = '/\A\[((?:(?!\]\().)+)\]\(\s*(\S+?)\s*\)\z/s';

    private const HEADING = '/\A {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*\z/';

    /**
     * @return Reading entrées triées par adresse, lignes d'API lues, catégories lues, lignes écartées et liens en
     *         double, champs dont une adresse e-mail a été retirée (par adresse)
     *
     * @throws SourceError
     */
    public static function read(string $markdown, int $minCategories): array
    {
        $rows = preg_split('/\r\n|\r|\n/', $markdown);
        $inIndex = false;
        $found = false;
        $category = null;
        $categoryLine = 0;
        $hasHeader = false;
        $readable = 0;
        $lines = 0;
        /** @var array<string, true> $categories */
        $categories = [];
        /** @var array<string, array{api: Api, line: int, emails: list<string>}> $byUrl */
        $byUrl = [];
        /** @var list<Note> $notes */
        $notes = [];

        foreach ($rows === false ? [] : $rows as $index => $row) {
            $number = $index + 1;
            $heading = self::heading($row);
            if ($heading !== null && $heading['level'] <= 2) {
                if ($inIndex) {
                    break;
                }
                $inIndex = strcasecmp($heading['text'], self::INDEX) === 0;
                $found = $found || $inIndex;
                continue;
            }
            if (!$inIndex) {
                continue;
            }
            if ($heading !== null && $heading['level'] === 3) {
                self::close($category, $categoryLine, $hasHeader, $readable);
                if ($heading['text'] === '') {
                    throw new SourceError(sprintf('Line %d: category without a name.', $number));
                }
                $category = Text::withoutEmails($heading['text']);
                $categoryLine = $number;
                $hasHeader = false;
                $readable = 0;
                $categories[$category] = true;
                continue;
            }
            if ($category === null) {
                continue;
            }
            $cells = self::cells($row);
            if ($cells === null || self::isDelimiter($cells)) {
                continue;
            }
            if (self::isHeader($cells)) {
                $hasHeader = true;
                continue;
            }
            if (!$hasHeader) {
                throw new SourceError(sprintf(
                    'Line %d, category "%s": table header "%s" expected, found "%s".',
                    $number,
                    $category,
                    implode(' | ', self::COLUMNS),
                    mb_strimwidth(Text::withoutEmails(implode(' | ', $cells)), 0, 120, Text::ELLIPSIS),
                ));
            }

            $lines++;
            $entry = self::entry($cells, $category);
            if (\is_string($entry)) {
                $link = self::link($cells[0] ?? '');
                $notes[] = ['kind' => self::KIND_UNREADABLE, 'line' => $number, 'reason' => $entry, 'name' => $link['name'] ?? null, 'url' => null];
                continue;
            }
            $readable++;
            $api = $entry['api'];
            $first = $byUrl[$api->url] ?? null;
            if ($first !== null) {
                $notes[] = [
                    'kind' => self::KIND_DUPLICATE,
                    'line' => $number,
                    'reason' => sprintf('same link as "%s" (line %d)', $first['api']->name, $first['line']),
                    'name' => $api->name,
                    'url' => $api->url,
                ];
                continue;
            }
            $byUrl[$api->url] = ['api' => $api, 'line' => $number, 'emails' => $entry['emails']];
        }
        if (!$found) {
            throw new SourceError('Heading "## ' . self::INDEX . '" not found: the list has changed shape, nothing was read.');
        }
        self::close($category, $categoryLine, $hasHeader, $readable);
        if ($byUrl === []) {
            throw new SourceError(sprintf('Empty list: no API read after "## %s" (%d line(s) set aside).', self::INDEX, \count($notes)));
        }
        if (\count($categories) < $minCategories) {
            throw new SourceError(sprintf('Safeguard: %d categories read where at least %d are expected: the list has changed shape.', \count($categories), $minCategories));
        }

        ksort($byUrl, SORT_STRING);
        $apis = [];
        $emails = [];
        foreach ($byUrl as $url => $entry) {
            $apis[] = $entry['api'];
            if ($entry['emails'] !== []) {
                $emails[(string) $url] = $entry['emails'];
            }
        }

        return ['apis' => $apis, 'lines' => $lines, 'categories' => \count($categories), 'notes' => $notes, 'emails' => $emails];
    }

    /**
     * Fin d'une catégorie : elle doit avoir eu son tableau et au moins une ligne lisible.
     *
     * @throws SourceError
     */
    private static function close(?string $category, int $line, bool $hasHeader, int $readable): void
    {
        if ($category === null) {
            return;
        }
        if (!$hasHeader) {
            throw new SourceError(sprintf('Category "%s" (line %d): no table found under its heading.', $category, $line));
        }
        if ($readable === 0) {
            throw new SourceError(sprintf('Category "%s" (line %d): no readable line in its table.', $category, $line));
        }
    }

    /**
     * Entrée d'une ligne du tableau, ou la raison (texte) pour laquelle la ligne est illisible.
     *
     * @param list<string> $cells
     *
     * @return array{api: Api, emails: list<string>}|string
     */
    private static function entry(array $cells, string $category): array|string
    {
        if (\count($cells) !== \count(self::COLUMNS)) {
            return sprintf('%d cells where %d are expected', \count($cells), \count(self::COLUMNS));
        }
        $link = self::link($cells[0]);
        if ($link === null) {
            return 'no "[name](link)" in the first cell';
        }
        $url = Address::normalize($link['url']);
        if ($url === null || Address::hostOf($url) === null) {
            return 'link is not an http or https address';
        }

        /** @var list<string> $emails */
        $emails = [];
        $text = static function (string $value, string $field) use (&$emails): ?string {
            if ($value === '') {
                return null;
            }
            if (!Text::hasEmail($value)) {
                return $value;
            }
            $emails[] = $field;

            return Text::withoutEmails($value);
        };

        $api = new Api(
            $text($link['name'], 'name') ?? '',
            $url,
            Text::truncate($text($cells[1], 'description'), Api::DESCRIPTION_LENGTH),
            $category,
            $text(self::declared($cells[2]), 'auth'),
            $text(self::declared($cells[3]), 'https'),
            $text(self::declared($cells[4]), 'cors'),
        );

        return ['api' => $api, 'emails' => $emails];
    }

    /**
     * Nom et adresse du lien d'une première cellule, ou null si elle n'est pas de la forme « [nom](adresse) ».
     *
     * @return array{name: string, url: string}|null
     */
    private static function link(string $cell): ?array
    {
        if (preg_match(self::LINK, $cell, $match) !== 1) {
            return null;
        }
        $name = Text::clean($match[1]);

        return $name === null ? null : ['name' => $name, 'url' => $match[2]];
    }

    /**
     * Valeur déclarée d'une cellule « Auth », « HTTPS » ou « CORS » : le texte sans ses accents graves.
     */
    private static function declared(string $cell): string
    {
        return Text::clean(str_replace('`', '', $cell)) ?? '';
    }

    /**
     * Titre Markdown d'une ligne (« ## Texte ») : son niveau et son texte nettoyé, ou null.
     *
     * @return array{level: int, text: string}|null
     */
    private static function heading(string $row): ?array
    {
        if (preg_match(self::HEADING, rtrim($row), $match) !== 1) {
            return null;
        }

        return ['level' => \strlen($match[1]), 'text' => Text::clean($match[2] ?? '') ?? ''];
    }

    /**
     * Cellules d'une ligne de tableau (texte nettoyé), ou null si la ligne ne contient aucune barre verticale. Une
     * barre verticale précédée d'une barre oblique inverse fait partie du texte. Les cellules vides placées à la fin,
     * au-delà du nombre de colonnes attendu, sont ignorées : c'est le reste d'une colonne supprimée, que l'affichage
     * du tableau ignore aussi.
     *
     * @return list<string>|null
     */
    private static function cells(string $row): ?array
    {
        $row = trim($row);
        $parts = preg_split('/(?<!\\\\)\|/', $row);
        if ($parts === false || \count($parts) < 2) {
            return null;
        }
        if (trim($parts[0]) === '') {
            array_shift($parts);
        }
        if (trim($parts[\count($parts) - 1]) === '') {
            array_pop($parts);
        }
        $cells = [];
        foreach ($parts as $part) {
            $cells[] = Text::clean(str_replace('\\|', '|', $part)) ?? '';
        }
        while (\count($cells) > \count(self::COLUMNS) && $cells[\count($cells) - 1] === '') {
            array_pop($cells);
        }

        return $cells === [] ? null : $cells;
    }

    /**
     * Ligne de séparation d'un tableau (« |:---|:---| »).
     *
     * @param list<string> $cells
     */
    private static function isDelimiter(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (preg_match('/\A:?-+:?\z/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * En-tête attendu d'un tableau : exactement les cinq colonnes, sans tenir compte des majuscules.
     *
     * @param list<string> $cells
     */
    private static function isHeader(array $cells): bool
    {
        return array_map(strtolower(...), $cells) === array_map(strtolower(...), self::COLUMNS);
    }
}
