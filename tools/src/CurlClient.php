<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Lecture HTTPS (extension curl) du fichier de la liste : en-tête User-Agent qui identifie l'outil, essais espacés
 * (5 puis 15 secondes), délai et taille bornés, adresses https uniquement (redirections comprises, trois au plus),
 * réponse 200 exigée.
 */
final class CurlClient implements HttpClient
{
    public const USER_AGENT = 'PolygonLab-actualisation-donnees (+https://github.com/polygonlab-ai/public-apis-health)';

    /** Pause avant le 2e essai, puis avant chacun des suivants (secondes). */
    private const FIRST_PAUSE = 5;

    private const NEXT_PAUSE = 15;

    private readonly \Closure $sleep;

    public function __construct(
        private readonly int $attempts,
        private readonly int $timeout,
        private readonly int $maxSize,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public static function fromSources(Sources $sources): self
    {
        return new self($sources->attempts, $sources->timeoutSeconds, $sources->maxSize);
    }

    public function get(string $url): string
    {
        if (!\function_exists('curl_init')) {
            throw new SourceError('PHP curl extension missing: the list cannot be read (replay a recorded collection with --replay).');
        }
        if ($url === '' || preg_match('~\Ahttps://[^\s]+\z~', $url) !== 1) {
            throw new SourceError('Address refused (https expected): ' . $url . '.');
        }
        $last = '';
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            if ($attempt > 1) {
                ($this->sleep)($attempt === 2 ? self::FIRST_PAUSE : self::NEXT_PAUSE);
            }
            [$body, $last] = $this->attempt($url);
            if ($body !== null) {
                return $body;
            }
        }

        throw new SourceError(sprintf('List unreachable after %d attempt(s): %s (%s).', $this->attempts, $url, $last));
    }

    /**
     * @param non-empty-string $url
     *
     * @return array{0: string|null, 1: string} corps (null en cas d'échec), cause de l'échec
     */
    private function attempt(string $url): array
    {
        $curl = curl_init();
        if ($curl === false) {
            return [null, 'curl could not be initialised'];
        }
        $maxSize = $this->maxSize;
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(20, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/plain, text/markdown, */*'],
            CURLOPT_ENCODING => '',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn(mixed $c, int $total, int $received): int => $received > $maxSize ? 1 : 0,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!\is_string($body) || $status !== 200) {
            return [null, $error !== '' ? $error : 'HTTP ' . $status];
        }
        if (\strlen($body) > $maxSize) {
            return [null, sprintf('file larger than %d bytes', $maxSize)];
        }

        return [$body, ''];
    }
}
