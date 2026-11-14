<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests\Fixtures;

use PolygonLab\PublicApisHealth\Tools\ProbeRequest;
use PolygonLab\PublicApisHealth\Tools\ProbeResponse;
use PolygonLab\PublicApisHealth\Tools\Resolver;
use PolygonLab\PublicApisHealth\Tools\Transport;

/**
 * Faux réseau des tests : réponses prévues par adresse, résolutions prévues par hôte, horloge fictive. Aucun appel
 * réel. Chaque requête lancée est notée avec son heure fictive, son hôte et l'adresse IP visée, pour vérifier la
 * politesse et la sécurité du test des adresses.
 */
final class FakeNetwork implements Transport, Resolver
{
    /** Adresse publique d'exemple (plage réservée à la documentation). */
    public const PUBLIC_IP = '192.0.2.10';

    /** Heure fictive, en secondes. */
    public float $time = 0.0;

    /** Durée fictive d'une requête, en secondes. */
    public float $latency = 0.2;

    /** @var list<array{time: float, host: string, url: string, ip: string}> requêtes lancées, dans l'ordre */
    public array $calls = [];

    /** @var list<string> hôtes dont la résolution a été demandée, dans l'ordre */
    public array $lookups = [];

    /** Plus grand nombre de requêtes en cours au même moment. */
    public int $maxFlying = 0;

    /** @var array<string, list<ProbeResponse>> */
    private array $responses = [];

    /** @var array<string, list<string>> */
    private array $addresses = [];

    /** @var array<int, array{at: float, response: ProbeResponse}> */
    private array $pending = [];

    /**
     * Réponses successives d'une adresse ; la dernière est répétée.
     */
    public function respond(string $url, ProbeResponse ...$responses): self
    {
        $this->responses[$url] = array_values($responses);

        return $this;
    }

    /**
     * Adresses IP d'un hôte (aucune : nom introuvable). Un hôte non déclaré a une adresse publique d'exemple.
     */
    public function host(string $host, string ...$addresses): self
    {
        $this->addresses[$host] = array_values($addresses);

        return $this;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->addresses[$host] ?? [self::PUBLIC_IP];
    }

    public function start(int $id, ProbeRequest $request): void
    {
        $this->calls[] = ['time' => $this->time, 'host' => $request->host, 'url' => $request->url, 'ip' => $request->ip];
        $planned = $this->responses[$request->url] ?? [];
        $response = $planned[0] ?? ProbeResponse::failure('no response planned');
        if (\count($planned) > 1) {
            $this->responses[$request->url] = \array_slice($planned, 1);
        }
        $this->pending[$id] = ['at' => $this->time + $this->latency, 'response' => $response];
        $this->maxFlying = max($this->maxFlying, \count($this->pending));
    }

    public function wait(float $seconds): array
    {
        if ($this->pending === []) {
            return [];
        }
        $first = min(array_column($this->pending, 'at'));
        $this->time = max($this->time, min($first, $this->time + $seconds));
        $responses = [];
        foreach ($this->pending as $id => $entry) {
            if ($entry['at'] <= $this->time) {
                $responses[$id] = $entry['response'];
                unset($this->pending[$id]);
            }
        }

        return $responses;
    }

    /**
     * Attente fictive : l'heure avance, rien n'attend vraiment.
     */
    public function sleeper(): \Closure
    {
        return function (float $seconds): void {
            $this->time += $seconds;
        };
    }

    public function clock(): \Closure
    {
        return fn(): float => $this->time;
    }

    /**
     * Adresses appelées, dans l'ordre.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        return array_column($this->calls, 'url');
    }

    /**
     * Hôtes appelés, dans l'ordre.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_column($this->calls, 'host');
    }
}
