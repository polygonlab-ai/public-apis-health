<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Test des adresses : une requête GET par adresse distincte, sans identifiant, puis classement de la réponse.
 *
 * Politesse :
 *   - jamais deux requêtes en même temps vers le même hôte, et un intervalle entre deux requêtes vers lui
 *     (1 seconde par défaut), compté à partir de la fin de la précédente ;
 *   - les hôtes passent à tour de rôle : un hôte qui vient d'être appelé repasse en fin de tour ;
 *   - un seul essai par adresse, plus un second après une pause (5 secondes) en cas d'erreur réseau seulement ;
 *   - après plusieurs adresses de suite sans réponse sur un même hôte (3 par défaut), les adresses restantes de cet
 *     hôte sont classées « unreachable » sans être appelées : on n'insiste pas sur un serveur en panne.
 *
 * Sécurité :
 *   - seules les adresses http et https dont l'hôte est un nom public sont appelées (voir Address) ;
 *   - le nom est résolu ici, et si l'une de ses adresses IP est locale, privée ou de lien local, rien n'est appelé ;
 *     la connexion se fait vers l'adresse IP vérifiée ;
 *   - une redirection n'est jamais suivie par le transport : son adresse repasse par les mêmes contrôles, comme une
 *     adresse nouvelle (3 redirections au plus).
 *
 * Les adresses qui ne sont pas appelées sont classées « not_tested ».
 *
 * @phpstan-type Task array{origin: string, address: Address, hops: int, retried: bool}
 */
final class Prober
{
    private readonly \Closure $sleep;

    private readonly \Closure $clock;

    /** @var array<int, Task> adresses en cours de test, par numéro */
    private array $tasks = [];

    /** @var array<string, list<int>> file d'attente de chaque hôte, dans l'ordre de passage des hôtes */
    private array $queues = [];

    /** @var array<int, string> hôte de chaque requête partie et sans réponse */
    private array $flying = [];

    /** @var array<string, float> moment à partir duquel un hôte peut être appelé de nouveau */
    private array $nextAt = [];

    /** @var array<string, int> adresses de suite sans réponse, par hôte */
    private array $failures = [];

    /** @var array<string, true> hôtes qui ne sont plus appelés */
    private array $dead = [];

    /** @var array<string, list<string>> adresses IP vérifiées, par hôte */
    private array $resolved = [];

    /** @var array<string, Outcome> */
    private array $outcomes = [];

    private int $requests = 0;

    public function __construct(
        private readonly Transport $transport,
        private readonly Resolver $resolver,
        private readonly Sources $sources,
        ?\Closure $sleep = null,
        ?\Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * Teste chaque adresse distincte une fois.
     *
     * @param list<string> $urls
     *
     * @return array<string, Outcome> résultat de chaque adresse
     *
     * @throws SourceError durée maximale dépassée
     */
    public function probe(array $urls): array
    {
        $this->tasks = [];
        $this->queues = [];
        $this->flying = [];
        $this->nextAt = [];
        $this->failures = [];
        $this->dead = [];
        $this->outcomes = [];
        $this->requests = 0;
        $number = 0;
        foreach (array_unique($urls) as $url) {
            $address = Address::parse($url);
            if (\is_string($address)) {
                $this->outcomes[$url] = Outcome::untested($address);
                continue;
            }
            $this->tasks[++$number] = ['origin' => $url, 'address' => $address, 'hops' => 0, 'retried' => false];
            $this->queues[$address->host][] = $number;
        }

        $start = $this->now();
        while ($this->tasks !== []) {
            $now = $this->now();
            if ($now - $start > $this->sources->maxProbeDurationSeconds) {
                throw new SourceError(sprintf(
                    'Maximum duration of %d seconds exceeded while testing the links (%d address(es) left): collection stopped.',
                    $this->sources->maxProbeDurationSeconds,
                    \count($this->tasks),
                ));
            }
            $this->launch($now);
            if ($this->flying === []) {
                $this->pause();
                continue;
            }
            foreach ($this->transport->wait($this->delay()) as $id => $response) {
                $this->receive($id, $response);
            }
        }

        return $this->outcomes;
    }

    /**
     * Nombre de requêtes envoyées par le dernier test (seconds essais et redirections compris).
     */
    public function requests(): int
    {
        return $this->requests;
    }

    /**
     * Lance une requête sur chaque hôte disponible, dans l'ordre du tour, sans dépasser le nombre de requêtes
     * simultanées.
     */
    private function launch(float $now): void
    {
        foreach (array_keys($this->queues) as $host) {
            if (\count($this->flying) >= $this->sources->concurrency) {
                return;
            }
            if (\in_array($host, $this->flying, true) || ($this->nextAt[$host] ?? 0.0) > $now) {
                continue;
            }
            $queue = $this->queues[$host];
            unset($this->queues[$host]);
            if (isset($this->dead[$host])) {
                foreach ($queue as $id) {
                    $this->finish($id, Outcome::unreachable(sprintf('host without any response for %d addresses in a row: address not called', $this->sources->maxConsecutiveFailures)));
                }
                continue;
            }
            $id = array_shift($queue);
            if ($queue !== []) {
                // L'hôte repasse en fin de tour.
                $this->queues[$host] = $queue;
            }
            if ($id !== null) {
                $this->start($id, $host);
            }
        }
    }

    private function start(int $id, string $host): void
    {
        $addresses = $this->resolved[$host] ?? $this->resolver->resolve($host);
        if ($addresses === []) {
            $this->fail($id, $host, 'host name not found');

            return;
        }
        $this->resolved[$host] = $addresses;
        $chosen = $addresses[0];
        foreach ($addresses as $ip) {
            if (!IpAddress::isPublic($ip)) {
                $this->finish($id, Outcome::untested('local or private host'));

                return;
            }
            if (IpAddress::isV6($chosen) && !IpAddress::isV6($ip)) {
                $chosen = $ip;
            }
        }
        $address = $this->tasks[$id]['address'];
        $this->flying[$id] = $host;
        $this->requests++;
        $this->transport->start($id, new ProbeRequest($address->url, $host, $address->port, $chosen));
    }

    private function receive(int $id, ProbeResponse $response): void
    {
        $host = $this->flying[$id] ?? null;
        if ($host === null || !isset($this->tasks[$id])) {
            return;
        }
        unset($this->flying[$id]);
        $this->nextAt[$host] = $this->now() + $this->sources->hostIntervalSeconds;
        $status = $response->status;
        if ($status !== null && ($status < 100 || $status > 999)) {
            // Un code hors de la norme HTTP n'est pas une réponse lisible.
            $this->fail($id, $host, 'unreadable HTTP response');

            return;
        }
        if ($status === null) {
            if ($response->malformed) {
                $this->finish($id, Outcome::untested('invalid address'));

                return;
            }
            $this->fail($id, $host, $response->error ?? 'no response');

            return;
        }
        $this->failures[$host] = 0;
        $task = $this->tasks[$id];
        if ($status >= 300 && $status < 400 && $response->location !== null && $task['hops'] < $this->sources->maxRedirects) {
            $next = Address::parse($response->location);
            if (\is_string($next)) {
                $this->finish($id, Outcome::untested('redirect not followed (' . $next . ')'));

                return;
            }
            $task['address'] = $next;
            $task['hops']++;
            $task['retried'] = false;
            $this->tasks[$id] = $task;
            $this->queues[$next->host][] = $id;

            return;
        }
        $this->finish($id, new Outcome(TestClass::fromStatus($status), $status, 'HTTP ' . $status));
    }

    /**
     * Erreur réseau : second essai après une pause, puis « unreachable ».
     */
    private function fail(int $id, string $host, string $reason): void
    {
        $now = $this->now();
        $task = $this->tasks[$id];
        if (!$task['retried']) {
            $task['retried'] = true;
            $this->tasks[$id] = $task;
            $queue = $this->queues[$host] ?? [];
            array_unshift($queue, $id);
            $this->queues[$host] = $queue;
            $this->nextAt[$host] = $now + $this->sources->retryPauseSeconds;

            return;
        }
        $this->failures[$host] = ($this->failures[$host] ?? 0) + 1;
        if ($this->failures[$host] >= $this->sources->maxConsecutiveFailures) {
            $this->dead[$host] = true;
        }
        $this->nextAt[$host] = $now + $this->sources->hostIntervalSeconds;
        $this->finish($id, Outcome::unreachable($reason));
    }

    private function finish(int $id, Outcome $outcome): void
    {
        $this->outcomes[$this->tasks[$id]['origin']] = $outcome;
        unset($this->tasks[$id]);
    }

    /**
     * Aucune requête n'est partie : attend le moment où le premier hôte en attente peut être appelé.
     *
     * @throws SourceError
     */
    private function pause(): void
    {
        if ($this->tasks === []) {
            return;
        }
        if ($this->queues === []) {
            throw new SourceError('Link test: addresses are left to test without being queued (error in the tool).');
        }
        $next = null;
        foreach (array_keys($this->queues) as $host) {
            $at = $this->nextAt[$host] ?? 0.0;
            $next = $next === null ? $at : min($next, $at);
        }
        $wait = $next - $this->now();
        if ($wait > 0) {
            ($this->sleep)($wait);
        }
    }

    /**
     * Temps d'attente des réponses : jusqu'au moment où un hôte libre peut être appelé, un quart de seconde au plus.
     */
    private function delay(): float
    {
        $now = $this->now();
        $delay = 0.25;
        foreach (array_keys($this->queues) as $host) {
            if (!\in_array($host, $this->flying, true)) {
                $delay = min($delay, max(0.01, ($this->nextAt[$host] ?? 0.0) - $now));
            }
        }

        return $delay;
    }

    private function now(): float
    {
        $now = ($this->clock)();

        return \is_float($now) || \is_int($now) ? (float) $now : 0.0;
    }
}
