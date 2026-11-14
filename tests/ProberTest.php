<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tests\Fixtures\FakeNetwork;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Workspace;
use PolygonLab\PublicApisHealth\Tools\Outcome;
use PolygonLab\PublicApisHealth\Tools\Prober;
use PolygonLab\PublicApisHealth\Tools\ProbeResponse;
use PolygonLab\PublicApisHealth\Tools\SourceError;
use PolygonLab\PublicApisHealth\Tools\Sources;
use PolygonLab\PublicApisHealth\Tools\TestClass;

/**
 * Le test des adresses, sur un faux réseau (aucun appel réel) : classement des réponses, second essai, redirections,
 * adresses jamais appelées (locales, privées, adresses IP), politesse par hôte, hôte en panne, durée maximale.
 */
final class ProberTest extends TestCase
{
    private FakeNetwork $network;

    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->network = new FakeNetwork();
        $this->workspace = new Workspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    /**
     * @return array<int|string, array{0: int, 1: string}>
     */
    public static function statuses(): array
    {
        return [
            '200' => [200, TestClass::RESPONDS],
            '204' => [204, TestClass::RESPONDS],
            '304' => [304, TestClass::RESPONDS],
            '401' => [401, TestClass::DENIED],
            '403' => [403, TestClass::DENIED],
            '404' => [404, TestClass::NOT_FOUND],
            '410' => [410, TestClass::NOT_FOUND],
            '400' => [400, TestClass::CLIENT_ERROR],
            '429' => [429, TestClass::CLIENT_ERROR],
            '500' => [500, TestClass::SERVER_ERROR],
            '503' => [503, TestClass::SERVER_ERROR],
        ];
    }

    #[DataProvider('statuses')]
    public function testEachStatusGetsItsClass(int $status, string $class): void
    {
        $url = 'https://api.example.com/v1';
        $this->network->respond($url, new ProbeResponse($status));

        $outcome = $this->probe([$url])[$url];

        self::assertSame($class, $outcome->class);
        self::assertSame($status, $outcome->code);
        self::assertSame([$url], $this->network->urls());
    }

    public function testNetworkErrorIsRetriedOnceAfterFiveSeconds(): void
    {
        $url = 'https://api.example.com/v1';
        $this->network->respond($url, ProbeResponse::failure('timeout'), new ProbeResponse(200));

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::RESPONDS, $outcome->class);
        self::assertCount(2, $this->network->calls);
        self::assertGreaterThanOrEqual(5.0, $this->network->calls[1]['time'] - $this->network->calls[0]['time']);
    }

    public function testTwoNetworkErrorsMakeTheAddressUnreachable(): void
    {
        $url = 'https://api.example.com/v1';
        $this->network->respond($url, ProbeResponse::failure('connection refused'));

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::UNREACHABLE, $outcome->class);
        self::assertNull($outcome->code);
        self::assertSame('connection refused', $outcome->detail);
        self::assertCount(2, $this->network->calls, 'un essai, puis un second, jamais plus');
    }

    public function testAStatusOutsideTheHttpRangeIsNotAnAnswer(): void
    {
        $url = 'https://api.example.com/v1';
        $this->network->respond($url, new ProbeResponse(42));

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::UNREACHABLE, $outcome->class);
        self::assertNull($outcome->code);
    }

    public function testAnErrorStatusIsNeverRetried(): void
    {
        $url = 'https://api.example.com/v1';
        $this->network->respond($url, new ProbeResponse(503), new ProbeResponse(200));

        self::assertSame(TestClass::SERVER_ERROR, $this->probe([$url])[$url]->class);
        self::assertCount(1, $this->network->calls);
    }

    public function testUnknownHostIsUnreachableWithoutAnyRequest(): void
    {
        $url = 'https://disparue.example.org/api';
        $this->network->host('disparue.example.org');

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::UNREACHABLE, $outcome->class);
        self::assertSame([], $this->network->calls);
        self::assertSame(['disparue.example.org', 'disparue.example.org'], $this->network->lookups);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function addressesNeverCalled(): array
    {
        return [
            'sans protocole' => ['api.example.com/v1'],
            'autre protocole' => ['ftp://api.example.com/v1'],
            'courriel' => ['mailto:contact@example.com'],
            'adresse IPv4' => ['https://' . implode('.', [203, 0, 113, 7]) . '/v1'],
            'adresse IPv4 privée' => ['http://' . implode('.', [10, 0, 0, 5]) . ':8080/v1'],
            'adresse IPv4 en nombre' => ['http://2130706433/'],
            'adresse IPv6' => ['https://[2001:db8::1]/v1'],
            'boucle locale' => ['http://localhost:8080/v1'],
            'nom sans point' => ['https://intranet/api'],
            'nom local' => ['https://serveur.local/api'],
            'nom interne' => ['https://api.service.internal/v1'],
            'identifiant dans l\'adresse' => ['https://' . implode(':', ['utilisateur', 'code']) . '@api.example.com/v1'],
            'espace' => ['https://api.example.com/v 1'],
            'vide' => [''],
        ];
    }

    #[DataProvider('addressesNeverCalled')]
    public function testSomeAddressesAreNeverCalled(string $url): void
    {
        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::UNTESTED, $outcome->class);
        self::assertNull($outcome->code);
        self::assertSame([], $this->network->calls);
        self::assertSame([], $this->network->lookups);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function privateAddresses(): array
    {
        return [
            'réseau privé 10' => [[implode('.', [10, 1, 2, 3])]],
            'réseau privé 172.16' => [[implode('.', [172, 20, 0, 9])]],
            'réseau privé 192.168' => [[implode('.', [192, 168, 1, 10])]],
            'lien local' => [[implode('.', [169, 254, 169, 254])]],
            'boucle locale' => [['127.0.0.1']],
            'adresse nulle' => [['0.0.0.0']],
            'plage partagée des opérateurs' => [[implode('.', [100, 64, 0, 1])]],
            'IPv6 boucle locale' => [['::1']],
            'IPv6 privé' => [['fd00::10']],
            'IPv6 lien local' => [['fe80::1']],
            'IPv4 privée enveloppée en IPv6' => [['::ffff:' . implode('.', [10, 0, 0, 1])]],
            'une adresse publique et une privée' => [[FakeNetwork::PUBLIC_IP, implode('.', [10, 0, 0, 1])]],
        ];
    }

    /**
     * @param list<string> $addresses
     */
    #[DataProvider('privateAddresses')]
    public function testHostResolvingToAPrivateAddressIsNeverCalled(array $addresses): void
    {
        $url = 'https://interne.example.net/api';
        $this->network->host('interne.example.net', ...$addresses)->respond($url, new ProbeResponse(200));

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::UNTESTED, $outcome->class);
        self::assertSame('local or private host', $outcome->detail);
        self::assertSame([], $this->network->calls);
    }

    public function testTheConnectionGoesToTheCheckedAddress(): void
    {
        $url = 'https://api.example.com/v1';
        $this->network->host('api.example.com', '2001:db8::7', '198.51.100.20')->respond($url, new ProbeResponse(200));

        $this->probe([$url]);

        self::assertSame('198.51.100.20', $this->network->calls[0]['ip'], 'adresse IPv4 vérifiée, préférée à l\'adresse IPv6');
    }

    public function testRedirectIsFollowedThroughTheSameChecks(): void
    {
        $url = 'http://ancien.example.net/v1';
        $this->network
            ->respond($url, new ProbeResponse(301, 'https://nouveau.example.org/v1'))
            ->respond('https://nouveau.example.org/v1', new ProbeResponse(200));

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::RESPONDS, $outcome->class);
        self::assertSame(200, $outcome->code);
        self::assertSame([$url, 'https://nouveau.example.org/v1'], $this->network->urls());
        self::assertSame(['ancien.example.net', 'nouveau.example.org'], $this->network->lookups);
    }

    public function testRedirectToAPrivateHostIsNotFollowed(): void
    {
        $url = 'https://detour.example.org/api';
        $this->network
            ->respond($url, new ProbeResponse(302, 'https://cache.example.net/admin'))
            ->host('cache.example.net', implode('.', [192, 168, 0, 12]))
            ->respond('https://cache.example.net/admin', new ProbeResponse(200));

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::UNTESTED, $outcome->class);
        self::assertNull($outcome->code);
        self::assertSame([$url], $this->network->urls(), 'l\'adresse privée n\'est jamais appelée');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedRedirects(): array
    {
        return [
            'boucle locale' => ['http://localhost/admin'],
            'adresse IP' => ['http://' . implode('.', [169, 254, 169, 254]) . '/latest/meta-data/'],
            'autre protocole' => ['file:///etc/passwd'],
            'identifiant' => ['https://' . implode(':', ['admin', 'admin']) . '@api.example.com/'],
        ];
    }

    #[DataProvider('refusedRedirects')]
    public function testRedirectToARefusedAddressIsNotFollowed(string $location): void
    {
        $url = 'https://detour.example.org/api';
        $this->network->respond($url, new ProbeResponse(302, $location));

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::UNTESTED, $outcome->class);
        self::assertSame([$url], $this->network->urls());
    }

    public function testAtMostThreeRedirectsAreFollowed(): void
    {
        $url = 'https://api.example.com/0';
        foreach ([0, 1, 2, 3, 4] as $step) {
            $this->network->respond('https://api.example.com/' . $step, new ProbeResponse(302, 'https://api.example.com/' . ($step + 1)));
        }

        $outcome = $this->probe([$url])[$url];

        self::assertSame(TestClass::RESPONDS, $outcome->class, 'une redirection non suivie reste une réponse sans erreur');
        self::assertSame(302, $outcome->code);
        self::assertCount(4, $this->network->calls, 'la première requête et trois redirections');
    }

    public function testEachDistinctAddressIsCalledOnce(): void
    {
        $url = 'https://api.example.com/v1';
        $this->network->respond($url, new ProbeResponse(200));

        $outcomes = $this->probe([$url, $url, $url]);

        self::assertCount(1, $outcomes);
        self::assertCount(1, $this->network->calls);
    }

    public function testOneRequestPerSecondAtMostPerHost(): void
    {
        $urls = [];
        foreach (['un.example.com' => 5, 'deux.example.com' => 3, 'trois.example.com' => 1] as $host => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $urls[] = 'https://' . $host . '/api/' . $i;
                $this->network->respond('https://' . $host . '/api/' . $i, new ProbeResponse(200));
            }
        }

        $outcomes = $this->probe($urls);

        self::assertCount(9, $outcomes);
        $last = [];
        foreach ($this->network->calls as $call) {
            if (isset($last[$call['host']])) {
                // Une seconde au moins après la fin de la requête précédente vers le même hôte.
                self::assertGreaterThanOrEqual($last[$call['host']] + $this->network->latency + 1.0, $call['time'] + 0.000001, $call['url']);
            }
            $last[$call['host']] = $call['time'];
        }
        self::assertLessThan(6.5, $this->network->time, 'les hôtes sont testés en parallèle : la durée est celle du plus chargé');
    }

    public function testHostsTakeTurns(): void
    {
        $sources = $this->workspace->sources([], ['concurrency' => 1]);
        $urls = [];
        foreach (['un.example.com', 'deux.example.com'] as $host) {
            for ($i = 1; $i <= 3; $i++) {
                $urls[] = 'https://' . $host . '/api/' . $i;
                $this->network->respond('https://' . $host . '/api/' . $i, new ProbeResponse(200));
            }
        }

        $this->probe($urls, $sources);

        self::assertSame(['un.example.com', 'deux.example.com', 'un.example.com', 'deux.example.com', 'un.example.com', 'deux.example.com'], $this->network->hosts());
        self::assertSame(1, $this->network->maxFlying);
    }

    public function testNeverMoreSimultaneousRequestsThanAllowed(): void
    {
        $urls = [];
        for ($i = 1; $i <= 30; $i++) {
            $urls[] = 'https://hote-' . $i . '.example.com/api';
            $this->network->respond('https://hote-' . $i . '.example.com/api', new ProbeResponse(200));
        }

        self::assertCount(30, $this->probe($urls));
        self::assertSame(Sources::load()->concurrency, $this->network->maxFlying);
    }

    public function testAHostThatKeepsFailingIsNotCalledAgain(): void
    {
        $urls = ['https://vivant.example.com/api'];
        $this->network->respond('https://vivant.example.com/api', new ProbeResponse(200));
        for ($i = 1; $i <= 7; $i++) {
            $urls[] = 'https://panne.example.net/api/' . $i;
        }

        $outcomes = $this->probe($urls);

        self::assertSame(TestClass::RESPONDS, $outcomes['https://vivant.example.com/api']->class);
        for ($i = 1; $i <= 7; $i++) {
            self::assertSame(TestClass::UNREACHABLE, $outcomes['https://panne.example.net/api/' . $i]->class);
        }
        $calls = array_values(array_filter($this->network->hosts(), static fn(string $host): bool => $host === 'panne.example.net'));
        self::assertCount(6, $calls, 'trois adresses essayées deux fois, les quatre autres ne sont pas appelées');
    }

    public function testAnAnswerResetsTheFailureCount(): void
    {
        $urls = [];
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            $urls[] = 'https://capricieux.example.net/api/' . $i;
        }
        $this->network->respond('https://capricieux.example.net/api/3', new ProbeResponse(500));

        $outcomes = $this->probe($urls);

        self::assertSame(TestClass::SERVER_ERROR, $outcomes['https://capricieux.example.net/api/3']->class);
        self::assertCount(11, $this->network->calls, 'cinq adresses essayées deux fois et une réponse : l\'hôte n\'a jamais trois échecs de suite');
    }

    public function testTheProbeStopsAfterTheMaximumDuration(): void
    {
        $sources = $this->workspace->sources([], ['max_duration_s' => 10]);
        $this->network->latency = 8.0;
        $urls = [];
        for ($i = 1; $i <= 5; $i++) {
            $urls[] = 'https://lent.example.com/api/' . $i;
            $this->network->respond('https://lent.example.com/api/' . $i, new ProbeResponse(200));
        }

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('Maximum duration of 10 seconds exceeded');
        $this->probe($urls, $sources);
    }

    public function testRequestsAreCounted(): void
    {
        $this->network
            ->respond('https://api.example.com/a', new ProbeResponse(302, 'https://api.example.com/b'))
            ->respond('https://api.example.com/b', new ProbeResponse(200))
            ->respond('https://api.example.com/c', ProbeResponse::failure('timeout'), new ProbeResponse(200));
        $prober = new Prober($this->network, $this->network, Sources::load(), $this->network->sleeper(), $this->network->clock());

        $prober->probe(['https://api.example.com/a', 'https://api.example.com/c', 'ftp://api.example.com/d']);

        self::assertSame(4, $prober->requests(), 'une redirection et un second essai comptent chacun pour une requête');
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, Outcome>
     */
    private function probe(array $urls, ?Sources $sources = null): array
    {
        return (new Prober($this->network, $this->network, $sources ?? Sources::load(), $this->network->sleeper(), $this->network->clock()))->probe($urls);
    }
}
