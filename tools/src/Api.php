<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Une entrée de la liste : son nom, son lien (adresse normalisée, qui est sa clé), sa description, sa catégorie, les
 * valeurs « Auth », « HTTPS » et « CORS » déclarées par la liste, et le résultat du test de son lien.
 *
 * Les valeurs « auth », « https » et « cors » sont celles de la liste, telles qu'elles y sont écrites (sans les
 * accents graves) : elles ne sont ni vérifiées ni mesurées par l'outil.
 *
 * @phpstan-type Card array{name: string, url: string, host: string, description: string|null, category: string, auth: string|null, https: string|null, cors: string|null}
 */
final class Api
{
    public const DESCRIPTION_LENGTH = 300;

    /** Valeurs attendues de chaque colonne déclarative ; toute autre valeur est gardée telle quelle et comptée. */
    public const EXPECTED = [
        'auth' => ['No', 'apiKey', 'OAuth', 'X-Mashape-Key', 'User-Agent'],
        'https' => ['Yes', 'No'],
        'cors' => ['Yes', 'No', 'Unknown'],
    ];

    /** Champs comparés d'un passage à l'autre (ni l'adresse, qui est la clé, ni le résultat du test). */
    public const COMPARED = ['name', 'description', 'category', 'auth', 'https', 'cors'];

    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $description,
        public readonly string $category,
        public readonly ?string $auth,
        public readonly ?string $https,
        public readonly ?string $cors,
        public readonly string $test = TestClass::UNTESTED,
        public readonly ?int $httpCode = null,
    ) {}

    /**
     * Hôte du lien, en minuscules.
     */
    public function host(): string
    {
        return Address::hostOf($this->url) ?? '';
    }

    /**
     * Adresse appelée par le test : le lien sans son ancre.
     */
    public function testUrl(): string
    {
        return Address::withoutFragment($this->url);
    }

    /**
     * Même entrée, avec le résultat de son test.
     */
    public function withTest(string $test, ?int $httpCode): self
    {
        return new self($this->name, $this->url, $this->description, $this->category, $this->auth, $this->https, $this->cors, $test, $httpCode);
    }

    /**
     * Valeurs textuelles publiées, par champ, pour les contrôles de Screening.
     *
     * @return array<string, string>
     */
    public function texts(): array
    {
        $texts = ['url' => $this->url, 'name' => $this->name, 'category' => $this->category];
        foreach (['description' => $this->description, 'auth' => $this->auth, 'https' => $this->https, 'cors' => $this->cors] as $field => $value) {
            if ($value !== null) {
                $texts[$field] = $value;
            }
        }

        return $texts;
    }

    /**
     * Fiche de l'entrée, dans l'ordre du fichier publié.
     *
     * @return Card
     */
    public function card(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'host' => $this->host(),
            'description' => $this->description,
            'category' => $this->category,
            'auth' => $this->auth,
            'https' => $this->https,
            'cors' => $this->cors,
        ];
    }

    /**
     * Forme normalisée de la collecte : la fiche et la classe du test, sans date ni code HTTP. C'est elle qui porte
     * l'empreinte : elle ne change que si l'entrée change de fiche ou de classe de test.
     *
     * @return array<string, mixed>
     */
    public function normalized(): array
    {
        return $this->card() + ['test' => ['result' => $this->test]];
    }

    /**
     * Entrée relue d'un fichier de l'outil (collecte normalisée ou data/apis.json).
     *
     * @param array<mixed> $data
     *
     * @throws SourceError
     */
    public static function fromArray(array $data, string $label): self
    {
        $url = $data['url'] ?? null;
        $name = $data['name'] ?? null;
        $category = $data['category'] ?? null;
        if (!\is_string($url) || $url === '' || !\is_string($name) || $name === '' || !\is_string($category) || $category === '') {
            throw new SourceError($label . ': entry without "url", "name" or "category".');
        }
        $texts = [];
        foreach (['description', 'auth', 'https', 'cors'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && !\is_string($value)) {
                throw new SourceError($label . ': "' . $field . '" of "' . $url . '": text or null expected.');
            }
            $texts[$field] = $value;
        }
        $test = $data['test'] ?? null;
        if (!\is_array($test)) {
            throw new SourceError($label . ': "test" of "' . $url . '": object expected.');
        }
        $class = $test['result'] ?? null;
        if (!\is_string($class) || !\in_array($class, TestClass::ALL, true)) {
            throw new SourceError($label . ': "test.result" of "' . $url . '": test class expected.');
        }
        $code = $test['http_code'] ?? null;

        return new self($name, $url, $texts['description'], $category, $texts['auth'], $texts['https'], $texts['cors'], $class, \is_int($code) ? $code : null);
    }
}
