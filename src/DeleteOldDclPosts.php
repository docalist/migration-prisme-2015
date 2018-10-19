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
use Docalist\Html;
use wpdb;

/**
 * Suppression des anciens posts "dclresource" et "dclrecord".
 *
 * Dans les toutes premières versions du site Prisme (2011/2012), des posts de type "dclresource" et "dclrecord"
 * avaient été créés pour faire des essais.
 *
 * Cet outil teste s'il y a encore des posts de ce type qui traînent dans la table wp_posts
 * et propose de les supprimer.
 *
 * @author Daniel Ménard <daniel.menard@laposte.net>
 */
class DeleteOldDclPosts extends BaseTool
{
    /**
     * Recherche les anciens posts, demande confirmation et les supprime.
     */
    public function run(array $args = []): void
    {
        echo
        '<p>
            Recherche des posts de type <code>dclresource</code> ou <code>dclrecord</code>
            dans la table <code>wp_posts</code>...
        </p>';

        $wpdb = docalist('wordpress-database'); /* @var wpdb $wpdb */

        $where = 'post_type LIKE "dcl%" AND NOT post_type LIKE "dclref%"';
        $sql =
            "SELECT DISTINCT post_type, count(*) AS `count`
            FROM `{$wpdb->posts}`
            WHERE $where
            GROUP BY post_type";

        $results = $wpdb->get_results($sql);

        if (empty($results)) {
            echo "<p><b>Aucun post trouvé, il n'y a rien à supprimer.</b></p>";

            return;
        }

        $confirm = $args['confirm'] ?? false;
        if (! $confirm) {
            $this->confirm($results);

            return;
        }

        $sql = "DELETE FROM `{$wpdb->posts}` WHERE $where";
        $count = $wpdb->query($sql);

        echo "<p><b>$count enregistrement(s) supprimé(s).</b></p>";
    }

    /**
     * Affiche les posts trouvés et demande confirmation à l'utilisateur.
     *
     * @param array $results
     */
    private function confirm(array $results): void
    {

        echo '<p>La table <code>wp_posts</code> contient des anciens enregistrements Docalist :</p>';

        echo '<table class="widefat">';
        echo '<thead><tr><th>Type de post</th><th>Nombre de posts</th></tr></thead>';
        $total = 0;
        foreach ($results as $result) {
            printf(
                '<tr><td>%s</td><td>%s</td></tr>',
                $result->post_type,
                $result->count
            );
            $total += (int) $result->count;
        }
        echo '</table>';

        printf(
            '<p><a href="%s" class="button button-primary">Supprimer les %d posts trouvés</a></p>',
            esc_attr(add_query_arg('confirm', 1)),
            $total
        );
    }
}
