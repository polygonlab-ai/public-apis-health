<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Réponse à une requête de test, redirection non suivie.
 *
 *   status    code HTTP reçu, ou null si aucune réponse n'est arrivée
 *   location  adresse absolue de la redirection demandée par la réponse, ou null
 *   error     cause de l'absence de réponse (nom introuvable, connexion refusée, certificat, délai), ou null
 *   malformed vrai si l'adresse a été refusée avant tout appel (adresse mal formée)
 *
 * Le corps de la réponse n'est jamais gardé : seul le code compte.
 */
final class ProbeResponse
{
    public function __construct(
        public readonly ?int $status,
        public readonly ?string $location = null,
        public readonly ?string $error = null,
        public readonly bool $malformed = false,
    ) {}

    public static function failure(string $error): self
    {
        return new self(null, null, $error);
    }
}
