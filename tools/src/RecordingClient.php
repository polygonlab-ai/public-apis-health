<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Enregistrement d'une collecte (option --record) : le fichier lu est copié tel quel dans le dossier (« list.md »),
 * pour être rejoué plus tard avec --replay.
 */
final class RecordingClient implements HttpClient
{
    public function __construct(
        private readonly HttpClient $client,
        private readonly string $directory,
    ) {}

    public function get(string $url): string
    {
        $body = $this->client->get($url);
        $file = ReplayClient::file($this->directory);
        if (file_put_contents($file, $body) !== \strlen($body)) {
            throw new SourceError('Recording failed: ' . $file . '.');
        }

        return $body;
    }
}
