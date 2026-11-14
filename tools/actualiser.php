<?php

/**
 * Actualise le dépôt à partir de la liste public-apis : lit la liste, teste chaque lien, réécrit les données.
 *
 * Usage :
 *   php tools/actualiser.php                         lit la liste, teste les liens, réécrit data/, LIST.md et le README
 *   php tools/actualiser.php --replay <dossier>      rejoue une collecte enregistrée (liste et tests), sans réseau
 *   php tools/actualiser.php --help                  toutes les options
 *
 * Codes de sortie : 0 succès (ou liste inchangée), 1 source refusée (rien n'est écrit), 2 usage.
 * Aucune bibliothèque extérieure : extensions PHP curl, json et mbstring seulement ; vendor/ n'est pas nécessaire.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$arguments = array_values(array_filter(array_slice($argv, 1), 'is_string'));

// Chargeur automatique facultatif : celui désigné par --loader, sinon celui de Composer s'il est installé.
require_once __DIR__ . '/src/Command.php';
$loader = PolygonLab\PublicApisHealth\Tools\Command::loader($arguments);
if ($loader !== null && !is_file($loader)) {
    fwrite(STDERR, "Autoloader not found: {$loader}\n");
    exit(2);
}
$loader ??= __DIR__ . '/../vendor/autoload.php';
if (is_file($loader)) {
    require_once $loader;
}

// Classes de l'outil, chargées depuis tools/src/ dans tous les cas.
spl_autoload_register(static function (string $class): void {
    $prefix = 'PolygonLab\\PublicApisHealth\\Tools\\';
    if (str_starts_with($class, $prefix) && preg_match('/\A[A-Za-z0-9]+\z/', substr($class, strlen($prefix))) === 1) {
        $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

exit(PolygonLab\PublicApisHealth\Tools\Command::run($arguments, STDOUT, STDERR));
