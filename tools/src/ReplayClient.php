<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Rejeu d'une collecte enregistrée (option --replay) : le fichier « list.md » du dossier est rendu à la place du
 * téléchargement, sans aucun appel réseau. Une collecte s'enregistre avec --record.
 */
final class ReplayClient implements HttpClient
{
    public const FILE = 'list.md';

    /** @var list<string> adresses demandées, dans l'ordre */
    public array $requests = [];

    public function __construct(private readonly string $directory) {}

    public static function file(string $directory): string
    {
        return rtrim($directory, '/') . '/' . self::FILE;
    }

    public function get(string $url): string
    {
        $this->requests[] = $url;
        $file = self::file($this->directory);
        $body = is_file($file) ? @file_get_contents($file) : false;
        if ($body === false) {
            throw new SourceError('Recorded list missing: ' . self::FILE . ' (requested for ' . $url . ').');
        }

        return $body;
    }
}
