<?php

/**
 * @file
 * Contains \Drupal\nodeorder\NodeOrderManager.
 */

namespace Drupal\nodeorder;
use Drupal\Core\Cache\Cache;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * The NodeOrderManager contains helper functions for the nodeorder module.
 */
class NodeOrderManager {

  /**
   * Push new or newly orderable node to the top of ordered list.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to add to the top of the list.
   * @param int $tid
   *   The term ID to order the node in.
   */
  public static function addToList(NodeInterface $node, $tid) {
    // Append new orderable node.
    $weights = static::getTermMinMax($tid); // Get the cached weights.
    db_update('taxonomy_index')
      ->fields(array('weight' => $weights['min'] - 1))
      ->condition('nid', $node->id())
      ->condition('tid', $tid)
      ->execute();
    // If new node out of range, push top nodes down filling the order gap
    // this is when old list's min weight is top range
    // except when new orderable node increases range (new list is not even).
    $taxonomy_nids = db_select('taxonomy_index', 'ti')
      ->fields('ti', array('nid'))
      ->condition('ti.tid', $tid)
      ->orderBy('ti.weight')
      ->execute()
      ->fetchCol('nid');

    $new_node_out_of_range = (count($taxonomy_nids) % 2 == 0 && $weights['min'] == -ceil(count($taxonomy_nids) / 2));
    if ($new_node_out_of_range) {
      // Collect top nodes.
      // Note that while the node data is not yet updated in the database, the taxonomy is.
      $top_range_nids = array();
      $previous_weight = $weights['min'] - 2;
      foreach ($taxonomy_nids as $taxonomy_nid) {
        $taxonomy_node_weight = db_select('taxonomy_index', 'i')
          ->fields('i', array('weight'))
          ->condition('tid', $tid)
          ->condition('nid', $taxonomy_nid)
          ->execute()
          ->fetchField();

        if ($taxonomy_node_weight > $previous_weight + 1)  break;
        $previous_weight = $taxonomy_node_weight;
        $top_range_nids[] = $taxonomy_nid;
      }
      // Move top nodes down.
      $query = db_update('taxonomy_index');
      $query->expression('weight', 'weight + 1');
      $query->condition('nid', $top_range_nids, 'IN')
        ->condition('tid', $tid)
        ->execute();
    }
    // Make sure the weight cache is invalidated.
    static::getTermMinMax($tid, TRUE);
  }

  /**
   * Get the minimum and maximum weights available for ordering nodes on a term.
   *
   * @param int $tid
   *   The tid of the term from which to check values.
   * @param bool $reset
   *   (optional) Select from or reset the cache.
   *
   * @return array
   *   An associative array with elements 'min' and 'max'.
   */
  public static function getTermMinMax($tid, $reset = FALSE) {
    static $min_weights = [];
    static $max_weights = [];

    if ($reset) {
      $min_weights = [];
      $max_weights = [];
    }

    if (!isset($min_weights[$tid]) || !isset($max_weights[$tid])) {
      $query = db_select('taxonomy_index', 'i')
        ->fields('i', ['tid'])
        ->condition('tid', $tid)
        ->groupBy('tid');
      $query->addExpression('MAX(weight)', 'max_weight');
      $query->addExpression('MIN(weight)', 'min_weight');
      $record = $query->execute()->fetch();

      $min_weights[$tid] = $record->min_weight;
      $max_weights[$tid] = $record->max_weight;
    }

    $weights['min'] = $min_weights[$tid];
    $weights['max'] = $max_weights[$tid];

    return $weights;
  }

  /**
   * Determines if a given vocabulary is orderable.
   *
   * @param string $vid
   *   The vocabulary vid.
   *
   * @return bool
   *   Returns TRUE if the given vocabulary is orderable.
   */
  public static function vocabularyIsOrderable($vid) {
    $vocabularies = \Drupal::config('nodeorder.settings')->get('vocabularies');
    return !empty($vocabularies[$vid]);
  }

  /**
   * Finds all nodes that match selected taxonomy conditions.
   *
   * NOTE: This is nearly a direct copy of taxonomy_select_nodes() -- see
   *       http://drupal.org/node/25801 if you find this sort of copy and
   *       paste upsetting...
   *
   *
   * @param $tids
   *   An array of term IDs to match.
   * @param $operator
   *   How to interpret multiple IDs in the array. Can be "or" or "and".
   * @param $depth
   *   How many levels deep to traverse the taxonomy tree. Can be a nonnegative
   *   integer or "all".
   * @param $pager
   *   Whether the nodes are to be used with a pager (the case on most Drupal
   *   pages) or not (in an XML feed, for example).
   * @param $order
   *   The order clause for the query that retrieve the nodes.
   * @param $count
   *   If $pager is TRUE, the number of nodes per page, or -1 to use the
   *   backward-compatible 'default_nodes_main' variable setting.  If $pager
   *   is FALSE, the total number of nodes to select; or -1 to use the
   *   backward-compatible 'feed_default_items' variable setting; or 0 to
   *   select all nodes.
   *
   * @return \Drupal\Core\Database\StatementInterface
   *   A resource identifier pointing to the query results.
   */
  public static function selectNodes($tids = [], $operator = 'or', $depth = 0, $pager = TRUE, $order = 'n.sticky DESC, n.created DESC', $count = -1) {
    if (count($tids) > 0) {
      // For each term ID, generate an array of descendant term IDs to the right depth.
      $descendant_tids = [];
      if ($depth === 'all') {
        $depth = NULL;
      }
      foreach ($tids as $index => $tid) {
        $term = \Drupal::entityManager()->getStorage('taxonomy_term')->load($tid);
        $tree = \Drupal::entityManager()->getStorage("taxonomy_term")->loadTree($term->getVocabularyId(), $tid, $depth);
        $descendant_tids[] = array_merge([$tid], array_map(function ($value) { return $value->id(); }, $tree));
      }

      if ($operator == 'or') {
        $args = call_user_func_array('array_merge', $descendant_tids);
        $placeholders = db_placeholders($args, 'int');
        $sql = 'SELECT DISTINCT(n.nid), nd.sticky, nd.title, nd.created, tn.weight FROM {node} n LEFT JOIN {node_field_data} nd INNER JOIN {taxonomy_index} tn ON n.vid = tn.vid WHERE tn.tid IN (' . $placeholders . ') AND n.status = 1 ORDER BY ' . $order;
        $sql_count = 'SELECT COUNT(DISTINCT(n.nid)) FROM {node} n INNER JOIN {taxonomy_index} tn ON n.vid = tn.vid WHERE tn.tid IN (' . $placeholders . ') AND n.status = 1';
      }
      else {
        $args = [];
        $query = db_select('node', 'n');
        $query->join('node_field_data', 'nd');
        $query->condition('nd.status', 1);
        foreach ($descendant_tids as $index => $tids) {
          $query->join('taxonomy_index', "tn$index", "n.nid = tn{$index}.nid");
          $query->condition("tn{$index}.tid", $tids, 'IN');
        }
        $query->fields('nd', ['nid', 'sticky', 'title', 'created']);
        // @todo: distinct?
        $query->fields('tn0', ['weight']);
        // @todo: ORDER BY ' . $order;
        //$sql_count = 'SELECT COUNT(DISTINCT(n.nid)) FROM {node} n ' . $joins . ' WHERE n.status = 1 ' . $wheres;
      }

      if ($pager) {
        if ($count == -1) {
          $count = \Drupal::config('nodeorder.settings')->get('default_nodes_main');
        }
        $result = pager_query($sql, $count, 0, $sql_count, $args);
      }
      else {
        if ($count == -1) {
          $count = \Drupal::config('nodeorder.settings')->get('feed_default_items');
        }

        if ($count == 0) {
          // TODO Please convert this statement to the D7 database API syntax.
          $result = $query->execute();
        }
        else {
          // TODO Please convert this statement to the D7 database API syntax.
          $result = db_query_range($sql, $args);
        }
      }
    }

    return $result;
  }

  /**
   * Determine if a given node can be ordered in any vocabularies.
   *
   * @param \Drupal\node\NodeInterface
   *   The node object.
   *
   * @return bool
   *   Returns TRUE if the node has terms in any orderable vocabulary.
   */
  public static function canBeOrdered(NodeInterface $node) {
    $cid = 'nodeorder:can_be_ordered:' . $node->getType();

    if (($cache = \Drupal::cache()->get($cid)) && !empty($cache->data)) {
      return $cache->data;
    }
    else {
      $can_be_ordered = FALSE;

      $nodeorder_vocabularies = [];
      foreach ($node->getFieldDefinitions() as $field) {
        if ($field->getType() != 'entity_reference' || $field->getSetting('target_type') != 'taxonomy_term') {
          continue;
        }

        foreach ($field->getSetting('handler_settings')['target_bundles'] as $vocabulary) {
          $nodeorder_vocabularies[] = $vocabulary;
        }
      }

      foreach ($nodeorder_vocabularies as $vid) {
        if (Vocabulary::load($vid)) {
          $can_be_ordered = TRUE;
        }
      }

      // Permanently cache the value for easy reuse.
      \Drupal::cache()->set($cid, $can_be_ordered, Cache::PERMANENT, ['nodeorder']);

      return $can_be_ordered;
    }
  }

  /**
   * Get a list of term IDs on a node that can be ordered.
   *
   * This method uses the `taxonomy_index` table to determine which terms on a
   * node are orderable.
   *
   * @see self::getOrderableTidsFromNode()
   *
   * @param \Drupal\node\NodeInterface
   *   The node to check for orderable term IDs.
   * @param bool
   *   Flag to reset cached data.
   *
   * @return int[]
   *   Returns an array of the node's tids that are in orderable vocabularies.
   */
  public static function getOrderableTids(NodeInterface $node, $reset = FALSE) {
    $cid = 'nodeorder:orderable_tids:' . $node->getType();

    if (!$reset && ($cache = \Drupal::cache()->get($cid)) && !empty($cache->data)) {
      $tids = $cache->data;
    }
    else {
      $vocabularies = [];
      foreach (\Drupal::config('nodeorder.settings')->get('vocabularies') as $vid => $orderable) {
        if ($orderable) {
          $vocabularies[] = $vid;
        }
      }
      if (!empty($vocabularies)) {
        $query = db_select('taxonomy_index', 'i');
        $query->join('taxonomy_term_data', 'd', 'd.tid = i.tid');
        $query->condition('i.nid', $node->id())
          ->condition('d.vid', $vocabularies, 'IN')
          ->fields('i', ['tid']);
        $tids = $query->execute()->fetchCol('tid');
      }
      else {
        $tids = [];
      }
      // Permanently cache the value for easy reuse.
      // @todo this needs to properly clear when node is edited.
      \Drupal::cache()->set($cid, $tids, Cache::PERMANENT, ['nodeorder']);
    }

    return $tids;
  }

  /**
   * Get all term IDs on a node that are on orderable vocabularies.
   *
   * Returns an array of the node's tids that are in orderable vocabularies.
   * Slower than self::getOrderableTids() but needed when tids have already been
   * removed from the database.
   *
   * @param \Drupal\node\NodeInterface
   *   The node to find term IDs for.
   *
   * @return int[]
   *   An array of term IDs.
   */
  public static function getOrderableTidsFromNode(NodeInterface $node) {
    $tids = [];
    foreach ($node->getFieldDefinitions() as $field) {
      if ($field->getType() == 'entity_reference' && $field->getSetting('target_type') == 'taxonomy_term') {
        // If a field value is not set in the node object when node_save() is
        // called, the old value from $node->original is used.
        $field_name = $field->getName();
        foreach ($node->getTranslationLanguages() as $langcode) {
          $translated = $node->getTranslation($langcode->getId());
          foreach ($translated->{$field_name} as $item) {
            $term = $item->getValue();
            if (!empty($term['target_id'])) {
              $tids[$term['target_id']] = $term['target_id'];
            }
          }
        }
      }
    }

    return $tids;
  }

  /**
   * Reorder list in which the node is dropped.
   *
   * When a node is removed, recalculates the ordering for a given term ID
   *
   * @param int $tid
   *   The term ID.
   */
  public static function handleListsDecrease($tid) {
    $taxonomy_nids = db_select('taxonomy_index', 'ti')
      ->fields('ti', array('nid'))
      ->condition('ti.tid', $tid)
      ->orderBy('ti.weight')
      ->execute()
      ->fetchCol('nid');
    if (!count($taxonomy_nids)) {
      return;
    }
    $weights = static::getTermMinMax($tid, TRUE);
    $range_border = ceil(count($taxonomy_nids) / 2);
    // Out of range when one of both new list's border weights is corresponding range border.
    $border_out_of_range = ($weights['min'] < -$range_border || $weights['max'] > $range_border);
    if ($border_out_of_range) {
      $weight = -$range_border;
      foreach ($taxonomy_nids as $nid) {
        $query = db_update('taxonomy_index')
          ->fields(array('weight' => $weight))
          ->condition('nid', $nid)
          ->condition('tid', $tid)
          ->execute();
        $weight ++;
      }
      // Make sure the weight cache is invalidated.
      static::getTermMinMax($tid, TRUE);
    }
  }

}
