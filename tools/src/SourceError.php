<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Collecte ou actualisation refusée : liste injoignable, fichier trop gros, titre « ## Index » absent, en-tête de
 * tableau inattendu, catégorie sans ligne lisible, trop peu de catégories, liste vide, trop d'adresses injoignables,
 * trop de changements d'un coup, configuration invalide.
 *
 * Le message dit ce qui ne va pas et où (ligne, catégorie, fichier), pour qu'on sache quoi vérifier sans lire le
 * code. Quand cette exception est levée, rien n'est écrit dans data/, ni dans LIST.md, ni dans le README.
 */
final class SourceError extends \RuntimeException {}
