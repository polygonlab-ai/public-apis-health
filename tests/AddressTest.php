<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tools\Address;
use PolygonLab\PublicApisHealth\Tools\IpAddress;

/**
 * Adresses normalisées (clé d'une entrée), adresses testables, adresses IP publiques ou non.
 */
final class AddressTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function links(): array
    {
        return [
            'déjà normalisée' => ['https://docs.example.com/api/', 'https://docs.example.com/api/'],
            'chemin vide' => ['https://docs.example.com', 'https://docs.example.com/'],
            'hôte et protocole en majuscules' => ['HTTPS://Docs.Example.COM/Api/V1', 'https://docs.example.com/Api/V1'],
            'port par défaut https' => ['https://docs.example.com:443/api', 'https://docs.example.com/api'],
            'port par défaut http' => ['http://docs.example.com:80/api', 'http://docs.example.com/api'],
            'autre port gardé' => ['https://docs.example.com:8443/api', 'https://docs.example.com:8443/api'],
            'paramètres sans chemin' => ['https://docs.example.com?utm_source=list', 'https://docs.example.com/?utm_source=list'],
            'ancre sans chemin' => ['https://docs.example.com#api', 'https://docs.example.com/#api'],
            'paramètres et ancre gardés' => ['https://docs.example.com/a?b=C#D', 'https://docs.example.com/a?b=C#D'],
            'barre finale gardée' => ['https://docs.example.com/api/', 'https://docs.example.com/api/'],
            'sans barre finale gardée' => ['https://docs.example.com/api', 'https://docs.example.com/api'],
            'point final de l\'hôte retiré' => ['https://docs.example.com./api', 'https://docs.example.com/api'],
            'autre protocole' => ['ftp://docs.example.com/api', null],
            'courriel' => ['mailto:contact@example.com', null],
            'sans protocole' => ['docs.example.com/api', null],
            'lien relatif' => ['/docs/api', null],
            'ancre seule' => ['#index', null],
            'espace' => ['https://docs.example.com/a b', null],
            'hôte absent' => ['https:///api', null],
            'hôte illisible' => ['https://docs.exa$mple.com/api', null],
            'vide' => ['', null],
        ];
    }

    #[DataProvider('links')]
    public function testALinkIsNormalised(string $link, ?string $expected): void
    {
        self::assertSame($expected, Address::normalize($link));
    }

    public function testNormalisingTwiceChangesNothing(): void
    {
        foreach (self::links() as [$link, $expected]) {
            if ($expected !== null) {
                self::assertSame($expected, Address::normalize($expected), $link);
            }
        }
    }

    public function testTheAnchorIsNotPartOfTheAddressCalled(): void
    {
        self::assertSame('https://docs.example.com/api', Address::withoutFragment('https://docs.example.com/api#users'));
        self::assertSame('https://docs.example.com/api?a=1', Address::withoutFragment('https://docs.example.com/api?a=1#'));
        self::assertSame('https://docs.example.com/api', Address::withoutFragment('https://docs.example.com/api'));
    }

    public function testATestableAddressGivesItsHostAndPort(): void
    {
        $address = Address::parse('https://API.Example.com/v1?page=2');
        self::assertInstanceOf(Address::class, $address);
        self::assertSame('api.example.com', $address->host);
        self::assertSame(443, $address->port);
        self::assertSame('https', $address->scheme);

        $address = Address::parse('http://api.example.com:8080/v1');
        self::assertInstanceOf(Address::class, $address);
        self::assertSame(8080, $address->port, 'tout port est admis');

        $address = Address::parse('http://api.example.com/v1');
        self::assertInstanceOf(Address::class, $address);
        self::assertSame(80, $address->port);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refused(): array
    {
        return [
            'protocole' => ['ftp://api.example.com/', 'protocol other than http or https'],
            'adresse IPv4' => ['https://' . implode('.', [10, 0, 0, 1]) . '/', 'IP address'],
            'adresse IPv6' => ['https://[::1]/', 'IP address'],
            'adresse IPv4 hexadécimale' => ['http://0x7f.1/', 'IP address'],
            'boucle locale' => ['http://localhost/', 'local host'],
            'sous-domaine de localhost' => ['http://api.localhost/', 'local host'],
            'nom local' => ['https://nas.local/', 'local host'],
            'sans protocole' => ['api.example.com', 'invalid address'],
            'nom invalide' => ['https://api_.exa$mple.com/', 'invalid host name'],
            'identifiant' => ['https://' . implode(':', ['user', 'code']) . '@api.example.com/', 'address with credentials'],
        ];
    }

    #[DataProvider('refused')]
    public function testARefusedAddressGivesItsReason(string $url, string $reason): void
    {
        self::assertSame($reason, Address::parse($url));
    }

    public function testHostOfAnAddress(): void
    {
        self::assertSame('api.example.com', Address::hostOf('https://API.example.com:8443/v1'));
        self::assertNull(Address::hostOf('mailto:contact@example.com'));
        self::assertNull(Address::hostOf('not an address'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function ipAddresses(): array
    {
        return [
            'publique' => ['198.51.100.20', true],
            'documentation' => ['192.0.2.1', true],
            'privée 10' => [implode('.', [10, 255, 0, 1]), false],
            'privée 172.16' => [implode('.', [172, 16, 0, 1]), false],
            'privée 172.31' => [implode('.', [172, 31, 255, 254]), false],
            'publique 172.32' => [implode('.', [172, 32, 0, 1]), true],
            'privée 192.168' => [implode('.', [192, 168, 0, 1]), false],
            'lien local' => [implode('.', [169, 254, 0, 1]), false],
            'boucle locale' => ['127.0.0.1', false],
            'boucle locale 127.x' => [implode('.', [127, 8, 9, 10]), false],
            'adresse nulle' => ['0.0.0.0', false],
            'opérateurs' => [implode('.', [100, 127, 255, 254]), false],
            'diffusion groupée' => [implode('.', [224, 0, 0, 251]), false],
            'diffusion générale' => [implode('.', [255, 255, 255, 255]), false],
            'IPv6 publique' => ['2001:db8::1', true],
            'IPv6 boucle locale' => ['::1', false],
            'IPv6 nulle' => ['::', false],
            'IPv6 privée fc' => ['fc00::1', false],
            'IPv6 privée fd' => ['fd12:3456::1', false],
            'IPv6 lien local' => ['fe80::abcd', false],
            'IPv6 diffusion groupée' => ['ff02::1', false],
            'IPv4 privée enveloppée' => ['::ffff:' . implode('.', [192, 168, 1, 1]), false],
            'IPv4 publique enveloppée' => ['::ffff:198.51.100.20', true],
            'illisible' => ['not-an-address', false],
        ];
    }

    #[DataProvider('ipAddresses')]
    public function testOnlyPublicIpAddressesAreAccepted(string $ip, bool $public): void
    {
        self::assertSame($public, IpAddress::isPublic($ip));
    }
}
