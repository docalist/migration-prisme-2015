<?php declare(strict_types=1);
/**
 * This file is part of Migration Prisme 2015.
 *
 * Copyright (C) 2018-2018 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 *
 * Plugin Name: Migration Prisme 2015
 * Plugin URI:  https://docalist.org/
 * Description: Docalist : Migration Prisme 2015.
 * Version:     1.0.0
 * Author:      Daniel Ménard
 * Author URI:  https://docalist.org/
 *
 * @author Daniel Ménard <daniel.menard@laposte.net>
 */
namespace MigrationPrisme2015;

/**
 * Version du plugin.
 */
define('MIGRATION_PRISME_2015_VERSION', '1.0.0'); // Garder synchro avec la version indiquée dans l'entête

/**
 * Path absolu du répertoire dans lequel le plugin est installé.
 *
 * Par défaut, on utilise la constante magique __DIR__ qui retourne le path réel du répertoire et résoud les liens
 * symboliques.
 *
 * Si le répertoire du plugin est un lien symbolique, la constante doit être définie manuellement dans le fichier
 * wp_config.php et pointer sur le lien symbolique et non sur le répertoire réel.
 */
!defined('MIGRATION_PRISME_2015_DIR') && define('MIGRATION_PRISME_2015_DIR', __DIR__);

/**
 * Path absolu du fichier principal du plugin.
 */
define('MIGRATION_PRISME_2015', MIGRATION_PRISME_2015_DIR . DIRECTORY_SEPARATOR . basename(__FILE__));

/**
 * Url de base du plugin.
 */
define('MIGRATION_PRISME_2015_URL', plugins_url('', MIGRATION_PRISME_2015));

/**
 * Initialise le plugin.
 */
add_action('plugins_loaded', function () {
    // Auto désactivation si les plugins dont on a besoin ne sont pas activés
    $dependencies = ['DOCALIST_CORE', 'DOCALIST_DATA'];
    foreach ($dependencies as $dependency) {
        if (! defined($dependency)) {
            return add_action('admin_notices', function () use ($dependency) {
                deactivate_plugins(MIGRATION_PRISME_2015);
                unset($_GET['activate']); // empêche wp d'afficher "extension activée"
                printf(
                    '<div class="%s"><p><b>%s</b> has been deactivated because it requires <b>%s</b>.</p></div>',
                    'notice notice-error is-dismissible',
                    'Migration Prisme 2015',
                    ucwords(strtolower(strtr($dependency, '_', ' ')))
                );
            });
        }
    }

    // Ajoute notre namespace à l'autoloader Docalist
    docalist('autoloader')->add(__NAMESPACE__, __DIR__ . '/src');

    // Pas de classe Plugin, on définit simplement les outils disponibles
    add_filter('docalist-tools', function (array $tools): array {
        return $tools + [
            DeleteOldDclPosts::class,
            MigrationPrisme2015::class,
            ImportDocalistBiblioSettings::class,
            DownloadPrismeCustomTables::class
        ];
    });
});
