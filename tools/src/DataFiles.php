<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Lecture et écriture des fichiers du dossier data/.
 *
 *   apis.json    liste courante, clés dans un ordre fixe, entrées triées par catégorie, nom puis adresse
 *   apis.csv     même contenu, une ligne par entrée, séparateur « , », UTF-8 sans BOM
 *   hosts.json   une entrée par hôte : nombre d'entrées, catégories, résultat du test, entrées qui répondent
 *   hosts.csv    même contenu, une ligne par hôte, listes aplaties (« a | b »)
 *   history.csv  un événement par ligne (ajout, retrait, changement de résultat de test, entrée écartée) ; ne fait
 *                que s'allonger
 *   runs.csv     résultat du test de chaque entrée aux derniers passages (8 au plus) : base de la disponibilité
 *                observée
 *   source.json  adresse lue, date de lecture, lignes lues et écartées, adresses testées, durée du test, empreinte
 *                SHA-256 des données normalisées
 *
 * Le rendu est déterministe : mêmes données, mêmes octets. Aucune adresse e-mail n'est écrite.
 *
 * @phpstan-type Event array{date: string, event: string, url: string, name: string, detail: string}
 * @phpstan-type Host array{host: string, api_count: int, categories: list<string>, result: string, responding_count: int}
 * @phpstan-type Runs array<string, array<string, string>>
 */
final class DataFiles
{
    public const APIS_JSON = 'apis.json';
    public const APIS_CSV = 'apis.csv';
    public const HOSTS_JSON = 'hosts.json';
    public const HOSTS_CSV = 'hosts.csv';
    public const HISTORY_CSV = 'history.csv';
    public const RUNS_CSV = 'runs.csv';
    public const SOURCE_JSON = 'source.json';

    public const SEPARATOR = ',';
    public const LIST_SEPARATOR = ' | ';

    public const APIS_COLUMNS = [
        'name', 'url', 'host', 'description', 'category', 'auth', 'https', 'cors',
        'test_result', 'test_http_code', 'tested_on', 'observed_availability',
    ];

    public const HOSTS_COLUMNS = ['host', 'api_count', 'categories', 'result', 'responding_count'];

    public const HISTORY_COLUMNS = ['date', 'event', 'url', 'name', 'detail'];

    public const RUNS_COLUMNS = ['date', 'url', 'result'];

    public const EVENT_ADDED = 'added';
    public const EVENT_REMOVED = 'removed';
    public const EVENT_TEST = 'test_result_changed';
    public const EVENT_DISCARDED = 'discarded';

    /**
     * Enregistrement publié d'une entrée.
     *
     * @return array<string, mixed>
     */
    public static function record(Api $api, ?float $observed, ?string $testedOn): array
    {
        return $api->card() + [
            'test' => [
                'result' => $api->test,
                'http_code' => $api->httpCode,
                'tested_on' => $api->test === TestClass::UNTESTED ? null : $testedOn,
                'observed_availability' => $observed,
            ],
        ];
    }

    /**
     * @param list<Api> $apis
     * @param array<string, float> $availability disponibilité observée, par adresse (absente : null)
     */
    public static function apisJson(Sources $sources, ?string $sourceDate, array $apis, array $availability): string
    {
        /** @var array<string, int> $tests */
        $tests = array_fill_keys(TestClass::ALL, 0);
        $list = [];
        foreach (self::sorted($apis) as $api) {
            $tests[$api->test] = ($tests[$api->test] ?? 0) + 1;
            $list[] = self::record($api, $availability[$api->url] ?? null, $sourceDate);
        }
        $categories = [];
        foreach (self::categories($apis) as $name => $counts) {
            $categories[$name] = $counts['count'];
        }

        return JsonFile::encode([
            'source' => $sources->source,
            'source_page' => $sources->page,
            'source_date' => $sourceDate,
            'count' => \count($list),
            // Un objet vide s'écrit « {} », jamais « [] » : le schéma attend un objet.
            'counts' => ['by_category' => (object) $categories, 'by_test_result' => $tests],
            'host_count' => \count(self::hosts($apis)),
            'apis' => $list,
        ]);
    }

    /**
     * @param list<Api> $apis
     * @param array<string, float> $availability
     */
    public static function apisCsv(?string $sourceDate, array $apis, array $availability): string
    {
        $lines = [self::csvLine(self::APIS_COLUMNS)];
        foreach (self::sorted($apis) as $api) {
            $lines[] = self::csvLine([
                $api->name,
                $api->url,
                $api->host(),
                $api->description,
                $api->category,
                $api->auth,
                $api->https,
                $api->cors,
                $api->test,
                $api->httpCode === null ? null : (string) $api->httpCode,
                $api->test === TestClass::UNTESTED ? null : $sourceDate,
                self::number($availability[$api->url] ?? null),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Catégories dans l'ordre alphabétique, avec leur nombre d'entrées et le nombre de celles qui répondent.
     *
     * @param list<Api> $apis
     *
     * @return array<string, array{count: int, responding: int}>
     */
    public static function categories(array $apis): array
    {
        /** @var array<string, array{name: string, count: int, responding: int}> $byKey */
        $byKey = [];
        foreach ($apis as $api) {
            $key = Text::sortKey($api->category) . "\0" . $api->category;
            $entry = $byKey[$key] ?? ['name' => $api->category, 'count' => 0, 'responding' => 0];
            $entry['count']++;
            $entry['responding'] += $api->test === TestClass::RESPONDS ? 1 : 0;
            $byKey[$key] = $entry;
        }
        ksort($byKey, SORT_STRING);
        $categories = [];
        foreach ($byKey as $entry) {
            $categories[$entry['name']] = ['count' => $entry['count'], 'responding' => $entry['responding']];
        }

        return $categories;
    }

    /**
     * Hôtes des entrées, du plus chargé au moins chargé puis par nom. Le résultat d'un hôte est la plus favorable
     * des classes de ses entrées ; « responding_count » compte ses entrées de classe « responds ».
     *
     * @param list<Api> $apis
     *
     * @return list<Host>
     */
    public static function hosts(array $apis): array
    {
        /** @var array<string, array{count: int, categories: array<string, string>, classes: list<string>, responding: int}> $byHost */
        $byHost = [];
        foreach ($apis as $api) {
            $host = $api->host();
            $entry = $byHost[$host] ?? ['count' => 0, 'categories' => [], 'classes' => [], 'responding' => 0];
            $entry['count']++;
            $entry['categories'][Text::sortKey($api->category) . "\0" . $api->category] = $api->category;
            $entry['classes'][] = $api->test;
            $entry['responding'] += $api->test === TestClass::RESPONDS ? 1 : 0;
            $byHost[$host] = $entry;
        }
        $hosts = [];
        foreach ($byHost as $host => $entry) {
            ksort($entry['categories'], SORT_STRING);
            $hosts[] = [
                'host' => (string) $host,
                'api_count' => $entry['count'],
                'categories' => array_values($entry['categories']),
                'result' => TestClass::best($entry['classes']),
                'responding_count' => $entry['responding'],
            ];
        }
        usort($hosts, static fn(array $a, array $b): int => [$b['api_count'], $a['host']] <=> [$a['api_count'], $b['host']]);

        return $hosts;
    }

    /**
     * @param list<Api> $apis
     */
    public static function hostsJson(Sources $sources, ?string $sourceDate, array $apis): string
    {
        $hosts = self::hosts($apis);

        return JsonFile::encode([
            'source' => $sources->source,
            'source_page' => $sources->page,
            'source_date' => $sourceDate,
            'count' => \count($hosts),
            'hosts' => $hosts,
        ]);
    }

    /**
     * @param list<Api> $apis
     */
    public static function hostsCsv(array $apis): string
    {
        $lines = [self::csvLine(self::HOSTS_COLUMNS)];
        foreach (self::hosts($apis) as $host) {
            $lines[] = self::csvLine([
                $host['host'],
                (string) $host['api_count'],
                implode(self::LIST_SEPARATOR, $host['categories']),
                $host['result'],
                (string) $host['responding_count'],
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * En-tête seul de l'historique (fichier vide).
     *
     * @return non-empty-string
     */
    public static function historyHeader(): string
    {
        return self::csvLine(self::HISTORY_COLUMNS) . "\n";
    }

    /**
     * Historique allongé des nouveaux événements.
     *
     * @param list<Event> $events
     *
     * @throws SourceError
     */
    public static function appendHistory(string $current, array $events): string
    {
        if ($current === '') {
            $current = self::historyHeader();
        }
        if (!str_starts_with($current, self::historyHeader())) {
            throw new SourceError('data/history.csv: unexpected header, file left untouched.');
        }
        if (!str_ends_with($current, "\n")) {
            $current .= "\n";
        }
        foreach ($events as $event) {
            $current .= self::csvLine([$event['date'], $event['event'], $event['url'], $event['name'], $event['detail']]) . "\n";
        }

        return $current;
    }

    /**
     * Adresses (telles qu'écrites dans l'historique) des entrées actuellement écartées : celles dont le dernier
     * événement est « discarded ».
     *
     * @return list<string>
     *
     * @throws SourceError
     */
    public static function discardedUrls(string $history): array
    {
        $last = [];
        foreach (self::rows($history, self::historyHeader(), \count(self::HISTORY_COLUMNS), 'data/history.csv') as $cells) {
            $last[$cells[2]] = $cells[1];
        }
        $discarded = [];
        foreach ($last as $url => $event) {
            if ($event === self::EVENT_DISCARDED) {
                $discarded[] = (string) $url;
            }
        }

        return $discarded;
    }

    /**
     * En-tête seul du fichier des passages (fichier vide).
     *
     * @return non-empty-string
     */
    public static function runsHeader(): string
    {
        return self::csvLine(self::RUNS_COLUMNS) . "\n";
    }

    /**
     * Passages enregistrés : date => adresse => classe du test, du plus ancien au plus récent.
     *
     * @return Runs
     *
     * @throws SourceError
     */
    public static function readRuns(string $content): array
    {
        $runs = [];
        foreach (self::rows($content, self::runsHeader(), \count(self::RUNS_COLUMNS), 'data/runs.csv') as $cells) {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $cells[0]) !== 1 || !\in_array($cells[2], TestClass::ALL, true)) {
                throw new SourceError('data/runs.csv: unexpected line "' . mb_strimwidth(implode(self::SEPARATOR, $cells), 0, 120, Text::ELLIPSIS) . '".');
            }
            $runs[$cells[0]][$cells[1]] = $cells[2];
        }
        ksort($runs, SORT_STRING);

        return $runs;
    }

    /**
     * Passages allongés du passage du jour (qui remplace un passage de la même date), limités aux $max derniers.
     *
     * @param Runs $runs
     * @param list<Api> $apis
     *
     * @return Runs
     */
    public static function withRun(array $runs, string $date, array $apis, int $max): array
    {
        $results = [];
        foreach ($apis as $api) {
            $results[$api->url] = $api->test;
        }
        ksort($results, SORT_STRING);
        $runs[$date] = $results;
        ksort($runs, SORT_STRING);

        return \array_slice($runs, -$max, null, true);
    }

    /**
     * @param Runs $runs
     */
    public static function runsCsv(array $runs): string
    {
        $content = self::runsHeader();
        foreach ($runs as $date => $results) {
            foreach ($results as $url => $class) {
                $content .= self::csvLine([(string) $date, (string) $url, $class]) . "\n";
            }
        }

        return $content;
    }

    /**
     * Disponibilité observée de chaque entrée : part des passages enregistrés où son lien a répondu (« responds » ou
     * « access_denied »), de 0 à 1, arrondie à deux décimales. Les passages où le lien n'a pas été testé ne comptent
     * pas ; une entrée testée à moins de $min passages n'a pas de valeur.
     *
     * @param Runs $runs
     *
     * @return array<string, float>
     */
    public static function availability(array $runs, int $min): array
    {
        $tested = [];
        $answered = [];
        foreach ($runs as $results) {
            foreach ($results as $url => $class) {
                if ($class === TestClass::UNTESTED) {
                    continue;
                }
                $tested[$url] = ($tested[$url] ?? 0) + 1;
                $answered[$url] = ($answered[$url] ?? 0) + (\in_array($class, TestClass::ANSWERED, true) ? 1 : 0);
            }
        }
        $availability = [];
        foreach ($tested as $url => $count) {
            if ($count >= $min) {
                $availability[(string) $url] = round(($answered[$url] ?? 0) / $count, 2);
            }
        }

        return $availability;
    }

    /**
     * Entrées de data/apis.json, par adresse.
     *
     * @return array<string, Api>
     *
     * @throws SourceError
     */
    public static function readApis(string $content): array
    {
        $label = 'data/' . self::APIS_JSON;
        $data = JsonFile::decode($content, $label);
        $list = $data['apis'] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new SourceError($label . ': "apis" list missing.');
        }
        $apis = [];
        foreach ($list as $record) {
            if (!\is_array($record)) {
                throw new SourceError($label . ': invalid record.');
            }
            $api = Api::fromArray($record, $label);
            $apis[$api->url] = $api;
        }

        return $apis;
    }

    /**
     * Ligne CSV : valeur entre guillemets si elle contient le séparateur, un guillemet ou un retour à la ligne.
     *
     * @param list<string|null> $values
     */
    public static function csvLine(array $values): string
    {
        $cells = [];
        foreach ($values as $value) {
            $value ??= '';
            if (strpbrk($value, self::SEPARATOR . "\"\n\r") !== false) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            $cells[] = $value;
        }

        return implode(self::SEPARATOR, $cells);
    }

    /**
     * Entrées triées par catégorie, par nom (sans tenir compte des majuscules ni des accents), puis par adresse.
     *
     * @param list<Api> $apis
     *
     * @return list<Api>
     */
    public static function sorted(array $apis): array
    {
        // Les clés de tri sont calculées une fois par entrée, pas à chaque comparaison.
        $keyed = [];
        foreach ($apis as $api) {
            $keyed[] = ['keys' => [Text::sortKey($api->category), $api->category, Text::sortKey($api->name), $api->name, $api->url], 'api' => $api];
        }
        usort($keyed, static fn(array $a, array $b): int => $a['keys'] <=> $b['keys']);

        return array_column($keyed, 'api');
    }

    /**
     * Nombre écrit au plus court (« 1 », « 0.88 »), ou null.
     */
    public static function number(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = number_format($value, 2, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * Lignes d'un fichier CSV de l'outil, après son en-tête. Aucune valeur écrite par l'outil ne contient de retour à
     * la ligne (voir Text::clean) : le fichier se lit ligne par ligne.
     *
     * @return list<list<string>>
     *
     * @throws SourceError
     */
    private static function rows(string $content, string $header, int $columns, string $label): array
    {
        if ($content === '') {
            return [];
        }
        if (!str_starts_with($content, $header)) {
            throw new SourceError($label . ': unexpected header.');
        }
        $rows = [];
        foreach (explode("\n", substr($content, \strlen($header))) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = [];
            foreach (str_getcsv($line, self::SEPARATOR, '"', '') as $cell) {
                $cells[] = (string) $cell;
            }
            if (\count($cells) !== $columns) {
                throw new SourceError($label . ': unreadable line "' . mb_strimwidth($line, 0, 120, Text::ELLIPSIS) . '".');
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
