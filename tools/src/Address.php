<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Adresse d'un lien de la liste.
 *
 * Address::normalize() donne l'adresse normalisée d'un lien, qui est la clé de son entrée : protocole et hôte en
 * minuscules, port par défaut retiré, chemin vide écrit « / ». Rien d'autre n'est touché : chemin, paramètres et
 * ancre restent ceux de la liste.
 *
 * Address::parse() donne l'adresse telle qu'elle peut être testée : protocole http ou https, nom d'hôte public (ni
 * adresse IP, ni nom local), sans identifiant dans l'adresse ; tout port est admis. Elle rend l'adresse, ou la
 * raison (texte) pour laquelle elle n'est pas testée.
 */
final class Address
{
    private const MAX_LENGTH = 2000;

    /** Suffixes de noms réservés à un usage local. */
    private const LOCAL_SUFFIXES = ['.localhost', '.local', '.localdomain', '.internal', '.intranet', '.lan', '.home', '.corp', '.home.arpa'];

    /** Écritures numériques d'une adresse IPv4 (« 2130706433 », « 0x7f.1 »). */
    private const NUMERIC_HOST = '/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/';

    private const HOSTNAME = '/\A(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/';

    /** Forme d'une adresse http ou https : protocole, autorité, puis le reste (chemin, paramètres, ancre). */
    private const HTTP_URL = '~\A(https?)://([^/?#\s]+)([^\s]*)\z~i';

    private function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
    ) {}

    /**
     * Adresse normalisée d'un lien http ou https, ou null si le lien n'en est pas un (autre protocole, hôte absent
     * ou illisible, espace ou caractère de contrôle, longueur excessive).
     *
     * @return non-empty-string|null
     */
    public static function normalize(string $url): ?string
    {
        if (\strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $url) === 1 || preg_match(self::HTTP_URL, $url, $parts) !== 1) {
            return null;
        }
        $scheme = strtolower($parts[1]);
        $authority = strtolower($parts[2]);
        $rest = $parts[3];
        if (preg_match('/\A(?:[^@]*@)?(\[[0-9a-f:.]+\]|[a-z0-9._-]+)(?::([0-9]{1,5}))?\z/', $authority, $match) !== 1) {
            return null;
        }
        $host = rtrim($match[1], '.');
        if ($host === '') {
            return null;
        }
        $port = $match[2] ?? '';
        if ($port === ($scheme === 'https' ? '443' : '80')) {
            $port = '';
        }
        // Les identifiants éventuels de l'adresse sont gardés tels quels : l'entrée est alors écartée par Screening.
        $user = strstr($parts[2], '@', true);
        if ($rest === '' || $rest[0] !== '/') {
            $rest = '/' . $rest;
        }

        return $scheme . '://' . ($user === false ? '' : $user . '@') . $host . ($port === '' ? '' : ':' . $port) . $rest;
    }

    /**
     * Adresse sans son ancre : c'est elle qui est appelée par le test (l'ancre n'est jamais envoyée au serveur).
     */
    public static function withoutFragment(string $url): string
    {
        $position = strpos($url, '#');

        return $position === false ? $url : substr($url, 0, $position);
    }

    /**
     * @return self|string l'adresse à tester, ou la raison pour laquelle elle ne l'est pas
     */
    public static function parse(string $url): self|string
    {
        if (\strlen($url) > self::MAX_LENGTH) {
            return 'address too long';
        }
        if ($url === '' || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return 'invalid address';
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'invalid address';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'protocol other than http or https';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'address with credentials';
        }
        $host = rtrim(strtolower($parts['host']), '.');
        if ($host === '') {
            return 'invalid address';
        }
        if (IpAddress::isLiteral($host) || preg_match(self::NUMERIC_HOST, $host) === 1) {
            return 'IP address';
        }
        if ($host === 'localhost' || !str_contains($host, '.')) {
            return 'local host';
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return 'local host';
            }
        }
        if (\strlen($host) > 253 || preg_match(self::HOSTNAME, $host) !== 1) {
            return 'invalid host name';
        }

        return new self($url, $scheme, $host, $parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * Hôte (en minuscules) d'une adresse http ou https, ou null si elle n'en a pas.
     */
    public static function hostOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $host = rtrim(strtolower($parts['host']), '.');

        return preg_match('/\A[a-z0-9._\[\]:-]+\z/', $host) === 1 ? $host : null;
    }
}
