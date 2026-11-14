<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Compare la liste issue de la collecte à la version en place dans data/ et prépare les nouveaux fichiers, le bloc
 * du README, la page LIST.md et le résumé des changements.
 *
 * Rien n'est écrit ici : Updater::run() rend le contenu des fichiers (vide si rien n'a changé) et le résumé, repris
 * par le journal des changements et le message d'information.
 *
 * Événements de l'historique : ajout, retrait, changement de résultat de test, entrée écartée (mot interdit, adresse
 * IPv4… : voir Screening).
 *
 * Garde-fous (SourceError, rien n'est écrit) : liste vide ; plus de 20 % des entrées disparaissent d'un coup ; plus
 * de 25 % des entrées présentes aux deux passages changent de classe de test (panne de notre côté probable).
 *
 * Les clés du résumé (« titre », « sections », « listes »…) et celles de data/source.json lues par le script de
 * publication (« lu_le », « fichiers », « cle ») sont en français : c'est le format commun aux dépôts de données de
 * PolygonLab. Leurs valeurs, reprises dans le journal des changements, sont en anglais.
 *
 * @phpstan-import-type Event from DataFiles
 * @phpstan-import-type Discarded from Screening
 * @phpstan-import-type Note from MarkdownList
 * @phpstan-type Collected array{apis: list<Api>, sha256: string, size: int, url: string, path: string, source_sha256: string, source_size: int, lines: int, categories: int, tested: int, requests: int, duration: int, notes: list<Note>, emails: array<string, list<string>>}
 * @phpstan-type Section array{titre: string, elements: list<string>}
 */
final class Updater
{
    public const SUMMARY_FORMAT = 1;
    public const REPOSITORY = 'public-apis-health';
    public const TIMEZONE = 'Europe/Paris';

    /** Unité des garde-fous du script de publication. */
    public const UNIT = 'API';

    public const LIST_KEY = 'apis';
    public const LIST_LABEL = 'APIs';

    /** Nombre maximal d'éléments cités dans une section du résumé ; la suite est dans data/history.csv. */
    private const MAX_ELEMENTS = 100;

    /** Longueur maximale du titre d'une version (limite du journal des changements). */
    private const MAX_TITLE = 100;

    public function __construct(
        private readonly Sources $sources,
        private readonly Screening $screening,
        private readonly string $dataDirectory,
    ) {}

    /**
     * @param Collected $collected
     *
     * @return array{changed: bool, files: array<string, string>, readme: string, list: string, summary: array<string, mixed>}
     *
     * @throws SourceError
     */
    public function run(array $collected, \DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $today = $now->format('Y-m-d');
        $previousSource = $this->previousSource();
        $previous = DataFiles::readApis($this->read(DataFiles::APIS_JSON));
        $history = $this->read(DataFiles::HISTORY_CSV);
        $runs = DataFiles::readRuns($this->read(DataFiles::RUNS_CSV));
        $firstRead = $previous === [] && ($previousSource['fichiers'] ?? []) === [];

        $screened = $this->screening->screen($collected['apis']);
        if ($screened['kept'] === []) {
            throw new SourceError(sprintf(
                'Empty list: no publishable API among the %d read in the list (%d discarded).',
                \count($collected['apis']),
                \count($screened['discarded']),
            ));
        }
        $current = [];
        foreach ($screened['kept'] as $api) {
            $current[$api->url] = $api;
        }

        [$events, $others, $exits, $touched, $testChanges, $common] = $this->compare($previous, $current, $screened['discarded'], DataFiles::discardedUrls($history), $today);
        $before = \count($previous);
        if ($before > 0 && $exits * 100 > $before * $this->sources->maxDropPercent) {
            throw new SourceError(sprintf(
                'Safeguard: %d APIs out of %d disappear at once (%d %%, threshold %d %%).',
                $exits,
                $before,
                intdiv($exits * 100, $before),
                $this->sources->maxDropPercent,
            ));
        }
        if ($common > 0 && $testChanges * 100 > $common * $this->sources->maxChangedPercent) {
            throw new SourceError(sprintf(
                'Safeguard: %d APIs out of %d change test result from one run to the next (%d %%, threshold %d %%): probable failure on the side of the tool or of its network.',
                $testChanges,
                $common,
                intdiv($testChanges * 100, $common),
                $this->sources->maxChangedPercent,
            ));
        }
        $changed = $firstRead || $events !== [] || $others !== [];

        $list = array_values($current);
        $notes = $this->notes($collected['notes']);
        $summary = $this->summary($firstRead, $changed, $before, $list, $events, $others, $exits, $touched, $collected['emails'], $notes, self::previousNotes($previousSource), $today, $now->format(DATE_ATOM));
        if (!$changed) {
            return ['changed' => false, 'files' => [], 'readme' => '', 'list' => '', 'summary' => $summary];
        }

        $runs = DataFiles::withRun($runs, $today, $list, $this->sources->runs);
        $availability = DataFiles::availability($runs, $this->sources->minRuns);
        $unreadable = \count(array_filter($notes, static fn(array $note): bool => $note['kind'] === MarkdownList::KIND_UNREADABLE));

        return [
            'changed' => true,
            'files' => [
                DataFiles::APIS_JSON => DataFiles::apisJson($this->sources, $today, $list, $availability),
                DataFiles::APIS_CSV => DataFiles::apisCsv($today, $list, $availability),
                DataFiles::HOSTS_JSON => DataFiles::hostsJson($this->sources, $today, $list),
                DataFiles::HOSTS_CSV => DataFiles::hostsCsv($list),
                DataFiles::HISTORY_CSV => DataFiles::appendHistory($history, $events),
                DataFiles::RUNS_CSV => DataFiles::runsCsv($runs),
                DataFiles::SOURCE_JSON => JsonFile::encode([
                    'source' => $this->sources->source,
                    'source_page' => $this->sources->page,
                    'lu_le' => $now->format(DATE_ATOM),
                    'source_date' => $today,
                    'fichiers' => [[
                        'cle' => Sources::KEY,
                        'url' => $collected['url'],
                        'sha256' => $collected['sha256'],
                        'size' => $collected['size'],
                        'source_sha256' => $collected['source_sha256'],
                        'source_size' => $collected['source_size'],
                        'categories_read' => $collected['categories'],
                        'lines_read' => $collected['lines'],
                        'lines_set_aside' => $unreadable,
                        'duplicate_links' => \count($notes) - $unreadable,
                        'apis_read' => \count($collected['apis']),
                        'apis_discarded' => \count($screened['discarded']),
                        'urls_tested' => $collected['tested'],
                        'requests' => $collected['requests'],
                        'test_duration_s' => $collected['duration'],
                        'set_aside' => $notes,
                    ]],
                ]),
            ],
            'readme' => Readme::block($today, $list),
            'list' => ListPage::render($today, $list),
            'summary' => $summary,
        ];
    }

    /**
     * Fichiers vides mais valides, pour un dépôt neuf.
     *
     * @return array<string, string>
     */
    public static function emptyFiles(Sources $sources): array
    {
        return [
            DataFiles::APIS_JSON => DataFiles::apisJson($sources, null, [], []),
            DataFiles::APIS_CSV => DataFiles::apisCsv(null, [], []),
            DataFiles::HOSTS_JSON => DataFiles::hostsJson($sources, null, []),
            DataFiles::HOSTS_CSV => DataFiles::hostsCsv([]),
            DataFiles::HISTORY_CSV => DataFiles::historyHeader(),
            DataFiles::RUNS_CSV => DataFiles::runsHeader(),
            DataFiles::SOURCE_JSON => JsonFile::encode([
                'source' => $sources->source,
                'source_page' => $sources->page,
                'lu_le' => null,
                'source_date' => null,
                'fichiers' => [],
            ]),
        ];
    }

    public static function plural(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count > 1 ? $plural : $singular);
    }

    /**
     * Valeurs inattendues des colonnes déclaratives (« Auth », « HTTPS », « CORS »), gardées telles quelles, avec
     * leur nombre : « auth "Token": 2 ». Une cellule vide est notée « (empty) ».
     *
     * @param list<Api> $apis
     *
     * @return list<string>
     */
    public static function unexpectedValues(array $apis): array
    {
        $counts = [];
        foreach ($apis as $api) {
            foreach (['auth' => $api->auth, 'https' => $api->https, 'cors' => $api->cors] as $field => $value) {
                if ($value === null || !\in_array($value, Api::EXPECTED[$field], true)) {
                    $label = $field . ' ' . ($value === null ? '(empty)' : '"' . $value . '"');
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            }
        }
        ksort($counts, SORT_STRING);
        $values = [];
        foreach ($counts as $label => $count) {
            $values[] = $label . ': ' . $count;
        }

        return $values;
    }

    /**
     * @param array<string, Api> $previous
     * @param array<string, Api> $current
     * @param list<Discarded> $discarded
     * @param list<string> $alreadyDiscarded
     *
     * @return array{0: list<Event>, 1: list<string>, 2: int, 3: list<string>, 4: int, 5: int} événements, autres
     *         modifications, retraits de la liste, adresses des entrées ajoutées ou modifiées, changements de classe
     *         de test, entrées présentes aux deux passages
     */
    private function compare(array $previous, array $current, array $discarded, array $alreadyDiscarded, string $date): array
    {
        $events = [];
        $others = [];
        $touched = [];
        $testChanges = 0;
        $common = 0;
        foreach (DataFiles::sorted(array_values($current)) as $api) {
            $before = $previous[$api->url] ?? null;
            if ($before === null) {
                $events[] = self::event($date, DataFiles::EVENT_ADDED, $api->url, $api->name, $api->category . ', test ' . $api->test);
                $touched[] = $api->url;
                continue;
            }
            $common++;
            if ($before->test !== $api->test) {
                $testChanges++;
                $events[] = self::event($date, DataFiles::EVENT_TEST, $api->url, $api->name, $before->test . ' -> ' . $api->test);
            }
            $fields = [];
            $old = $before->card();
            $new = $api->card();
            foreach (Api::COMPARED as $field) {
                if ($old[$field] !== $new[$field]) {
                    $fields[] = $field;
                }
            }
            if ($fields !== []) {
                $others[] = $api->name . ' (' . $api->url . '): ' . implode(', ', $fields);
                $touched[] = $api->url;
            }
        }

        $discardedByUrl = [];
        foreach ($discarded as $item) {
            $discardedByUrl[$item['api']->url] = $item;
        }
        $exits = 0;
        foreach (DataFiles::sorted(array_values(array_diff_key($previous, $current))) as $api) {
            $exits++;
            $item = $discardedByUrl[$api->url] ?? null;
            $events[] = $item === null
                ? self::event($date, DataFiles::EVENT_REMOVED, $api->url, $api->name, 'no longer in the list')
                : self::event($date, DataFiles::EVENT_DISCARDED, $item['url'], $item['name'], 'discarded: ' . $item['reason']);
        }
        foreach ($discarded as $item) {
            if (!isset($previous[$item['api']->url]) && !\in_array($item['url'], $alreadyDiscarded, true)) {
                $events[] = self::event($date, DataFiles::EVENT_DISCARDED, $item['url'], $item['name'], 'discarded: ' . $item['reason']);
            }
        }

        return [$events, $others, $exits, $touched, $testChanges, $common];
    }

    /**
     * @return Event
     */
    private static function event(string $date, string $type, string $url, string $name, string $detail): array
    {
        return ['date' => $date, 'event' => $type, 'url' => $url, 'name' => $name, 'detail' => $detail];
    }

    /**
     * Lignes écartées à la lecture et liens en double, tels qu'ils peuvent être publiés : un nom ou une adresse
     * refusés par Screening sont retirés de la note.
     *
     * @param list<Note> $notes
     *
     * @return list<Note>
     */
    private function notes(array $notes): array
    {
        $published = [];
        foreach ($notes as $note) {
            $name = $note['name'];
            $url = $note['url'];
            $published[] = [
                'kind' => $note['kind'],
                'line' => $note['line'],
                'reason' => $this->screening->isSafe($note['reason']) ? $note['reason'] : 'reason withheld',
                'name' => $name !== null && $this->screening->isSafe($name) ? $name : null,
                'url' => $url !== null && $this->screening->isSafe($url) ? $url : null,
            ];
        }

        return $published;
    }

    /**
     * Notes de la lecture précédente, d'après data/source.json, réduites à ce qui les identifie d'un passage à
     * l'autre (le numéro de ligne change dès qu'une ligne est ajoutée plus haut dans la liste).
     *
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    private static function previousNotes(array $source): array
    {
        $identities = [];
        $files = $source['fichiers'] ?? null;
        foreach (\is_array($files) ? $files : [] as $file) {
            $notes = \is_array($file) ? ($file['set_aside'] ?? null) : null;
            foreach (\is_array($notes) ? $notes : [] as $note) {
                if (\is_array($note)) {
                    $kind = $note['kind'] ?? null;
                    $name = $note['name'] ?? null;
                    $url = $note['url'] ?? null;
                    $identities[] = self::identity(\is_string($kind) ? $kind : '', \is_string($name) ? $name : null, \is_string($url) ? $url : null);
                }
            }
        }

        return $identities;
    }

    private static function identity(string $kind, ?string $name, ?string $url): string
    {
        return $kind . "\0" . ($name ?? '') . "\0" . ($url ?? '');
    }

    /**
     * Résumé des changements : compteurs (garde-fous), sections (journal des changements, message), titre.
     *
     * @param list<Api> $current
     * @param list<Event> $events
     * @param list<string> $others
     * @param list<string> $touched
     * @param array<string, list<string>> $emails champs dont une adresse e-mail a été retirée, par adresse
     * @param list<Note> $notes lignes écartées à la lecture et liens en double
     * @param list<string> $knownNotes notes déjà signalées au passage précédent
     *
     * @return array<string, mixed>
     */
    private function summary(bool $firstRead, bool $changed, int $before, array $current, array $events, array $others, int $exits, array $touched, array $emails, array $notes, array $knownNotes, string $sourceDate, string $readAt): array
    {
        /** @var array<string, list<string>> $byType */
        $byType = array_fill_keys([DataFiles::EVENT_ADDED, DataFiles::EVENT_REMOVED, DataFiles::EVENT_TEST, DataFiles::EVENT_DISCARDED], []);
        foreach ($events as $event) {
            $byType[$event['event']][] = $event['name'] . ' (' . $event['url'] . '): ' . $event['detail'];
        }
        $hosts = \count(DataFiles::hosts($current));
        $categories = \count(DataFiles::categories($current));

        $sections = [];
        if ($firstRead) {
            $title = sprintf('First reading of the list on %s: %s, %s', $sourceDate, self::plural(\count($current), 'API', 'APIs'), self::plural($hosts, 'host', 'hosts'));
            if ($byType[DataFiles::EVENT_DISCARDED] !== []) {
                $sections[] = self::section('Discarded automatically', $byType[DataFiles::EVENT_DISCARDED]);
            }
        } else {
            $parts = [];
            $labels = [
                DataFiles::EVENT_ADDED => ['added', 'added', 'Added'],
                DataFiles::EVENT_REMOVED => ['removed', 'removed', 'Removed'],
                DataFiles::EVENT_TEST => ['test result changed', 'test results changed', 'Test result changes'],
                DataFiles::EVENT_DISCARDED => ['discarded', 'discarded', 'Discarded automatically'],
            ];
            foreach ($labels as $type => [$singular, $pluralLabel, $sectionTitle]) {
                if ($byType[$type] !== []) {
                    $parts[] = self::plural(\count($byType[$type]), $singular, $pluralLabel);
                    $sections[] = self::section($sectionTitle, $byType[$type]);
                }
            }
            if ($others !== []) {
                $parts[] = self::plural(\count($others), 'other change', 'other changes');
                $sections[] = self::section('Other changes', $others, false);
            }
            $title = $changed
                ? sprintf('List of %s: %s', $sourceDate, implode(', ', $parts))
                : sprintf('List of %s: no change', $sourceDate);
            if (mb_strlen($title) > self::MAX_TITLE) {
                $title = sprintf('List of %s: %d changes', $sourceDate, \count($events) + \count($others));
            }
        }

        // Lignes écartées à la lecture : seules celles qui n'étaient pas déjà signalées au passage précédent sont
        // citées ; la liste complète est dans data/source.json.
        $unreadable = 0;
        $newNotes = [];
        foreach ($notes as $note) {
            $unreadable += $note['kind'] === MarkdownList::KIND_UNREADABLE ? 1 : 0;
            if (!\in_array(self::identity($note['kind'], $note['name'], $note['url']), $knownNotes, true)) {
                $newNotes[] = sprintf(
                    'Line %d, %s: %s',
                    $note['line'],
                    $note['name'] ?? 'name withheld',
                    $note['kind'] === MarkdownList::KIND_DUPLICATE ? 'duplicate link set aside, ' . $note['reason'] : 'unreadable line set aside, ' . $note['reason'],
                );
            }
        }
        if ($newNotes !== []) {
            $sections[] = self::section('Set aside at reading', $newNotes, false);
        }

        $remarks = [];
        foreach ($current as $api) {
            $fields = $emails[$api->url] ?? [];
            if ($fields !== [] && \in_array($api->url, $touched, true)) {
                $remarks[] = 'E-mail address removed (' . implode(', ', $fields) . '): ' . $api->name;
            }
        }
        if ($remarks !== []) {
            $sections[] = self::section('Remarks', $remarks, false);
        }

        $tests = [];
        foreach ($current as $api) {
            $tests[$api->test] = ($tests[$api->test] ?? 0) + 1;
        }
        $lists = [self::LIST_LABEL . ': ' . \count($current), 'Hosts: ' . $hosts, 'Categories: ' . $categories];
        foreach (TestClass::ALL as $class) {
            if (isset($tests[$class])) {
                $lists[] = 'Test ' . $class . ': ' . $tests[$class];
            }
        }
        $lists[] = 'Unreadable lines set aside: ' . $unreadable;
        $lists[] = 'Duplicate links set aside: ' . (\count($notes) - $unreadable);
        foreach (self::unexpectedValues($current) as $value) {
            $lists[] = 'Unexpected value, ' . $value;
        }
        $sections[] = ['titre' => 'Lists', 'elements' => $lists];

        $text = [$title];
        foreach ($sections as $section) {
            $text[] = '';
            $text[] = $section['titre'];
            foreach ($section['elements'] as $element) {
                $text[] = '- ' . $element;
            }
        }

        return [
            'format' => self::SUMMARY_FORMAT,
            'depot' => self::REPOSITORY,
            'change' => $changed,
            'premiere_lecture' => $firstRead,
            'date_source' => $sourceDate,
            'lu_le' => $readAt,
            'avant' => $before,
            'apres' => \count($current),
            'retraits' => $exits,
            'unite' => self::UNIT,
            'listes' => [['cle' => self::LIST_KEY, 'libelle' => self::LIST_LABEL, 'nombre' => \count($current)]],
            'titre' => $title,
            'sections' => $sections,
            'texte' => implode("\n", $text),
        ];
    }

    /**
     * Section du résumé, limitée à ses premiers éléments ; le détail complet des événements est dans
     * data/history.csv.
     *
     * @param list<string> $elements
     *
     * @return Section
     */
    private static function section(string $title, array $elements, bool $inHistory = true): array
    {
        $count = \count($elements);
        if ($count > self::MAX_ELEMENTS) {
            $elements = \array_slice($elements, 0, self::MAX_ELEMENTS);
            $elements[] = sprintf('… and %d more', $count - self::MAX_ELEMENTS) . ($inHistory ? ': see data/history.csv' : '');
        }

        return ['titre' => $title . ($count > self::MAX_ELEMENTS ? ' (' . $count . ')' : ''), 'elements' => $elements];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SourceError
     */
    private function previousSource(): array
    {
        $content = $this->read(DataFiles::SOURCE_JSON);

        return $content === '' ? [] : JsonFile::decode($content, 'data/' . DataFiles::SOURCE_JSON);
    }

    private function read(string $name): string
    {
        $path = $this->dataDirectory . '/' . $name;
        if (!is_file($path)) {
            return $name === DataFiles::APIS_JSON ? '{"apis": []}' : '';
        }

        return (string) file_get_contents($path);
    }
}
