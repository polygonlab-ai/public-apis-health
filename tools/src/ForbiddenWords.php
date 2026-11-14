<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Mots interdits facultatifs (option --forbidden-words) : une entrée dont une valeur publiée contient l'un d'eux est
 * écartée.
 *
 * Fichier JSON : {"mots": [{"mot": "exemple", "tolerees": ["exemple build"]}]}. Un mot est cherché sans tenir
 * compte des majuscules, comme une partie de texte. Les écritures « tolerees » (facultatives) sont des écritures
 * exactes, sensibles aux majuscules, retirées du texte avant de chercher ce mot, et pour ce mot seulement, à
 * condition d'être délimitées : ni lettre, ni chiffre, ni « _ », « - » ou « . » juste avant ; ni lettre, ni chiffre,
 * ni « _ » ou « - » juste après.
 */
final class ForbiddenWords
{
    /**
     * @param list<array{mot: string, motifs: list<string>}> $words
     */
    private function __construct(private readonly array $words) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @throws SourceError
     */
    public static function load(string $path): self
    {
        $label = 'Forbidden words file';
        $data = JsonFile::read($path, $label);
        $list = $data['mots'] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new SourceError($label . ': "mots" list expected.');
        }
        $words = [];
        foreach ($list as $index => $entry) {
            $word = \is_array($entry) ? ($entry['mot'] ?? null) : null;
            if (!\is_string($word) || trim($word) === '') {
                throw new SourceError(sprintf('%s: word no. %d empty or missing.', $label, $index + 1));
            }
            $tolerated = JsonFile::strings($entry['tolerees'] ?? [], $label . ', tolerated writings of word no. ' . ($index + 1));
            $patterns = [];
            foreach ($tolerated as $writing) {
                if (mb_stripos($writing, $word) === false) {
                    throw new SourceError(sprintf('%s: tolerated writing no. %d of word no. %d does not contain that word.', $label, \count($patterns) + 1, $index + 1));
                }
                $patterns[] = self::pattern($writing);
            }
            $words[] = ['mot' => trim($word), 'motifs' => $patterns];
        }

        return new self($words);
    }

    /**
     * Le texte contient-il un mot interdit (une fois retirées les écritures tolérées de ce mot) ?
     */
    public function found(string $text): bool
    {
        foreach ($this->words as $word) {
            $rest = $text;
            foreach ($word['motifs'] as $pattern) {
                $rest = (string) preg_replace($pattern, ' ', $rest);
            }
            if (mb_stripos($rest, $word['mot']) !== false) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return \count($this->words);
    }

    /**
     * Expression de l'écriture exacte, délimitée à ses extrémités alphanumériques.
     */
    private static function pattern(string $writing): string
    {
        $pattern = preg_quote($writing, '~');
        if (preg_match('/\A[\p{L}\p{N}]/u', $writing) === 1) {
            $pattern = '(?<![\p{L}\p{N}_.\-])' . $pattern;
        }
        if (preg_match('/[\p{L}\p{N}]\z/u', $writing) === 1) {
            $pattern .= '(?![\p{L}\p{N}_\-])';
        }

        return '~' . $pattern . '~u';
    }
}
