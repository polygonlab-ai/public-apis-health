<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tests\Fixtures;

use PolygonLab\PublicApisHealth\Tools\JsonFile;
use PolygonLab\PublicApisHealth\Tools\ListPage;
use PolygonLab\PublicApisHealth\Tools\ProbeReplay;
use PolygonLab\PublicApisHealth\Tools\ReplayClient;
use PolygonLab\PublicApisHealth\Tools\Sources;
use PolygonLab\PublicApisHealth\Tools\Updater;

/**
 * Dossier de travail temporaire d'un test : dossier data/ vide mais valide, copie du README, page LIST.md, fichier
 * de mots interdits d'essai, réglages d'essai (catégories minimales réduites à la taille du fichier d'essai), copie
 * modifiable de la collecte d'essai, effacement.
 */
final class Workspace
{
    /** Mot interdit des essais (fictif). */
    public const FORBIDDEN_WORD = 'forbiddenword';

    /**
     * Collecte d'essai : des lignes réelles de la liste public-apis (list.md) et les réponses de faux serveurs
     * (probes.json), inventées pour les tests.
     */
    public const LIST = __DIR__ . '/list';

    /** Nombre minimal de catégories des réglages d'essai : le fichier d'essai en compte huit. */
    public const MIN_CATEGORIES = 5;

    public readonly string $path;

    public readonly string $data;

    public readonly string $readme;

    public readonly string $list;

    public readonly string $forbiddenWords;

    /** Réglages d'essai : ceux du dépôt, avec un nombre minimal de catégories à la taille du fichier d'essai. */
    public readonly string $settings;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/public-apis-health-test-' . bin2hex(random_bytes(6));
        $this->data = $this->path . '/data';
        mkdir($this->data, 0700, true);
        foreach (Updater::emptyFiles(Sources::load()) as $name => $content) {
            file_put_contents($this->data . '/' . $name, $content);
        }
        $this->readme = $this->path . '/README.md';
        copy(__DIR__ . '/../../README.md', $this->readme);
        $this->list = $this->path . '/' . ListPage::FILE;
        file_put_contents($this->list, ListPage::render(null, []));
        $this->forbiddenWords = $this->path . '/forbidden-words.json';
        file_put_contents($this->forbiddenWords, (string) json_encode(['mots' => [['mot' => self::FORBIDDEN_WORD, 'tolerees' => ['forbiddenword-allowed']]]]));
        $this->settings = $this->settingsFile(['min_categories' => self::MIN_CATEGORIES]);
    }

    /**
     * Copie de la collecte d'essai dans un dossier du test, pour la modifier.
     */
    public function replay(string $name = 'replay'): string
    {
        $directory = $this->path . '/' . $name;
        mkdir($directory, 0700, true);
        foreach ([ReplayClient::FILE, ProbeReplay::FILE] as $file) {
            copy(self::LIST . '/' . $file, $directory . '/' . $file);
        }

        return $directory;
    }

    /**
     * Texte du fichier Markdown d'essai.
     */
    public static function markdown(): string
    {
        return (string) file_get_contents(self::LIST . '/' . ReplayClient::FILE);
    }

    /**
     * Modifie un fichier JSON du dossier de travail : la fonction reçoit son contenu décodé et rend le nouveau.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    public function edit(string $file, \Closure $change): void
    {
        file_put_contents($file, JsonFile::encode($change(JsonFile::read($file, basename($file)))));
    }

    /**
     * Fichier de réglages : ceux du dépôt, avec les valeurs données à la place des siennes.
     *
     * @param array<string, int> $settings réglages de premier niveau (« min_categories »…)
     * @param array<string, int> $test réglages du test des adresses (« max_duration_s »…)
     */
    public function settingsFile(array $settings = [], array $test = []): string
    {
        $data = JsonFile::read(Sources::FILE, 'tools/sources.json');
        $data = array_merge($data, $settings);
        $data['test'] = array_merge(\is_array($data['test']) ? $data['test'] : [], $test);
        $path = $this->path . '/sources-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, JsonFile::encode($data));

        return $path;
    }

    /**
     * Réglages du dépôt, avec les valeurs données à la place des siennes.
     *
     * @param array<string, int> $settings
     * @param array<string, int> $test
     */
    public function sources(array $settings = [], array $test = []): Sources
    {
        return Sources::load($this->settingsFile($settings, $test));
    }

    public function read(string $name): string
    {
        return (string) file_get_contents($this->data . '/' . $name);
    }

    /**
     * Empreintes des fichiers de data/, du README et de LIST.md : état du dépôt d'essai.
     *
     * @return array<string, string>
     */
    public function state(): array
    {
        $state = [];
        foreach ([...(glob($this->data . '/*') ?: []), $this->readme, $this->list] as $file) {
            $state[basename($file)] = hash('sha256', (string) file_get_contents($file));
        }
        ksort($state, SORT_STRING);

        return $state;
    }

    public function remove(): void
    {
        self::delete($this->path);
    }

    private static function delete(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
