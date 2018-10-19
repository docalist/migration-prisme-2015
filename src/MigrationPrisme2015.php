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
use Docalist\Data\Database;
use Docalist\Data\Record;
use Docalist\Data\Settings\DatabaseSettings;
use Docalist\Data\Settings\TypeSettings;
use Docalist\Data\Grid;
use Docalist\Biblio\Entity\ReferenceEntity;
use Generator;
use wpdb;

/**
 * Prisme2015 : migration des bases Prisme créées en 2015 vers la version actuelle de Docalist.
 *
 * Cet outil permet de migrer les bases Prisme de 2015 vers la version actuelle de Docalist. Il recherche dans
 * la table wp_posts les enregistrements qui ont un post_type de la forme "dclref*" (le préfixe des anciennes
 * bases Docalist) et propose de convertir chacune des bases trouvées.
 * La conversion des notices consiste à changer le post_type pour utiliser le nouveau préfixe ('db*') puis à
 * charger et ré-enregistrer chaque notices pour qu'elle soit mise à jour : les champs qui n'existent plus sont
 * supprimés (par exemple 'imported'), les champs qui ont changé de nom sont renommés (par exemple 'organization'
 * qui devient 'corporation'), etc.
 *
 * @author Daniel Ménard <daniel.menard@laposte.net>
 */
class MigrationPrisme2015 extends BaseTool
{
    use MigrationToolTrait;

    /**
     * Permet à l'utilisateur de choisir la base à convertir puis lance la conversion.
     */
    public function run(array $args = []): void
    {
        // Choisit le type à convertir
        $type = $args['post_type'] ?? '';
        if (empty($type)) {
            $this->chooseDatabase();

            return;
        }

        // Convertit le type sélectionné
        $this->convertDatabase($type);
    }

    /**
     * Choisit la base Docalist à convertir.
     *
     * Recherche les anciens types de posts (dclref*) dans la table wp_posts et affiche une liste permettant à
     * l'utilisateur de choisir la base à convertir.
     */
    private function chooseDatabase()
    {
        echo '<p>Recherche des posts de type <code>dclref*</code> dans la table <code>wp_posts</code>...</p>';

        $db = $this->wpdb();
        $sql =
            "SELECT DISTINCT post_type, count(*) AS `count`
            FROM `{$db->posts}`
            WHERE post_type LIKE 'dclref%'
            GROUP BY post_type";

        $results = $db->get_results($sql);

        if (empty($results)) {
            echo '<p><b>Aucun post <code>dclref*</code> trouvé, il n\'y a rien à convertir, terminé.</b></p>';

            return;
        }

        echo '<p><b>La table wp_posts contient des anciennes bases Docalist. Choisissez la base à convertir :</b></p>';

        echo '<table class="widefat">';
        echo '<thead><tr><th>Type de post</th><th>Nombre de notices</th></tr></thead>';
        foreach ($results as $result) {
            printf(
                '<tr><td><a href="%s"><b>Base "%s"</b> (post_type <code>%s</code>)</a></td><td>%s</td></tr>',
                add_query_arg('post_type', $result->post_type),
                substr($result->post_type, 6),
                $result->post_type,
                $result->count
            );
        }
        echo '</table>';
    }

    /**
     * Convertit une ancienne base Docalist.
     *
     * @param string $oldType Le post_type de l'ancienne base docalist (dclref*)
     */
    private function convertDatabase(string $oldType): void
    {
        // Vérifie que le type à convertir commence par "dclref"
        if (substr($oldType, 0, 6) !== 'dclref') {
            echo "<p>Erreur : le type à convertir '$oldType' ne commence pas par 'dclref'</p>";
            return;
        }

        // Vérifie qu'on a des posts de ce type
        $count = $this->getPostCount($oldType);
        if ($count === 0) {
            echo "<p>Erreur : il n'y a aucun post de type '$oldType' dans la table wp_posts</p>";
            return;
        }

        // Détermine le nouveau type de post
        $newType = 'db' . substr($oldType, 6);

        // Vérifie qu'il n'y a pas déjà des posts de ce type
        $check = $this->getPostCount($newType);
        if ($check !== 0) {
            echo "<p>Erreur : il y a déjà des posts de type '$newType' dans la table wp_posts</p>";
            return;
        }

        // Ok, on peut convertir
        $base = substr($oldType, 6);
        echo "<p>Migration de la base '$base', $count notices à convertir.</p>";

        // Crée une base qui contient la version à jour de tous les types de notices possibles
        $database = $this->createDatabaseWithAllTypes($base);

        // Convertit tous les posts
        $count = 0;
        foreach ($this->getPosts($oldType) as $id => $post) {
            // Convertit le post
            $newPost = $this->convertPost($post, $database);

            // Met à jour le post
            $this->updatePost($id, $newPost);

            // Affichage progression
            ++$count;
            if (0 === $count % 100) {
                echo "<li>$count notices converties...</li>";
                //ob_end_flush();
                //ob_flush();
                flush();
            }
        }

        echo "<p>Terminé, $count notices ont été converties.</p>";
    }

    /**
     * Convertit un post.
     *
     * @param array     $post       Les données de l'ancien post.
     * @param Database  $database   La base de destination.
     *
     * @return array Les données du post convertit.
     */
    private function convertPost(array $post, Database $database): array
    {
        // On convertit le post en Record docalist. Comme les entités et les types Docalist gèrent la compatibilité
        // ascendante, cela a pour effet de mettre les données à niveau.
        $record = $database->fromPost($post);

        // Pour les anciennes références biblio, on n'avait pas de champ title, on l'initialise depuis post_title
        if ($record instanceof ReferenceEntity && empty($record->title->getPhpValue())) {
            $record->title = $record->posttitle->getPhpValue();
        }

        // On émule database::save() car on ne veut pas faire l'indexation ES, les transitions, etc.
        $record->beforeSave($database); // peut changer post_title

        // Reconvertit le Record en post pour obtenir les données à enregistrer
        $newPost = $database->encode($record->getPhpValue());

        // On ne veut mettre à jour que les champs modifiés, supprime tous ce qui n'a pas changé
        foreach ($newPost as $field => $value) {
            if ($value === $post[$field]) {
                unset($newPost[$field]);
            }
        }

        // Transfère le post dans la base fournie en paramètre
        $newPost ['post_type'] = $database->postType();

        // Retourne le post modifié
        return $newPost;
    }

    /**
     * Met à jour la table wp_posts avec le post passé en paramètre.
     *
     * @param int   $id     ID du post.
     * @param array $post   Données à mettre à jour.
     */
    private function updatePost(int $id, array $post): void
    {
        $db = $this->wpdb();
        $nb = $db->update($db->posts, $post, ['ID' => $id]);

        ($nb !== 1) && printf(
            '<p>Erreur, update a retourné %s pour le post <code>%d</code> : <pre>%s</pre></p>',
            var_export($nb, true),
            $id,
            $db->last_query
        );
    }

    /**
     * Retourne la base WordPress.
     *
     * @return wpdb
     */
    private function wpdb(): wpdb
    {
        return docalist('wordpress-database');
    }

    /**
     * Retourne le nombre de posts ayant le post_type indiqué.
     *
     * @param string $postType post_type recherché.
     *
     * @return int
     */
    private function getPostCount(string $postType): int
    {
        $db = $this->wpdb();
        $sql = "SELECT count(*) AS `count` FROM `{$db->posts}` WHERE post_type = '$postType'";

        return (int) $db->get_var($sql);
    }

    /**
     * Retourne un générateur qui permet de parcourir tous les posts qui ont un post type donné.
     *
     * @param string    $postType   Type de post recherché
     * @param number    $batchSize  Optionnel, taille des batchs.
     *
     * @return Generator
     */
    private function getPosts(string $postType, $batchSize = 1000): Generator
    {
        $db = $this->wpdb();

        // Important : comme on change le post_type, on ne pagine pas avec offset

        for (;;) {
            // Prépare la requête
            $sql = sprintf(
                'SELECT * FROM `%s` WHERE `post_type`="%s" ORDER BY `ID` ASC LIMIT %d',
                $db->posts,
                $postType,
                $batchSize
            );
            // Charge le lot suivant
            $posts = $db->get_results($sql);

            // Génère tous les posts
            foreach ($posts as $post) {
                yield (int) $post->ID => (array) $post;
            }

            // Si le lot est incomplet, on a terminé
            if (count($posts) < $batchSize) {
                break;
            }
        }
    }

    /**
     * Crée une base Docalist virtuelle qui contient tous les types de notices pssibles.
     *
     * @param string $name  Nom de la base (pour déterminer le bon post_type).
     *
     * @return Database
     */
    private function createDatabaseWithAllTypes(string $name): Database
    {
        $settings = new DatabaseSettings();
        $settings->name = $name;

        $types = Database::getAvailableTypes();
        foreach ($types as $type => $class) { /* @var Record $class */
            $base = $class::getBaseGrid();
            $base = apply_filters('docalist_data_get_base_grid', $base, $name, 0);
            $base    = new Grid($base);

            $edit = $class::getEditGrid();
            $edit = apply_filters('docalist_data_get_edit_grid', $edit, $name, 0);
            $edit    = new Grid($edit);

            $content = $class::getContentGrid();
            $content = apply_filters('docalist_data_get_content_grid', $content, $name, 0);
            $content = new Grid($content);

            $excerpt = $class::getExcerptGrid();
            $excerpt = apply_filters('docalist_data_get_excerpt_grid', $excerpt, $name, 0);
            $excerpt = new Grid($excerpt);

            $edit->initSubfields($base);
            $content->initSubfields($base);
            $excerpt->initSubfields($base);

            // Crée le type
            $settings->types[] = new TypeSettings([
                'name' => $type,
                'label' => $base->label(),
                'description' => $base->description(),
                'grids' => [ $base, $edit, $content, $excerpt]
            ]);
        }

        return new Database($settings);
    }
}
