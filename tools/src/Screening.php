<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Tri des entrées lues dans la liste, avant publication.
 *
 * Une entrée est écartée automatiquement, sans bloquer les autres, si l'une de ses valeurs publiées (adresse, nom,
 * description, catégorie, valeurs déclarées) contient un mot interdit (option --forbidden-words), une adresse IPv4,
 * une adresse e-mail restée dans une adresse, un motif de secret (clé d'API, jeton) ou un texte entre doubles
 * accolades.
 *
 * Les adresses e-mail des textes ont déjà été remplacées à la lecture de la liste (voir MarkdownList).
 *
 * Une entrée écartée est citée par son adresse et son nom, sauf si l'un d'eux est lui-même en cause : il devient
 * alors « [url withheld xxxxxxxx] » ou « [name withheld xxxxxxxx] » (début de l'empreinte SHA-256 de l'adresse),
 * pour que rien de refusé ne soit publié.
 *
 * @phpstan-type Discarded array{api: Api, url: string, name: string, reason: string}
 */
final class Screening
{
    public const IPV4 = '/(?<![\d.])((?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?:\.(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3})(?!\.?\d)/';

    private const TEMPLATE = '/(?<!\$)\{\{[^{}\n]*\}\}/';

    /** Motifs de secrets (clés d'API, jetons). */
    private const SECRETS = [
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/(?<![A-Za-z0-9_-])sk-[A-Za-z0-9_-]{20,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/-{5}BEGIN/',
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}/',
        '/\bxapp-[A-Za-z0-9-]{10,}/',
        '/\bSG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/',
        '/\bAIza[0-9A-Za-z_-]{35}/',
        '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{16,}/',
        '/\bwhsec_[A-Za-z0-9]{20,}/',
        '/\bglpat-[A-Za-z0-9_-]{20,}/',
        '/\bnpm_[A-Za-z0-9]{36}\b/',
        '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
        '~[a-z][a-z0-9+.-]*://[^/\s:@"\'<>]+:[^/\s@"\'<>]+@~i',
        '/\b(?:password|passwd|pwd|mot_de_passe|secret|api_?key|access_?key|token)\b["\']?\s*(?:=>|=|:)\s*["\']([^"\'\s]{12,})["\']/i',
    ];

    public function __construct(private readonly ForbiddenWords $forbiddenWords) {}

    /**
     * @param list<Api> $apis
     *
     * @return array{kept: list<Api>, discarded: list<Discarded>}
     */
    public function screen(array $apis): array
    {
        $kept = [];
        $discarded = [];
        foreach ($apis as $api) {
            $reason = null;
            foreach ($api->texts() as $field => $text) {
                $reason = $this->problem($text);
                if ($reason !== null) {
                    $reason .= ' (field "' . $field . '")';
                    break;
                }
            }
            if ($reason === null) {
                $kept[] = $api;
                continue;
            }
            $discarded[] = ['api' => $api, 'url' => $this->displayUrl($api), 'name' => $this->displayName($api), 'reason' => $reason];
        }

        return ['kept' => $kept, 'discarded' => $discarded];
    }

    /**
     * Nom publiable d'une entrée : son nom, ou « [name withheld xxxxxxxx] » si le nom lui-même est refusé.
     */
    public function displayName(Api $api): string
    {
        return $this->isSafe($api->name) ? $api->name : '[name withheld ' . substr(hash('sha256', $api->url), 0, 8) . ']';
    }

    /**
     * Adresse publiable d'une entrée : son adresse, ou « [url withheld xxxxxxxx] » si elle est elle-même refusée.
     */
    public function displayUrl(Api $api): string
    {
        return $this->isSafe($api->url) ? $api->url : '[url withheld ' . substr(hash('sha256', $api->url), 0, 8) . ']';
    }

    /**
     * Vrai si le texte peut être publié tel quel.
     */
    public function isSafe(string $text): bool
    {
        return $this->problem($text) === null;
    }

    /**
     * Motif de refus d'une valeur, ou null si elle est publiable.
     */
    private function problem(string $text): ?string
    {
        if ($this->forbiddenWords->found($text)) {
            return 'forbidden word';
        }
        if (preg_match(self::IPV4, $text) === 1) {
            return 'IPv4 address';
        }
        if (Text::hasEmail($text)) {
            return 'e-mail address';
        }
        foreach (self::SECRETS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return 'API key or token pattern';
            }
        }
        if (preg_match(self::TEMPLATE, $text) === 1) {
            return 'text between double braces';
        }

        return null;
    }
}
