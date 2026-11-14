<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Une requête de test : l'adresse à appeler et l'adresse IP, déjà vérifiée publique, à laquelle la connexion doit
 * se faire (la résolution du nom n'est pas refaite au moment de l'appel).
 */
final class ProbeRequest
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {}
}
