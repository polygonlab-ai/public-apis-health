<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests\Fixtures;

use PolygonLab\PublicApisHealth\Tools\Api;
use PolygonLab\PublicApisHealth\Tools\MarkdownList;
use PolygonLab\PublicApisHealth\Tools\Sources;
use PolygonLab\PublicApisHealth\Tools\TestClass;
use PolygonLab\PublicApisHealth\Tools\Updater;

/**
 * Entrées d'essai et collectes d'essai, pour les tests qui n'ont pas besoin de lire un fichier Markdown.
 *
 * @phpstan-import-type Collected from Updater
 * @phpstan-import-type Note from MarkdownList
 */
final class Apis
{
    /**
     * Entrée d'essai : nom « Sample API <numéro> », lien sur l'hôte donné, catégorie et résultat au choix.
     */
    public static function make(
        int $number,
        string $test = TestClass::RESPONDS,
        string $category = 'Sample category',
        string $host = 'docs.example.com',
        ?string $name = null,
        ?string $description = 'Sample description',
        ?string $auth = 'No',
        ?int $httpCode = 200,
    ): Api {
        return new Api(
            $name ?? sprintf('Sample API %d', $number),
            sprintf('https://%s/api-%d/', $host, $number),
            $description,
            $category,
            $auth,
            'Yes',
            'Unknown',
            $test,
            $test === TestClass::UNTESTED || $test === TestClass::UNREACHABLE ? null : $httpCode,
        );
    }

    /**
     * Plusieurs entrées d'essai, numérotées de $from à $to, toutes de la même classe de test.
     *
     * @return list<Api>
     */
    public static function range(int $from, int $to, string $test = TestClass::RESPONDS, string $host = 'docs.example.com'): array
    {
        $apis = [];
        for ($number = $from; $number <= $to; $number++) {
            $apis[] = self::make($number, $test, host: $host);
        }

        return $apis;
    }

    /**
     * Collecte d'essai portant ces entrées.
     *
     * @param list<Api> $apis
     * @param array<string, list<string>> $emails
     * @param list<Note> $notes
     *
     * @return Collected
     */
    public static function collected(array $apis, array $emails = [], array $notes = []): array
    {
        return [
            'apis' => $apis,
            'sha256' => hash('sha256', serialize($apis)),
            'size' => 1000,
            'url' => Sources::load()->listUrl,
            'path' => '/collect/list.json',
            'source_sha256' => hash('sha256', 'source'),
            'source_size' => 5000,
            'lines' => \count($apis) + \count($notes),
            'categories' => 1,
            'tested' => \count($apis),
            'requests' => \count($apis),
            'duration' => 12,
            'notes' => $notes,
            'emails' => $emails,
        ];
    }
}
