<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Résolution d'un nom d'hôte en adresses IP. Production : DnsResolver. Tests et rejeu : résolutions fournies.
 */
interface Resolver
{
    /**
     * Adresses IP (IPv4 ou IPv6) du nom d'hôte ; liste vide si le nom est introuvable.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
