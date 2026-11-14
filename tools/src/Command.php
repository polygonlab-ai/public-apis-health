<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Commande « php tools/actualiser.php » : arguments, collecte (lecture de la liste puis test des liens), lecture du
 * manifeste, écriture des fichiers de data/, de LIST.md et des chiffres du README, résumé.
 *
 * Trois usages :
 *   - sans --collect ni --manifest : collecte puis actualisation, en une fois ;
 *   - --collect <dossier> --manifest <fichier.json> : collecte seule ; écrit dans le dossier le fichier normalisé
 *     (list.json : fiches et classes de test, sans date ni code HTTP), ses compléments (details.json : codes HTTP,
 *     compteurs, lignes écartées) et le manifeste (adresse, empreinte SHA-256 du fichier normalisé), sans toucher à
 *     data/ ;
 *   - --manifest <fichier.json> : actualisation à partir d'une collecte déjà faite.
 *
 * Les options --collecter, --manifeste, --resume, --mots-interdits et --chargeur sont les mêmes options sous le nom
 * que leur donne le script de publication commun aux dépôts de données de PolygonLab ; les clés du manifeste
 * (« fichiers », « cle », « chemin »…) et du fichier des mots interdits (« mots », « mot », « tolerees ») suivent
 * aussi son format.
 *
 * Codes de sortie : 0 succès (fichiers réécrits, ou liste inchangée) ; 1 source refusée, rien n'est écrit ; 2 usage.
 *
 * @phpstan-import-type Collected from Updater
 * @phpstan-import-type Note from MarkdownList
 * @phpstan-type Options array{collect: string|null, manifest: string|null, data: string, readme: string, list: string, summary: string|null, forbidden-words: string|null, sources: string, replay: string|null, record: string|null, help: bool}
 */
final class Command
{
    public const SUCCESS = 0;
    public const REFUSED = 1;
    public const USAGE = 2;

    /** Fichier normalisé écrit par la collecte : c'est lui qui porte l'empreinte. */
    public const COLLECTED_FILE = 'list.json';

    /** Compléments de la collecte, hors empreinte : codes HTTP, compteurs, lignes écartées. */
    public const DETAILS_FILE = 'details.json';

    public const MANIFEST_FORMAT = 1;

    public const COLLECT_FORMAT = 1;

    /** Nom de chaque option, et son autre nom dans le format commun aux dépôts de données. */
    private const ALIASES = [
        '--collecter' => '--collect',
        '--manifeste' => '--manifest',
        '--resume' => '--summary',
        '--mots-interdits' => '--forbidden-words',
        '--chargeur' => '--loader',
        '-h' => '--help',
    ];

    private const WITH_VALUE = ['--collect', '--manifest', '--data', '--readme', '--list', '--summary', '--forbidden-words', '--sources', '--replay', '--record', '--loader'];

    /**
     * @param list<string> $arguments arguments de la ligne de commande, sans le nom du script
     * @param resource $output
     * @param resource $errors
     */
    public static function run(array $arguments, $output, $errors, ?\DateTimeImmutable $now = null): int
    {
        try {
            $options = self::options($arguments);
        } catch (\InvalidArgumentException $error) {
            fwrite($errors, $error->getMessage() . "\n\n" . self::usage());

            return self::USAGE;
        }
        if ($options['help']) {
            fwrite($output, self::usage());

            return self::SUCCESS;
        }

        $temporary = null;
        try {
            $sources = Sources::load($options['sources']);
            $manifest = $options['manifest'];
            $directory = $options['collect'];
            if ($directory === null && $manifest !== null) {
                $collected = self::readManifest($manifest);
            } else {
                if ($directory === null) {
                    $temporary = sys_get_temp_dir() . '/public-apis-health-' . bin2hex(random_bytes(6));
                }
                $collected = self::collect($sources, $directory ?? (string) $temporary, $options, $output);
                if ($directory !== null) {
                    self::writeManifest((string) $manifest, $collected);
                    fwrite($output, 'Manifest written: ' . (string) $manifest . "\n");

                    return self::SUCCESS;
                }
            }

            $screening = new Screening($options['forbidden-words'] === null ? ForbiddenWords::none() : ForbiddenWords::load($options['forbidden-words']));
            $result = (new Updater($sources, $screening, $options['data']))->run($collected, $now ?? new \DateTimeImmutable('now'));
            if ($result['changed']) {
                $readme = @file_get_contents($options['readme']);
                if ($readme === false) {
                    throw new SourceError('README unreadable: ' . $options['readme'] . '.');
                }
                $files = [];
                foreach ($result['files'] as $name => $content) {
                    $files[$options['data'] . '/' . $name] = $content;
                }
                $files[$options['list']] = $result['list'];
                $files[$options['readme']] = Readme::replace($readme, $result['readme']);
                self::write($files);
            }
            if ($options['summary'] !== null && file_put_contents($options['summary'], JsonFile::encode($result['summary'])) === false) {
                throw new SourceError('The summary could not be written: ' . $options['summary'] . '.');
            }
            $text = $result['summary']['texte'];
            fwrite($output, (\is_string($text) ? $text : '') . "\n\n" . ($result['changed'] ? 'Files of data/, ' . ListPage::FILE . ' and the figures of the README rewritten.' : 'List unchanged: no file modified.') . "\n");

            return self::SUCCESS;
        } catch (SourceError $error) {
            fwrite($errors, 'Stopped: ' . $error->getMessage() . "\nNo file was written.\n");

            return self::REFUSED;
        } finally {
            if ($temporary !== null) {
                foreach (glob($temporary . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($temporary);
            }
        }
    }

    public static function usage(): string
    {
        return <<<'TXT'
            Usage: php tools/actualiser.php [options]

            Without any option, reads the public-apis list (tools/sources.json), tests each link once, compares the
            result with data/ and rewrites the files, LIST.md and the figures of the README if the list or a test
            result has changed.

              --collect <directory>         collection only: files written to this directory (with --manifest)
              --manifest <file.json>        with --collect: manifest to write; alone: collection to use
              --data <directory>            data directory (default: data/ of the repository)
              --readme <file.md>            README whose figures are rewritten (default: README.md of the repository)
              --list <file.md>              page of the list by category (default: LIST.md of the repository)
              --summary <file.json>         writes the summary of the changes as JSON
              --forbidden-words <file>      words that discard an API (JSON: {"mots": [{"mot": "..."}]})
              --sources <file.json>         settings file (default: tools/sources.json)
              --replay <directory>          replays a recorded collection (list and tests), without network
              --record <directory>          records the list read and the tests, to replay them with --replay
              --loader <file.php>           optional autoloader (default: vendor/autoload.php if it exists)
              --help                        shows this help

            Also accepted, for the publishing script shared by the PolygonLab data repositories: --collecter,
            --manifeste, --resume, --mots-interdits and --chargeur (same options, other names).

            Exit codes: 0 success, 1 source refused (nothing is written), 2 usage.

            TXT;
    }

    /**
     * Chargeur automatique désigné par --loader (lu avant toute autre option), sinon null.
     *
     * @param list<string> $arguments
     */
    public static function loader(array $arguments): ?string
    {
        foreach ($arguments as $index => $argument) {
            foreach (['--loader', '--chargeur'] as $option) {
                if ($argument === $option) {
                    return $arguments[$index + 1] ?? null;
                }
                if (str_starts_with($argument, $option . '=')) {
                    return substr($argument, \strlen($option) + 1);
                }
            }
        }

        return null;
    }

    /**
     * Collecte : lecture de la liste, test de chaque adresse distincte, garde-fou des adresses injoignables, puis
     * écriture du fichier normalisé et de ses compléments dans le dossier.
     *
     * @param Options $options
     * @param resource $output
     *
     * @return Collected
     *
     * @throws SourceError
     */
    private static function collect(Sources $sources, string $directory, array $options, $output): array
    {
        foreach (array_filter([$directory, $options['record']]) as $folder) {
            if (!is_dir($folder) && !@mkdir($folder, 0700, true)) {
                throw new SourceError('Directory could not be created: ' . $folder . '.');
            }
        }
        $replayed = $options['replay'];
        $client = $replayed !== null ? new ReplayClient($replayed) : CurlClient::fromSources($sources);
        if ($options['record'] !== null) {
            $client = new RecordingClient($client, $options['record']);
        }
        fwrite($output, 'Reading the list: ' . $sources->listUrl . "\n");
        $markdown = $client->get($sources->listUrl);
        if (\strlen($markdown) > $sources->maxSize) {
            throw new SourceError(sprintf('The list is larger than %d bytes (%d): nothing was read.', $sources->maxSize, \strlen($markdown)));
        }
        $reading = MarkdownList::read($markdown, $sources->minCategories);

        $urls = [];
        foreach ($reading['apis'] as $api) {
            $urls[$api->testUrl()] = true;
        }
        $urls = array_map(strval(...), array_keys($urls));

        // Rejeu : aucune attente réelle, le temps avance seul. Sinon : horloge et attentes réelles.
        $sleep = null;
        $clock = static fn(): float => microtime(true);
        if ($replayed !== null) {
            $replay = ProbeReplay::load($replayed);
            $transport = $replay;
            $resolver = $replay;
            $time = 0.0;
            $sleep = static function (float $seconds) use (&$time): void {
                $time += $seconds;
            };
            $clock = static function () use (&$time): float {
                return $time;
            };
        } else {
            $transport = CurlTransport::fromSources($sources);
            $resolver = new DnsResolver();
        }
        $recorder = null;
        if ($options['record'] !== null) {
            $recorder = new ProbeRecorder($transport, $resolver, $options['record']);
            $transport = $recorder;
            $resolver = $recorder;
        }
        fwrite($output, sprintf("Testing %d distinct address(es), one request per second at most per host...\n", \count($urls)));
        $start = $clock();
        $prober = new Prober($transport, $resolver, $sources, $sleep, $clock);
        $outcomes = $prober->probe($urls);
        $duration = (int) round($clock() - $start);
        $recorder?->save();

        $tested = 0;
        $unreachable = 0;
        foreach ($outcomes as $outcome) {
            $tested += $outcome->class === TestClass::UNTESTED ? 0 : 1;
            $unreachable += $outcome->class === TestClass::UNREACHABLE ? 1 : 0;
        }
        if ($tested > 0 && $unreachable * 100 > $tested * $sources->maxUnreachablePercent) {
            throw new SourceError(sprintf(
                'Safeguard: %d address(es) out of %d are unreachable (%d %%, threshold %d %%): probable network failure on the side of the tool.',
                $unreachable,
                $tested,
                intdiv($unreachable * 100, $tested),
                $sources->maxUnreachablePercent,
            ));
        }
        $apis = [];
        $codes = [];
        foreach ($reading['apis'] as $api) {
            $outcome = $outcomes[$api->testUrl()] ?? Outcome::untested('address not tested');
            $apis[] = $api->withTest($outcome->class, $outcome->code);
            if ($outcome->code !== null) {
                $codes[$api->url] = $outcome->code;
            }
        }

        $content = JsonFile::encode([
            'format' => self::COLLECT_FORMAT,
            'apis' => array_map(static fn(Api $api): array => $api->normalized(), $apis),
        ]);
        $path = rtrim($directory, '/') . '/' . self::COLLECTED_FILE;
        $collected = [
            'apis' => $apis,
            'sha256' => hash('sha256', $content),
            'size' => \strlen($content),
            'url' => $sources->listUrl,
            'path' => $path,
            'source_sha256' => hash('sha256', $markdown),
            'source_size' => \strlen($markdown),
            'lines' => $reading['lines'],
            'categories' => $reading['categories'],
            'tested' => $tested,
            'requests' => $prober->requests(),
            'duration' => $duration,
            'notes' => $reading['notes'],
            'emails' => $reading['emails'],
        ];
        $details = JsonFile::encode([
            'format' => self::COLLECT_FORMAT,
            'source_sha256' => $collected['source_sha256'],
            'source_size' => $collected['source_size'],
            'lines_read' => $collected['lines'],
            'categories_read' => $collected['categories'],
            'urls_tested' => $collected['tested'],
            'requests' => $collected['requests'],
            'test_duration_s' => $collected['duration'],
            'set_aside' => $collected['notes'],
            'emails' => (object) $collected['emails'],
            'http_codes' => (object) $codes,
        ]);
        foreach ([$path => $content, \dirname($path) . '/' . self::DETAILS_FILE => $details] as $file => $data) {
            if (file_put_contents($file, $data) !== \strlen($data)) {
                throw new SourceError('File could not be written: ' . $file . '.');
            }
        }
        fwrite($output, sprintf(
            "Collection: %d line(s) read in %d categories, %d set aside (unreadable or duplicate), %d API(s), %d address(es) tested in %d s (%d request(s)).\n",
            $collected['lines'],
            $collected['categories'],
            \count($collected['notes']),
            \count($apis),
            $tested,
            $duration,
            $collected['requests'],
        ));

        return $collected;
    }

    /**
     * @param Collected $collected
     *
     * @throws SourceError
     */
    private static function writeManifest(string $path, array $collected): void
    {
        $content = JsonFile::encode([
            'format' => self::MANIFEST_FORMAT,
            'mode' => 'collecte',
            'fichiers' => [[
                'cle' => Sources::KEY,
                'chemin' => $collected['path'],
                'url' => $collected['url'],
                'derniere_modification' => null,
                'sha256' => $collected['sha256'],
                'size' => $collected['size'],
                'lines_read' => $collected['lines'],
            ]],
        ]);
        if (file_put_contents($path, $content) !== \strlen($content)) {
            throw new SourceError('The manifest could not be written: ' . $path . '.');
        }
    }

    /**
     * Collecte décrite par un manifeste : le fichier normalisé est relu et son empreinte vérifiée, puis complété par
     * le fichier des compléments du même dossier.
     *
     * @return Collected
     *
     * @throws SourceError
     */
    private static function readManifest(string $manifest): array
    {
        $data = JsonFile::read($manifest, 'Manifest');
        $files = $data['fichiers'] ?? null;
        $entry = null;
        foreach (\is_array($files) ? $files : [] as $file) {
            if (\is_array($file) && ($file['cle'] ?? null) === Sources::KEY) {
                $entry = $file;
            }
        }
        $path = $entry['chemin'] ?? null;
        $url = $entry['url'] ?? null;
        $hash = $entry['sha256'] ?? null;
        if (!\is_string($path) || !\is_string($url) || !\is_string($hash)) {
            throw new SourceError('Manifest: entry "' . Sources::KEY . '" with "chemin", "url" and "sha256" expected.');
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new SourceError('Collected file unreadable: ' . $path . '.');
        }
        if (!hash_equals($hash, hash('sha256', $content))) {
            throw new SourceError('Collected file: SHA-256 fingerprint different from the one in the manifest.');
        }
        $label = 'Collected file';
        $normalized = JsonFile::decode($content, $label);
        $list = $normalized['apis'] ?? null;
        if (($normalized['format'] ?? null) !== self::COLLECT_FORMAT || !\is_array($list) || !array_is_list($list)) {
            throw new SourceError($label . ': format ' . self::COLLECT_FORMAT . ' and "apis" list expected.');
        }
        $label = 'Details of the collection';
        $details = JsonFile::read(\dirname($path) . '/' . self::DETAILS_FILE, $label);
        $counts = [];
        foreach (['source_size', 'lines_read', 'categories_read', 'urls_tested', 'requests', 'test_duration_s'] as $field) {
            $value = $details[$field] ?? null;
            if (!\is_int($value) || $value < 0) {
                throw new SourceError($label . ': "' . $field . '" (integer) expected.');
            }
            $counts[$field] = $value;
        }
        $sourceHash = $details['source_sha256'] ?? null;
        if (!\is_string($sourceHash) || preg_match('/\A[0-9a-f]{64}\z/', $sourceHash) !== 1) {
            throw new SourceError($label . ': "source_sha256" expected.');
        }
        $codes = \is_array($details['http_codes'] ?? null) ? $details['http_codes'] : [];
        $apis = [];
        foreach ($list as $item) {
            if (!\is_array($item)) {
                throw new SourceError($label . ': unreadable API.');
            }
            $api = Api::fromArray($item, 'Collected file');
            $code = $codes[$api->url] ?? null;
            $apis[] = $api->withTest($api->test, \is_int($code) ? $code : null);
        }
        $emails = [];
        foreach (\is_array($details['emails'] ?? null) ? $details['emails'] : [] as $address => $fields) {
            $emails[(string) $address] = JsonFile::strings($fields, $label . ', "emails"');
        }

        return [
            'apis' => $apis,
            'sha256' => $hash,
            'size' => \strlen($content),
            'url' => $url,
            'path' => $path,
            'source_sha256' => $sourceHash,
            'source_size' => $counts['source_size'],
            'lines' => $counts['lines_read'],
            'categories' => $counts['categories_read'],
            'tested' => $counts['urls_tested'],
            'requests' => $counts['requests'],
            'duration' => $counts['test_duration_s'],
            'notes' => self::notes($details['set_aside'] ?? null, $label),
            'emails' => $emails,
        ];
    }

    /**
     * Lignes écartées à la lecture, relues du fichier des compléments.
     *
     * @return list<Note>
     *
     * @throws SourceError
     */
    private static function notes(mixed $value, string $label): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new SourceError($label . ': "set_aside" list expected.');
        }
        $notes = [];
        foreach ($value as $item) {
            $kind = \is_array($item) ? ($item['kind'] ?? null) : null;
            $line = \is_array($item) ? ($item['line'] ?? null) : null;
            $reason = \is_array($item) ? ($item['reason'] ?? null) : null;
            $name = \is_array($item) ? ($item['name'] ?? null) : null;
            $url = \is_array($item) ? ($item['url'] ?? null) : null;
            if (!\is_string($kind) || !\is_int($line) || !\is_string($reason) || ($name !== null && !\is_string($name)) || ($url !== null && !\is_string($url))) {
                throw new SourceError($label . ': unreadable "set_aside" item.');
            }
            $notes[] = ['kind' => $kind, 'line' => $line, 'reason' => $reason, 'name' => $name, 'url' => $url];
        }

        return $notes;
    }

    /**
     * @param list<string> $arguments
     *
     * @return Options
     */
    private static function options(array $arguments): array
    {
        $root = \dirname(__DIR__, 2);
        $options = [
            'collect' => null,
            'manifest' => null,
            'data' => $root . '/data',
            'readme' => $root . '/README.md',
            'list' => $root . '/' . ListPage::FILE,
            'summary' => null,
            'forbidden-words' => null,
            'sources' => Sources::FILE,
            'replay' => null,
            'record' => null,
            'help' => false,
        ];
        for ($i = 0, $count = \count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];
            $value = null;
            if (str_contains($argument, '=') && str_starts_with($argument, '--')) {
                [$argument, $value] = explode('=', $argument, 2);
            }
            $argument = self::ALIASES[$argument] ?? $argument;
            if (\in_array($argument, self::WITH_VALUE, true)) {
                $value ??= $arguments[++$i] ?? throw new \InvalidArgumentException('Missing value after ' . $argument . '.');
                if ($value === '') {
                    throw new \InvalidArgumentException('Empty value after ' . $argument . '.');
                }
            }
            switch ($argument) {
                case '--data':
                    $options['data'] = rtrim((string) $value, '/');
                    break;
                case '--collect':
                    $options['collect'] = (string) $value;
                    break;
                case '--manifest':
                    $options['manifest'] = (string) $value;
                    break;
                case '--readme':
                    $options['readme'] = (string) $value;
                    break;
                case '--list':
                    $options['list'] = (string) $value;
                    break;
                case '--summary':
                    $options['summary'] = (string) $value;
                    break;
                case '--forbidden-words':
                    $options['forbidden-words'] = (string) $value;
                    break;
                case '--sources':
                    $options['sources'] = (string) $value;
                    break;
                case '--replay':
                    $options['replay'] = (string) $value;
                    break;
                case '--record':
                    $options['record'] = (string) $value;
                    break;
                case '--loader':
                    break;
                case '--help':
                    $options['help'] = true;
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown argument: ' . $argument . '.');
            }
        }
        if ($options['collect'] !== null && $options['manifest'] === null) {
            throw new \InvalidArgumentException('--collect also requires --manifest <file.json>.');
        }
        if (!$options['help'] && $options['collect'] === null && !is_dir($options['data'])) {
            throw new \InvalidArgumentException('Data directory not found: ' . $options['data'] . '.');
        }

        return $options;
    }

    /**
     * Écrit chaque fichier dans un fichier temporaire du même dossier, puis le renomme : un fichier n'est jamais
     * à moitié écrit.
     *
     * @param array<string, string> $files chemin => contenu
     *
     * @throws SourceError
     */
    private static function write(array $files): void
    {
        $temporaries = [];
        foreach ($files as $path => $content) {
            $temporary = \dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (file_put_contents($temporary, $content) !== \strlen($content)) {
                foreach ($temporaries as $written) {
                    @unlink($written);
                }
                @unlink($temporary);

                throw new SourceError('File could not be written: ' . $path . '.');
            }
            @chmod($temporary, 0644);
            $temporaries[$path] = $temporary;
        }
        foreach ($temporaries as $path => $temporary) {
            if (!rename($temporary, $path)) {
                throw new SourceError('File could not be replaced: ' . $path . '.');
            }
        }
    }
}
