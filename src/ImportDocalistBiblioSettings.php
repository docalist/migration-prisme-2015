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

use Docalist\Tools\BaseTool;
use Docalist\Tools\Category\MigrationToolTrait;
use Docalist\Repository\SettingsRepository;
use Docalist\Data\Settings\Settings;
use Docalist\Data\Settings\DatabaseSettings;
use Docalist\Data\Settings\TypeSettings;
use Docalist\Data\Database;
use Docalist\Data\Grid;

/**
 * Prisme2015 : import des anciens paramètres docalist-biblio dans docalist-data.
 *
 * Cet outil permet de récupérer partiellement les anciens paramètres de docalist-biblio pour créer la version
 * initiale des paramètres de docalist-data.
 *
 * @author Daniel Ménard <daniel.menard@laposte.net>
 */
class ImportDocalistBiblioSettings extends BaseTool
{
    use MigrationToolTrait;

    /**
     * Charge les anciens settings, demande confirmation et lance la conversion.
     */
    public function run(array $args = []): void
    {
        // Charge les anciens paramètres
        $settings = $this->loadOldSettings();

        // Si on n'en a pas, terminé
        if (empty($settings)) {
            echo '<p>Aucun paramètre docalist-biblio trouvé, rien à importer</p>';

            return;
        }

        // Demande confirmation à l'utilisateur
        if (! $this->confirm($args)) {
            return;
        }

        // Convertit les settings
        $this->convertSettings($settings);
    }

    /**
     * Demande confirmation à l'utilisateur si ce n'est pas déjà fait.
     *
     * @param array $args
     *
     * @return bool
     */
    private function confirm(array $args): bool
    {
        $confirm = $args['confirm'] ?? false;
        if ($confirm) {
            return true;
        }

        printf(
            '<p>
                Les settings docalist-biblio vont être importés et
                <b>les paramètres existants de docalist-data seront écrasés</b>.
            </p>
            <p>
                <a href="%s" class="button button-primary">Importer les paramètres</a>
            </p>',
            esc_attr(add_query_arg('confirm', 1))
        );

        return false;
    }

    /**
     * Charge les anciens paramètres docalist-biblio.
     *
     * @return array
     */
    private function loadOldSettings(): array
    {
        $option = 'docalist-biblio-settings';
        $options = docalist('settings-repository'); /* @var SettingsRepository $options */

        return $options->has($option) ? $options->loadRaw($option) : [];
    }

    /**
     * Convertit les paramètres docalist-biblio en paramètres docalist-data.
     *
     * @param array $oldSettings Les anciens paramètres docalist-biblio.
     */
    private function convertSettings(array $oldSettings): void
    {
        echo '<p>Conversion des anciens settings</p>';
//        echo '<pre>Anciens settings', var_export($oldSettings, true), '</pre>';

        $settings = new Settings(docalist('settings-repository'));
        $settings->delete();
        foreach ($oldSettings['databases'] as $database) {
            $settings->databases[] = $this->convertDatabase($database);
        }

//        echo '<pre>Nouveaux settings', var_export($settings->getPhpValue(), true), '</pre>';

        $settings->save();

        echo '<p>Terminé, les nouveaux settings docalist-data ont été enregistrés</p>';
    }

    private function convertDatabase(array $settings): DatabaseSettings
    {
        $name = $settings['name'];
        echo "<h2>Conversion des paramètres de la base <code>$name</code></h2>";

        $db = [];
        $db['name'] = $name;
        $db['homepage'] = $settings['homepage'];
        $db['homemode'] = $settings['homemode'];
        $db['searchpage'] = $settings['searchpage'];
        $db['label'] = $settings['label'];
        $db['description'] = $settings['description'];
        $db['stemming'] = $settings['stemming'];
        $db['types'] = $this->convertTypes($name, $settings['types']);
        $db['creation'] = $settings['creation'];
        $db['lastupdate'] = date_i18n('Y/m/d H:i:s');
        $db['icon'] = $settings['icon'];

        $notes = $settings['notes'] ?? '';
        $notes .= "\n";
        $user = wp_get_current_user()->display_name;
        $notes .= date_i18n('d/m/Y') . ' : Import des anciens paramètres Docalist (' . $user . ')';
        $notes = trim($notes);
        $db['notes'] = $notes;

        $db['thumbnail'] = $settings['thumbnail'];
        $db['revisions'] = $settings['revisions'];
        $db['comments'] = $settings['comments'];

        return new DatabaseSettings($db);
    }

    /**
     * Convertit une liste de types.
     *
     * @param string    $database   Base docalist de destination.
     * @param array     $types      Types à convertir.
     *
     * @return TypeSettings[] Types convertis.
     */
    private function convertTypes(string $database, array $types): array
    {
        $result = [];
        foreach ($types as $type) {
            $result[] = $this->convertType($database, $type);
        }

        return $result;
    }

    /**
     * Convertit le type passé en paramètre.
     *
     * @param string    $database   Base docalist de destination.
     * @param array     $type       Anciens paramètres du type.
     *
     * @return TypeSettings Nouveau paramètres du type.
     */
    private function convertType(string $database, array $type): TypeSettings
    {
        $name = $type['name'];
        echo "<h3>Type <code>$name</code></h3>";

        $class = Database::getClassForType($name);

        $base = $class::getBaseGrid();
        $base = apply_filters('docalist_data_get_base_grid', $base, $database, 0);

//         echo '<h3>Grille de base</h3>';
//         echo '<pre>';
//         var_export($base);
//         echo '</pre>';

//         echo '<h3>Paramètres personnalisés</h3>';
//         echo '<pre>';
//         var_export($type['grids'][0]);
//         echo '</pre>';

        $base = $this->customizeGrid($base, $type['grids'][0]);



//         echo '<h3>Grille finale</h3>';
//         echo '<pre>';
//         var_export($base);
//         echo '</pre>';

//         die();
        $base    = new Grid($base);

        $edit = $class::getEditGrid();
        $edit = apply_filters('docalist_data_get_edit_grid', $edit, $database, 0);
        $edit    = new Grid($edit);

        $content = $class::getContentGrid();
        $content = apply_filters('docalist_data_get_content_grid', $content, $database, 0);
        $content = new Grid($content);

        $excerpt = $class::getExcerptGrid();
        $excerpt = apply_filters('docalist_data_get_excerpt_grid', $excerpt, $database, 0);
        $excerpt = new Grid($excerpt);

        $edit->initSubfields($base);
        $content->initSubfields($base);
        $excerpt->initSubfields($base);

        // Crée le type
        return new TypeSettings([
            'name' => $name,
            'label' => $base->label(),
            'description' => $base->description(),
            'grids' => [ $base, $edit, $content, $excerpt]
        ]);
    }

    /**
     * Personnalise une grille en récupérant certains des paramèters qui figurent dans les settings.
     *
     * @param array $grid       La grille à personnaliser.
     * @param array $oldGrid    Les paramètres de personnalisation.
     *
     * @return array La grille modifiée.
     */
    private function customizeGrid(array $grid, array $oldGrid): array
    {
        // Emplacement des attributs "table" / "table2" dans les anciennes et nouvelles grilles
        $tables = [
            // source (dans $old)   =>  destination (dans $grid)
            'genre.table'           => 'genre.table',
            'media.table'           => 'media.table',
            'othertitle.table'      => 'othertitle.fields.type.table',
            'translation.table'     => 'translation.fields.type.table',
            'author.table'          => 'author.fields.role.table',
            'organisation.table'    => 'corporation.fields.country.table',
            'organisation.table2'   => 'corporation.fields.role.table',
            'date.table'            => 'date.fields.type.table',
            'number.table'          => 'number.fields.type.table',
            'language.table'        => 'language.table',
            'extent.table'          => 'extent.fields.type.table',
            'format.table'          => 'format.table',
            'topic.table'           => 'topic.fields.type.table',
            'content.table'         => 'content.fields.type.table',
            'link.table'            => 'link.fields.type.table',
            'relation.table'        => 'relation.fields.type.table',

        ];

        // Récupère les tables d'autorité indiquées
        foreach ($tables as $src => $dst) {
            $field = strstr($dst, '.', true);
            if (! isset($grid['fields'][$field])) {
                echo "INF - Le champ $field n'existe pas pour ce type<br />";
                continue;
            }
            $oldField = strstr($src, '.', true);
            if (! isset($oldGrid['fields'][$oldField])) {
                echo "INF - Le champ $oldField n'existait pas à l'époque pour ce type<br />";
                continue;
            }
            $table = $this->arrayGet($oldGrid['fields'], $src);
            $this->arraySet($grid['fields'], $dst, $table);
            echo "TBL - $dst=$table <br />";
        }

        // Recopie les champs qui ont changé de nom pour simplifier la récupération (dans l'ancienne grille)
        foreach (['organisation' => 'corporation', 'event' => 'context', 'owner' => 'source'] as $old => $new) {
            if (isset($oldGrid['fields'][$old])) {
                $oldGrid['fields'][$new] = $oldGrid['fields'][$old];
            }
        }

        // Capacités
        foreach ($grid['fields'] as $name => & $field) {
            if (! isset($oldGrid['fields'][$name])) {
                echo "ERR - Le champ $name n'existe pas dans l'ancienne grille<br />";
                continue;
            }
            $old = $oldGrid['fields'][$name];
            if (isset($old['capability'])) {
                $field['capability'] = $old['capability'];
                echo "CAP - $name.capability=", $old['capability'], "<br />";
                continue;
            }
        }

        return $grid;
    }

    private function arrayGet($array, $key)
    {
        foreach (explode('.', $key) as $segment) {
            if (!array_key_exists($segment, $array)) {
                throw new \InvalidArgumentException("Clé $key non trouvé");
            }
            $array = $array[$segment];
        }

        return $array;
    }

    private function arraySet(&$array, $key, $value)
    {
        foreach (explode('.', $key) as $segment) {
            if (!array_key_exists($segment, $array)) {
                throw new \InvalidArgumentException("Clé $key non trouvé");
            }
            $array = &$array[$segment];
        }

        $array = $value;
    }
}
