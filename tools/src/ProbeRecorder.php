<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Enregistrement des tests d'adresses d'une collecte (option --record), pour les rejouer avec --replay.
 *
 * Le fichier « probes.json » garde, pour chaque adresse appelée, le code reçu, la redirection demandée et l'erreur,
 * et pour chaque hôte ses adresses IP. Ce fichier sert au rejeu local : il n'est jamais publié.
 */
final class ProbeRecorder implements Transport, Resolver
{
    /** @var array<string, list<string>> */
    private array $resolutions = [];

    /** @var array<string, array{code: int|null, redirect: string|null, error: string|null}> */
    private array $responses = [];

    /** @var array<int, string> adresse de chaque requête en cours */
    private array $urls = [];

    public function __construct(
        private readonly Transport $transport,
        private readonly Resolver $resolver,
        private readonly string $directory,
    ) {}

    public function resolve(string $host): array
    {
        return $this->resolutions[$host] = $this->resolver->resolve($host);
    }

    public function start(int $id, ProbeRequest $request): void
    {
        $this->urls[$id] = $request->url;
        $this->transport->start($id, $request);
    }

    public function wait(float $seconds): array
    {
        $responses = $this->transport->wait($seconds);
        foreach ($responses as $id => $response) {
            $url = $this->urls[$id] ?? null;
            if ($url !== null) {
                $this->responses[$url] = ['code' => $response->status, 'redirect' => $response->location, 'error' => $response->error];
                unset($this->urls[$id]);
            }
        }

        return $responses;
    }

    /**
     * Écrit le fichier des tests enregistrés.
     *
     * @throws SourceError
     */
    public function save(): void
    {
        ksort($this->resolutions, SORT_STRING);
        ksort($this->responses, SORT_STRING);
        $content = JsonFile::encode(['resolutions' => $this->resolutions, 'responses' => $this->responses]);
        $path = rtrim($this->directory, '/') . '/' . ProbeReplay::FILE;
        if (file_put_contents($path, $content) !== \strlen($content)) {
            throw new SourceError('Recording failed: ' . $path . '.');
        }
    }
}
