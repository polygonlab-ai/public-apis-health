<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Textes : nettoyage des valeurs lues dans la liste, coupe propre à une longueur donnée, retrait des adresses
 * e-mail, clé de tri, échappement Markdown des pages générées.
 */
final class Text
{
    public const ELLIPSIS = '…';

    public const EMAIL_REPLACEMENT = '[address removed]';

    public const EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/';

    /**
     * Texte nettoyé (caractères de contrôle, espaces insécables et retours à la ligne remplacés par une espace,
     * espaces réduits), ou null s'il est vide.
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('//u', $value) !== 1) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F\x{00A0}\x{2007}\x{202F}\x{200B}\x{2028}\x{2029}\x{FEFF}\s]+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * Texte coupé à $max caractères au plus, points de suspension compris : la coupe se fait à la fin d'un mot quand
     * c'est possible, jamais au milieu d'un caractère.
     */
    public static function truncate(?string $value, int $max): ?string
    {
        if ($value === null || mb_strlen($value) <= $max) {
            return $value;
        }
        $cut = mb_substr($value, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= intdiv($max, 2)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return (string) preg_replace('/[\s,;:.\-–—(«"\']+\z/u', '', $cut) . self::ELLIPSIS;
    }

    /**
     * Texte dont les adresses e-mail sont remplacées par « [address removed] ».
     */
    public static function withoutEmails(string $value): string
    {
        return (string) preg_replace(self::EMAIL, self::EMAIL_REPLACEMENT, $value);
    }

    public static function hasEmail(string $value): bool
    {
        return preg_match(self::EMAIL, $value) === 1;
    }

    /**
     * Clé de tri : minuscules sans accent, pour un ordre alphabétique lisible et stable.
     */
    public static function sortKey(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $plain = strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $plain));
    }

    /**
     * Valeur affichée telle quelle dans une page Markdown : HTML neutralisé, caractères de mise en forme et de lien
     * échappés (aucun lien ni aucune image ne peut être fabriqué par un nom, une description ou une catégorie).
     */
    public static function markdown(string $value): string
    {
        $value = htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/([\\\\`*_\[\]|~#])/', '\\\\$1', $value);
    }
}
