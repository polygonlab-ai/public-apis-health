<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Adresses IP : une adresse est-elle publique ? Sont refusées les adresses locales (boucle locale, adresse nulle),
 * privées (réseaux internes IPv4 et IPv6, plage partagée des opérateurs), de lien local, de diffusion groupée et
 * réservées. Les adresses IPv6 qui enveloppent une adresse IPv4 sont jugées sur cette adresse IPv4.
 */
final class IpAddress
{
    /**
     * Vrai si le texte est une adresse IPv4 ou IPv6.
     */
    public static function isLiteral(string $text): bool
    {
        return filter_var(trim($text, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    public static function isV6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Vrai si l'adresse est publique. Une adresse illisible n'est jamais publique.
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        $bytes = array_map(ord(...), str_split($packed));
        if (\count($bytes) === 4) {
            return self::isPublicV4($bytes[0], $bytes[1], $bytes[2]);
        }
        if (\count($bytes) !== 16) {
            return false;
        }
        $head = \array_slice($bytes, 0, 12);
        // Adresse IPv4 enveloppée : « ::ffff:a.b.c.d », ou traduite « 64:ff9b::a.b.c.d ».
        if ($head === [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 255, 255] || $head === [0, 100, 255, 155, 0, 0, 0, 0, 0, 0, 0, 0]) {
            return self::isPublicV4($bytes[12], $bytes[13], $bytes[14]);
        }
        // Adresse nulle, boucle locale et plage réservée « ::/8 » ; réseaux privés « fc00::/7 » ;
        // lien local « fe80::/10 » et ancien site local « fec0::/10 » ; diffusion groupée « ff00::/8 ».
        if ($bytes[0] === 0 || ($bytes[0] & 0xFE) === 0xFC || ($bytes[0] === 0xFE && ($bytes[1] & 0x80) === 0x80) || $bytes[0] === 0xFF) {
            return false;
        }

        return true;
    }

    /**
     * Adresse IPv4 publique ? Refusées, dans l'ordre : réseau « ce réseau » (0), réseaux privés (10, 172.16 à 172.31,
     * 192.168), boucle locale (127), plage partagée des opérateurs (100.64 à 100.127), lien local (169.254),
     * attributions de protocole (192.0.0), bancs d'essai (198.18 et 198.19), diffusion groupée et plage réservée
     * (224 et au-delà).
     */
    private static function isPublicV4(int $a, int $b, int $c): bool
    {
        if ($a === 0 || $a === 10 || $a === 127 || $a >= 224) {
            return false;
        }
        if (($a === 172 && ($b & 0xF0) === 16) || ($a === 192 && $b === 168)) {
            return false;
        }
        if (($a === 100 && ($b & 0xC0) === 64) || ($a === 169 && $b === 254)) {
            return false;
        }

        return !(($a === 192 && $b === 0 && $c === 0) || ($a === 198 && ($b & 0xFE) === 18));
    }
}
