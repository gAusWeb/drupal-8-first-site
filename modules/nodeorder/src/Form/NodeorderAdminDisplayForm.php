<?php
/**
 * @file
 * Contains \Drupal\nodeorder\Form\NodeorderAdminDisplayForm.
 */
namespace Drupal\nodeorder\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Implements an example form.
 */
class NodeorderAdminDisplayForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'nodeorder_admin_display';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    global $pager_page_array, $pager_total, $pager_total_items;

    $page            = isset($_GET['page']) ? $_GET['page'] : 0;
    $page_increment  = \Drupal::config('nodeorder.settings')->get('taxonomy_terms_per_page_admin');  // Number of terms per page.
    $page_entries    = 0;   // Elements shown on this page.
    $before_entries  = 0;   // Elements at the root level before this page.
    $after_entries   = 0;   // Elements at the root level after this page.
    $root_entries    = 0;   // Elements at the root level on this page.

    $tid = \Drupal::request()->attributes->get('taxonomy_term');

    $term = Term::load($tid);

    // Build form tree.
    $form = array(
      '#tree' => TRUE,
      '#parent_fields' => FALSE,
      '#term' => $term,
    );
    $form['#title'] = $this->t('Order nodes for <em>%term_name</em>', array('%term_name' => $term->label()));

    $node_ids = db_select('taxonomy_index', 'ti')
      ->fields('ti', ['nid', 'weight'])
      ->condition('ti.tid', $tid)
      ->orderBy('ti.weight')
      ->execute()
      ->fetchAllKeyed();
    $nodes = Node::loadMultiple(array_keys($node_ids));
    $node_count = count($nodes);

    // Weights range from -delta to +delta, so delta should be at least half
    // of the amount of blocks present. This makes sure all blocks in the same
    // region get an unique weight.
    $weight_delta = round($node_count / 2);
    $current_weight = $node_ids;

    foreach ($nodes as $node) {
      $form[$node->id()]['#node'] = $node;
      $form[$node->id()]['title'] = array(
        '#markup' => $node->label());
      $form[$node->id()]['weight'] = array(
        '#type' => 'weight',
        '#title' => $this->t('Weight for @title', array('@title' => $node->label())),
        '#title_display' => 'invisible',
        '#delta' => $weight_delta,
        '#default_value' => $current_weight[$node->id()],
      );
    }

    if (empty($node_count)) {
      $form['empty'] = array(
        '#markup' => '<div>' . $this->t('No nodes exist in this category') . '</div>',
      );
    }

    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Save order'),
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // if (strlen($form_state->getValue('phone_number')) < 3) {
    //   $this->setFormError('phone_number', $form_state, $this->t('The phone number is too short. Please enter a full phone number.'));
    // }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $tid = $form['#term']->tid->value;

    foreach ($form_state->getValues() as $nid => $node) {
      // Only take form elements that are blocks.
      if (is_array($node) && array_key_exists('weight', $node)) {
        db_update('taxonomy_index')->fields(['weight' => $node['weight']])
          ->condition('tid', $tid)
          ->condition('nid', $nid)
          ->execute();
      }
    }

    drupal_set_message(t('The node orders have been updated.'));
    \Drupal::cache()->deleteAll();
  }
}
