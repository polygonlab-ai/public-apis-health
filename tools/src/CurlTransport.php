<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Requêtes de test par l'extension curl, plusieurs à la fois (curl_multi).
 *
 * Chaque requête : GET sans identifiant ni en-tête d'authentification, sans témoin (cookie), délai borné, aucune
 * redirection suivie, protocoles http et https seulement, corps reçu jusqu'à la taille maximale puis abandonné
 * (il n'est jamais gardé : seul le code de la réponse compte).
 * La connexion se fait vers l'adresse IP déjà vérifiée par Prober (le nom n'est pas résolu une seconde fois) et
 * sans serveur mandataire : une adresse locale ou privée ne peut pas être atteinte par un détour.
 */
final class CurlTransport implements Transport
{
    public const USER_AGENT = CurlClient::USER_AGENT;

    /** Code d'erreur de curl pour une adresse mal formée. */
    private const MALFORMED = 3;

    private ?\CurlMultiHandle $multi = null;

    /** @var array<int, array{id: int, handle: \CurlHandle}> requêtes en cours, par numéro d'objet de la poignée curl */
    private array $handles = [];

    /** @var array<int, int> octets du corps déjà reçus pour chaque réponse, par numéro d'objet */
    private array $received = [];

    /** @var array<int, ProbeResponse> requêtes qui n'ont pas pu partir, par numéro de requête */
    private array $failed = [];

    public function __construct(
        private readonly int $timeout,
        private readonly int $maxBodySize,
    ) {}

    public static function fromSources(Sources $sources): self
    {
        return new self($sources->probeTimeoutSeconds, $sources->maxBodySize);
    }

    public function start(int $id, ProbeRequest $request): void
    {
        if (!\function_exists('curl_multi_init')) {
            throw new SourceError('PHP curl extension missing: the links cannot be tested (replay a recorded collection with --replay).');
        }
        $url = $request->url;
        if ($url === '') {
            $this->failed[$id] = ProbeResponse::failure('empty address');

            return;
        }
        $handle = curl_init();
        if ($handle === false) {
            $this->failed[$id] = ProbeResponse::failure('curl could not be initialised');

            return;
        }
        $this->multi ??= curl_multi_init();
        $key = spl_object_id($handle);
        $this->received[$key] = 0;
        $ip = IpAddress::isV6($request->ip) ? '[' . $request->ip . ']' : $request->ip;
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_ENCODING => '',
            CURLOPT_RESOLVE => [$request->host . ':' . $request->port . ':' . $ip],
            CURLOPT_PROXY => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $curl, string $chunk) use ($key): int {
                $this->received[$key] = ($this->received[$key] ?? 0) + \strlen($chunk);

                // Une valeur différente de la taille reçue arrête le transfert : la suite du corps n'est pas lue.
                return $this->received[$key] > $this->maxBodySize ? 0 : \strlen($chunk);
            },
        ]);
        curl_multi_add_handle($this->multi, $handle);
        $this->handles[$key] = ['id' => $id, 'handle' => $handle];
    }

    public function wait(float $seconds): array
    {
        $responses = $this->failed;
        $this->failed = [];
        if ($this->multi === null || $this->handles === []) {
            return $responses;
        }
        $running = 0;
        curl_multi_exec($this->multi, $running);
        $responses += $this->finished($this->multi);
        if ($responses === [] && $running > 0) {
            curl_multi_select($this->multi, max(0.01, $seconds));
            curl_multi_exec($this->multi, $running);
            $responses = $this->finished($this->multi);
        }

        return $responses;
    }

    /**
     * @return array<int, ProbeResponse>
     */
    private function finished(\CurlMultiHandle $multi): array
    {
        $responses = [];
        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            if (!$handle instanceof \CurlHandle) {
                continue;
            }
            $key = spl_object_id($handle);
            $entry = $this->handles[$key] ?? null;
            if ($entry === null) {
                continue;
            }
            $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $location = curl_getinfo($handle, CURLINFO_REDIRECT_URL);
            $errno = $info['result'];
            if ($code > 0) {
                // Une réponse est arrivée : son code suffit, même si la lecture du corps a été arrêtée.
                $response = new ProbeResponse($code, \is_string($location) && $location !== '' ? $location : null);
            } elseif ($errno === self::MALFORMED) {
                $response = new ProbeResponse(null, null, 'malformed address', true);
            } else {
                $response = ProbeResponse::failure(\is_int($errno) ? (string) curl_strerror($errno) : 'no response');
            }
            $responses[$entry['id']] = $response;
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($this->handles[$key], $this->received[$key]);
        }

        return $responses;
    }
}
