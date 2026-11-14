<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests;

use PHPUnit\Framework\TestCase;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Apis;
use PolygonLab\PublicApisHealth\Tests\Fixtures\Workspace;
use PolygonLab\PublicApisHealth\Tools\Api;
use PolygonLab\PublicApisHealth\Tools\ForbiddenWords;
use PolygonLab\PublicApisHealth\Tools\Screening;
use PolygonLab\PublicApisHealth\Tools\SourceError;

/**
 * Le tri des entrées avant publication : mot interdit, adresse IPv4, adresse e-mail restée dans une adresse, motif
 * de secret, texte entre doubles accolades. Une entrée écartée ne bloque pas les autres.
 */
final class ScreeningTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new Workspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testAPublishableEntryIsKept(): void
    {
        $screened = $this->screening()->screen([Apis::make(1, description: 'Nothing special here, version 2.1.'), Apis::make(2)]);

        self::assertCount(2, $screened['kept']);
        self::assertSame([], $screened['discarded']);
    }

    public function testAForbiddenWordInTheDescriptionDiscardsTheEntry(): void
    {
        $screened = $this->screening()->screen([Apis::make(1), Apis::make(2, description: 'Service of the ForbiddenWord company.')]);

        self::assertSame(['https://docs.example.com/api-1/'], array_map(static fn(Api $api): string => $api->url, $screened['kept']));
        self::assertCount(1, $screened['discarded']);
        self::assertSame('forbidden word (field "description")', $screened['discarded'][0]['reason']);
        self::assertSame('Sample API 2', $screened['discarded'][0]['name']);
        self::assertSame('https://docs.example.com/api-2/', $screened['discarded'][0]['url']);
    }

    public function testAForbiddenWordInTheNameIsNeverShown(): void
    {
        $api = Apis::make(3, name: 'API ' . Workspace::FORBIDDEN_WORD);

        $screened = $this->screening()->screen([$api]);

        self::assertSame('forbidden word (field "name")', $screened['discarded'][0]['reason']);
        self::assertSame('[name withheld ' . substr(hash('sha256', $api->url), 0, 8) . ']', $screened['discarded'][0]['name']);
        self::assertSame($api->url, $screened['discarded'][0]['url']);
    }

    public function testARefusedLinkIsNeverShown(): void
    {
        $api = Apis::make(4, host: Workspace::FORBIDDEN_WORD . '.example.com');

        $screened = $this->screening()->screen([$api]);

        self::assertSame('forbidden word (field "url")', $screened['discarded'][0]['reason']);
        self::assertSame('[url withheld ' . substr(hash('sha256', $api->url), 0, 8) . ']', $screened['discarded'][0]['url']);
        self::assertSame('Sample API 4', $screened['discarded'][0]['name']);
    }

    public function testAForbiddenWordIsLookedForInEveryPublishedValue(): void
    {
        $screened = $this->screening()->screen([
            Apis::make(5, category: 'Tools of ' . Workspace::FORBIDDEN_WORD),
            Apis::make(6, auth: Workspace::FORBIDDEN_WORD),
            new Api('Sample API 7', 'https://docs.example.com/api-7/', null, 'Sample category', 'No', Workspace::FORBIDDEN_WORD, 'Yes'),
            new Api('Sample API 8', 'https://docs.example.com/api-8/', null, 'Sample category', 'No', 'Yes', Workspace::FORBIDDEN_WORD),
        ]);

        self::assertSame([], $screened['kept']);
        self::assertSame(
            ['forbidden word (field "category")', 'forbidden word (field "auth")', 'forbidden word (field "https")', 'forbidden word (field "cors")'],
            array_column($screened['discarded'], 'reason'),
        );
    }

    public function testAToleratedWritingIsKept(): void
    {
        $screened = $this->screening()->screen([Apis::make(9, description: 'Works with forbiddenword-allowed.')]);

        self::assertCount(1, $screened['kept']);
    }

    public function testWithoutForbiddenWordsNothingIsDiscardedForThem(): void
    {
        $screened = (new Screening(ForbiddenWords::none()))->screen([Apis::make(10, name: 'API ' . Workspace::FORBIDDEN_WORD)]);

        self::assertCount(1, $screened['kept']);
    }

    public function testAnIpv4AddressDiscardsTheEntry(): void
    {
        $screened = $this->screening()->screen([
            Apis::make(11, description: 'Backup server on 192.0.2.44.'),
            Apis::make(12, host: 'lookup.example.com/' . implode('.', [203, 0, 113, 9])),
        ]);

        self::assertSame(['IPv4 address (field "description")', 'IPv4 address (field "url")'], array_column($screened['discarded'], 'reason'));
        self::assertSame('[url withheld ' . substr(hash('sha256', $screened['discarded'][1]['api']->url), 0, 8) . ']', $screened['discarded'][1]['url']);
    }

    public function testASecretOrATemplateDiscardsTheEntry(): void
    {
        $token = 'ghp_' . str_repeat('a1B2', 9);
        $screened = $this->screening()->screen([
            Apis::make(13, description: 'Demo token: ' . $token),
            Apis::make(14, description: 'Call /v1/' . '{' . '{id}' . '}' . '/detail'),
        ]);

        self::assertSame(
            ['API key or token pattern (field "description")', 'text between double braces (field "description")'],
            array_column($screened['discarded'], 'reason'),
        );
    }

    public function testAnEmailOrCredentialsLeftInALinkDiscardTheEntry(): void
    {
        $screened = $this->screening()->screen([
            Apis::make(15, host: 'docs.example.com/contact@example.com'),
            Apis::make(16, host: implode(':', ['user', 'password']) . '@docs.example.com'),
        ]);

        self::assertSame([], $screened['kept']);
        self::assertSame(['e-mail address (field "url")', 'e-mail address (field "url")'], array_column($screened['discarded'], 'reason'));
    }

    public function testASafeTextIsToldFromARefusedOne(): void
    {
        $screening = $this->screening();

        self::assertTrue($screening->isSafe('Sample API'));
        self::assertFalse($screening->isSafe('Sample ' . Workspace::FORBIDDEN_WORD));
        self::assertFalse($screening->isSafe('contact@example.com'));
    }

    public function testAnInvalidForbiddenWordsFileIsRefused(): void
    {
        file_put_contents($this->workspace->forbiddenWords, '{"mots": [{"mot": ""}]}');

        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('word no. 1 empty or missing');
        ForbiddenWords::load($this->workspace->forbiddenWords);
    }

    private function screening(): Screening
    {
        return new Screening(ForbiddenWords::load($this->workspace->forbiddenWords));
    }
}
