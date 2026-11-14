<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Chiffres de la liste, générés dans le README entre deux balises de commentaire : date, nombre d'entrées et
 * d'hôtes, répartition par résultat de test, puis par catégorie (entrées, entrées qui répondent). Le reste du README
 * n'est jamais réécrit. Toute valeur venue de la liste est échappée : aucun lien, aucune image ni aucun code HTML ne
 * peut être fabriqué par un nom de catégorie.
 */
final class Readme
{
    public const START = '<!-- list:start -->';
    public const END = '<!-- list:end -->';

    /**
     * Contenu placé entre les balises.
     *
     * @param list<Api> $apis
     */
    public static function block(?string $sourceDate, array $apis): string
    {
        if ($apis === [] || $sourceDate === null) {
            return "\n_The figures will be published here after the first reading of the list._\n\n";
        }
        /** @var array<string, int> $tests */
        $tests = array_fill_keys(TestClass::ALL, 0);
        foreach ($apis as $api) {
            $tests[$api->test] = ($tests[$api->test] ?? 0) + 1;
        }
        $categories = DataFiles::categories($apis);
        $lines = [
            '',
            sprintf(
                'List read and tested on %s: %s on %s, in %s. Full files: [`data/apis.json`](data/apis.json) and [`data/apis.csv`](data/apis.csv). List by category: [`%s`](%s).',
                $sourceDate,
                Updater::plural(\count($apis), 'API', 'APIs'),
                Updater::plural(\count(DataFiles::hosts($apis)), 'host', 'hosts'),
                Updater::plural(\count($categories), 'category', 'categories'),
                ListPage::FILE,
                ListPage::FILE,
            ),
            '',
            '| Test result | Value | APIs |',
            '|---|---|---:|',
        ];
        foreach (TestClass::LABELS as $class => $label) {
            $lines[] = '| ' . $label . ' | `' . $class . '` | ' . ($tests[$class] ?? 0) . ' |';
        }
        $lines[] = '';
        $lines[] = '| Category | APIs | Responding |';
        $lines[] = '|---|---:|---:|';
        foreach ($categories as $name => $counts) {
            $lines[] = '| ' . Text::markdown((string) $name) . ' | ' . $counts['count'] . ' | ' . $counts['responding'] . ' |';
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * README dont la partie entre les balises est remplacée.
     *
     * @throws SourceError
     */
    public static function replace(string $readme, string $block): string
    {
        $start = strpos($readme, self::START);
        $end = strpos($readme, self::END);
        if ($start === false || $end === false || $end < $start
            || substr_count($readme, self::START) !== 1 || substr_count($readme, self::END) !== 1) {
            throw new SourceError('README.md: markers "' . self::START . '" and "' . self::END . '" expected, once each and in that order.');
        }

        return substr($readme, 0, $start + \strlen(self::START)) . $block . substr($readme, $end);
    }
}
