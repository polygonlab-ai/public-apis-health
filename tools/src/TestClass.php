<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Classes du résultat d'un test d'adresse.
 *
 *   responds       l'adresse a répondu sans erreur (codes 2xx et 3xx)
 *   access_denied  identification demandée ou accès interdit (401, 403)
 *   not_found      adresse inconnue du serveur (404, 410)
 *   client_error   autre refus de la demande (autres codes 4xx)
 *   server_error   erreur du serveur (codes 5xx)
 *   unreachable    aucune réponse : nom introuvable, connexion refusée, certificat refusé, délai dépassé
 *   not_tested     adresse invalide, adresse IP, hôte local ou privé : aucun appel n'est fait
 */
final class TestClass
{
    public const RESPONDS = 'responds';
    public const DENIED = 'access_denied';
    public const NOT_FOUND = 'not_found';
    public const CLIENT_ERROR = 'client_error';
    public const SERVER_ERROR = 'server_error';
    public const UNREACHABLE = 'unreachable';
    public const UNTESTED = 'not_tested';

    /** Toutes les classes, dans l'ordre des tableaux publiés. */
    public const ALL = [
        self::RESPONDS,
        self::DENIED,
        self::NOT_FOUND,
        self::CLIENT_ERROR,
        self::SERVER_ERROR,
        self::UNREACHABLE,
        self::UNTESTED,
    ];

    /** Libellé de chaque classe dans les pages générées. */
    public const LABELS = [
        self::RESPONDS => 'Responds',
        self::DENIED => 'Access denied',
        self::NOT_FOUND => 'Not found',
        self::CLIENT_ERROR => 'Client error',
        self::SERVER_ERROR => 'Server error',
        self::UNREACHABLE => 'Unreachable',
        self::UNTESTED => 'Not tested',
    ];

    /** Classes qui comptent comme « l'adresse a répondu » dans la disponibilité observée. */
    public const ANSWERED = [self::RESPONDS, self::DENIED];

    /** Classes de la plus favorable à la moins favorable : le résultat d'un hôte est la meilleure de ses API. */
    private const RANK = [
        self::RESPONDS,
        self::DENIED,
        self::CLIENT_ERROR,
        self::NOT_FOUND,
        self::SERVER_ERROR,
        self::UNREACHABLE,
        self::UNTESTED,
    ];

    /**
     * Classe d'un code de réponse HTTP.
     */
    public static function fromStatus(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 400 => self::RESPONDS,
            $status === 401, $status === 403 => self::DENIED,
            $status === 404, $status === 410 => self::NOT_FOUND,
            $status >= 400 && $status < 500 => self::CLIENT_ERROR,
            default => self::SERVER_ERROR,
        };
    }

    /**
     * La plus favorable des classes données (résultat d'un hôte) ; « not_tested » pour une liste vide.
     *
     * @param list<string> $classes
     */
    public static function best(array $classes): string
    {
        foreach (self::RANK as $class) {
            if (\in_array($class, $classes, true)) {
                return $class;
            }
        }

        return self::UNTESTED;
    }
}
