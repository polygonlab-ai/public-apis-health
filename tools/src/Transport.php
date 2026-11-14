<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Envoi des requêtes de test, plusieurs à la fois. Production : CurlTransport. Tests et rejeu : réponses fournies.
 *
 * Le transport ne suit aucune redirection, n'envoie aucun identifiant et ne décide de rien : l'ordre des requêtes,
 * la politesse par hôte et la vérification des adresses sont faits par Prober.
 */
interface Transport
{
    /**
     * Lance une requête GET, sans attendre sa réponse.
     *
     * @param int $id numéro choisi par l'appelant, rendu avec la réponse
     */
    public function start(int $id, ProbeRequest $request): void;

    /**
     * Attend au plus $seconds secondes et rend les réponses arrivées, par numéro de requête.
     *
     * @return array<int, ProbeResponse>
     */
    public function wait(float $seconds): array;
}
