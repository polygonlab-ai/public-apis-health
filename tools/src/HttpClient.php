<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Lecture du fichier de la liste. Production : CurlClient. Rejeu d'une collecte enregistrée : ReplayClient.
 */
interface HttpClient
{
    /**
     * Corps de la réponse (réponse 200 exigée).
     *
     * @throws SourceError fichier impossible à lire
     */
    public function get(string $url): string;
}
