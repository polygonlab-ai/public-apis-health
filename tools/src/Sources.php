<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Source des données et réglages, déclarés dans tools/sources.json : adresse du fichier Markdown de la liste
 * public-apis, limites de sa lecture (essais, délai, taille, nombre minimal de catégories) et réglages du test des
 * adresses (délai, redirections, taille lue, politesse par hôte, durée maximale, seuils des garde-fous).
 *
 * Les clés « mode », « mots_interdits » et « fichiers_generes » du même fichier sont lues par le script de
 * publication, pas par cet outil.
 */
final class Sources
{
    public const FILE = __DIR__ . '/../sources.json';

    /** Clé de la source dans data/source.json et dans le manifeste de collecte. */
    public const KEY = 'list';

    private function __construct(
        public readonly string $source,
        public readonly string $page,
        public readonly string $listUrl,
        public readonly int $attempts,
        public readonly int $timeoutSeconds,
        public readonly int $maxSize,
        public readonly int $minCategories,
        public readonly int $maxDropPercent,
        public readonly int $probeTimeoutSeconds,
        public readonly int $maxRedirects,
        public readonly int $maxBodySize,
        public readonly int $hostIntervalSeconds,
        public readonly int $retryPauseSeconds,
        public readonly int $concurrency,
        public readonly int $maxConsecutiveFailures,
        public readonly int $maxProbeDurationSeconds,
        public readonly int $maxUnreachablePercent,
        public readonly int $maxChangedPercent,
        public readonly int $runs,
        public readonly int $minRuns,
    ) {}

    /**
     * @throws SourceError
     */
    public static function load(string $path = self::FILE): self
    {
        $data = JsonFile::read($path, 'tools/sources.json');
        $texts = [];
        foreach (['source', 'source_page', 'list_url'] as $field) {
            $value = $data[$field] ?? null;
            if (!\is_string($value) || trim($value) === '') {
                throw new SourceError('tools/sources.json: "' . $field . '" (text) expected.');
            }
            $texts[$field] = $value;
        }
        foreach (['source_page', 'list_url'] as $field) {
            if (preg_match('~\Ahttps://[^\s?#]+\z~', $texts[$field]) !== 1) {
                throw new SourceError('tools/sources.json: "' . $field . '" must be an https address, without parameters.');
            }
        }
        $test = $data['test'] ?? null;
        if (!\is_array($test)) {
            throw new SourceError('tools/sources.json: "test" object expected.');
        }
        $minRuns = self::integer($test, 'test.min_runs', 'min_runs', 1, 52);
        $runs = self::integer($test, 'test.runs', 'runs', $minRuns, 52);

        return new self(
            $texts['source'],
            $texts['source_page'],
            $texts['list_url'],
            self::integer($data, 'attempts', 'attempts', 1, 10),
            self::integer($data, 'timeout_s', 'timeout_s', 1, 600),
            self::integer($data, 'max_size', 'max_size', 1000, 100_000_000),
            self::integer($data, 'min_categories', 'min_categories', 1, 1000),
            self::integer($data, 'max_drop_pct', 'max_drop_pct', 1, 100),
            self::integer($test, 'test.timeout_s', 'timeout_s', 1, 120),
            self::integer($test, 'test.max_redirects', 'max_redirects', 0, 10),
            self::integer($test, 'test.max_body_size', 'max_body_size', 1024, 10_000_000),
            self::integer($test, 'test.host_interval_s', 'host_interval_s', 1, 60),
            self::integer($test, 'test.retry_pause_s', 'retry_pause_s', 1, 120),
            self::integer($test, 'test.concurrency', 'concurrency', 1, 50),
            self::integer($test, 'test.max_consecutive_failures', 'max_consecutive_failures', 1, 100),
            self::integer($test, 'test.max_duration_s', 'max_duration_s', 10, 86400),
            self::integer($test, 'test.max_unreachable_pct', 'max_unreachable_pct', 1, 100),
            self::integer($test, 'test.max_changed_pct', 'max_changed_pct', 1, 100),
            $runs,
            $minRuns,
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @throws SourceError
     */
    private static function integer(array $data, string $label, string $field, int $min, int $max): int
    {
        $value = $data[$field] ?? null;
        if (!\is_int($value) || $value < $min || $value > $max) {
            throw new SourceError(sprintf('tools/sources.json: "%s" must be an integer from %d to %d.', $label, $min, $max));
        }

        return $value;
    }
}
