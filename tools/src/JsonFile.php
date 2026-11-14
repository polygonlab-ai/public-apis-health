<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Lecture et écriture JSON : objets lus avec un message clair en cas d'erreur, rendu déterministe (mêmes données,
 * mêmes octets).
 */
final class JsonFile
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Objet JSON lu dans un fichier.
     *
     * @return array<string, mixed>
     *
     * @throws SourceError
     */
    public static function read(string $path, string $label): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new SourceError($label . ': unreadable file.');
        }

        return self::decode($content, $label);
    }

    /**
     * Objet JSON décodé.
     *
     * @return array<string, mixed>
     *
     * @throws SourceError
     */
    public static function decode(string $content, string $label): array
    {
        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new SourceError($label . ': invalid JSON (' . $error->getMessage() . ').');
        }
        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new SourceError($label . ': JSON object expected.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        // Écriture la plus courte des nombres à virgule (99.9 et non 99.900000000000006), quel que soit le réglage ;
        // un nombre à virgule entier garde sa décimale (100.0), pour rester un nombre à virgule à la relecture.
        ini_set('serialize_precision', '-1');

        return json_encode($data, self::FLAGS) . "\n";
    }

    /**
     * Liste de textes non vides, sans doublon.
     *
     * @return list<string>
     *
     * @throws SourceError
     */
    public static function strings(mixed $value, string $label): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new SourceError($label . ': list of texts expected.');
        }
        $strings = [];
        foreach ($value as $item) {
            if (!\is_string($item) || trim($item) === '') {
                throw new SourceError($label . ': each item must be a non-empty text.');
            }
            $strings[] = $item;
        }
        if (\count(array_unique($strings)) !== \count($strings)) {
            throw new SourceError($label . ': duplicate item.');
        }

        return $strings;
    }
}
