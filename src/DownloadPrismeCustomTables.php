<?php declare(strict_types=1);
/**
 * This file is part of Migration Prisme 2015.
 *
 * Copyright (C) 2018-2018 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */
namespace MigrationPrisme2015;

use Docalist\Repository\SettingsRepository;
use Docalist\Data\Settings\Settings;
use Docalist\Data\Settings\DatabaseSettings;
use Docalist\Data\Database;
use Docalist\Data\Grid;
use Docalist\Data\Settings\TypeSettings;
use Docalist\Table\MasterTable;
use Docalist\Table\TableInfo;
use Docalist\Table\TableManager;
use Docalist\Tools\BaseTool;
use Docalist\Tools\Category\MigrationToolTrait;

/**
 * Prisme2015 : importe les tables personnalisées du site.
 *
 * Télécharge les tables d'autorité personnalisées du site www.documentation-sociale.org et les installe
 * localement.
 *
 * @author Daniel Ménard <daniel.menard@laposte.net>
 */
class DownloadPrismeCustomTables extends BaseTool
{
    use MigrationToolTrait;

    /**
     * URL du site où on récupère les tables, SANS slash à la fin.
     *
     * @var string
     */
    const URL = 'http://www.documentation-sociale.org';

    /**
     * URL complète de la table master.
     *
     * @var string
     */
    const MASTER = self::URL . '/uploads/docalist-data/tables/master.txt';

    /**
     * Récupère la liste des tables, demande confirmation à l'utilisateur et lance le téléchargement.
     */
    public function run(array $args = []): void
    {
        // Charge la liste des tables personnalisées qui existent sur le site
        $tables = $this->getCustomTables();

        // Demande confirmation à l'utilisateur
        if (! $this->confirm($args, $tables)) {
            return;
        }

        // Télécharge et installe les tables
        $this->downloadTables($tables);

        echo 'done';
    }

    /**
     * Récupère la liste des tables personnalisées.
     *
     * @return TableInfo[];
     */
    private function getCustomTables(): array
    {
        echo '<h2>Initialisation</h2>';
        echo '<ul class="ul-square">';

        echo '<li>Téléchargement de la liste des tables du site <code>', self::URL, '</code>...</li>';
        $master = file_get_contents(self::MASTER);

        echo '<li>Enregistrement et compilation de la table temporaire master-prisme-org.txt...</li>';
        $path = docalist('tables-dir') . DIRECTORY_SEPARATOR . 'master-prisme-org.txt';
        file_put_contents($path, $master);

        echo '<li>Obtention de la liste des tables personnalisées...</li>';
        $master = new MasterTable($path);

        $tables = $master->tables(null, null, false);
        echo '<li>Il y a ', count($tables), ' tables personnalisées</li>';

        echo '<li>Suppression de la table master temporaire...</li>';
        unset($master);
        unlink($path);
        docalist('file-cache')->clear($path);

        echo '</ul>';

        return $tables;
    }

    /**
     * Demande confirmation à l'utilisateur si ce n'est pas déjà fait.
     *
     * @param array $args
     * @param TableInfo[] $tables
     *
     * @return bool
     */
    private function confirm(array $args, array $tables): bool
    {
        $confirm = $args['confirm'] ?? false;
        if ($confirm) {
            return true;
        }

        echo '<h2>Liste des tables personnalisées du site ', self::URL, '</h2>';

        echo '<p>Les tables suivantes vont être téléchargées et importées :</p>';
        echo '<ul class="ul-square">';

        foreach ($tables as $table) {
            $path = docalist('root-dir') . $table->path->getPhpValue();
            $exists = file_exists($path);
            printf(
                '<li><b>%s</b> (%s) : <span style="color:%s">%s</span></li>',
                $table->label->getPhpValue(),
                $table->name->getPhpValue(),
                $exists ? 'red' : 'green',
                $exists ? '<b>la table locale sera écrasée</b>': "nouvelle table"
            );
        }
        echo '</ul>';
        printf(
            '<p>
                <a href="%s" class="button button-primary">
                    Lancer le téléchargement et écraser les tables qui existent déjà
                </a>
            </p>',
            esc_attr(add_query_arg('confirm', 1))
        );

        return false;
    }

    /**
     * Télécharge et installe les tables en local.
     *
     * @param TableInfo[] $tables
     */
    private function downloadTables(array $tables): void
    {
        $tableManager = docalist('table-manager'); /* @var TableManager $tableManager */

        foreach ($tables as $table) {
            $path = $table->path->getPhpValue();
            $name = $table->name->getPhpValue();
            $label = $table->label->getPhpValue();
            printf(
                "<h2>Récupération de la table <b>%s</b> (%s)...</h2>",
                $label,
                $name
            );
            echo '<ul class="ul-square">';
            if ($tableManager->has($name)) {
                echo "<li>La table <code>$name</code> est déjà référencée dans le Table Manager, suppression...</li>";
                $tableManager->delete($name);
            } else {
                echo "<li>La table <code>$name</code> n'est pas référencée dans le Table Manager...</li>";
            }

            $url = self::URL . $path;
            echo "<li>Téléchargement du fichier <code>$url</code>...</li>";
            $data = file_get_contents($url);

            $path = docalist('tables-dir') . DIRECTORY_SEPARATOR . basename($path);
            if (file_exists($path)) {
                echo "<li>Ecrasement du fichier existant <code>$path</code>...</li>";
            } else {
                echo "<li>Création du nouveau fichier <code>$path</code>...</li>";
            }
            file_put_contents($path, $data);

            echo "<li>Déclaration de la table dans le Table Manager...</li>";

            $table->path = $path;
            $tableManager->register($table);

            echo '<li>ok</li>';
            echo '</ul>';
        }
    }
}
