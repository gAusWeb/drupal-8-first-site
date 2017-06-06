<?php

namespace Drupal\Tests\page_load_progress\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Url;

/**
 * Tests for the page_load_progress module.
 *
 * @group page_load_progress
 */
class PageLoadProgressAdminSettingsFormTest extends BrowserTestBase {
  /**
   * User account with page_load_progress permissions.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $privilegedUser;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['page_load_progress'];

  /**
   * The installation profile to use with this test.
   *
   * @var string
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return [
      'name' => 'Page Load Progress settings form',
      'description' => 'Tests the Page Load Progress admin settings form.',
      'group' => 'Page Load Progress',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Privileged user should only have the page_load_progress permissions.
    $this->privilegedUser = $this->drupalCreateUser(['administer page load progress']);
    $this->drupalLogin($this->privilegedUser);
  }

  /**
   * Test the page_load_progress settings form.
   */
  public function testPageLoadProgressSettings() {
    // Verify if we can successfully access the page_load_progress form.
    $this->drupalGet(Url::fromRoute('page_load_progress.admin_settings'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Page Load Progress | Drupal');

    // Verify every field exists.
    $this->assertSession()->fieldExists('edit-page-load-progress-time');
    $this->assertSession()->fieldExists('edit-page-load-progress-elements');
    $this->assertSession()->fieldExists('edit-page-load-progress-esc-key');

    // Validate default form values.
    $this->assertSession()->fieldValueEquals('edit-page-load-progress-time', 10);
    $this->assertSession()->fieldValueEquals('edit-page-load-progress-elements', '.form-submit');
    $this->assertSession()->fieldValueEquals('edit-page-load-progress-esc-key', TRUE);

    // Verify that there's no access bypass.
    $this->drupalLogout();
    $this->drupalGet(Url::fromRoute('page_load_progress.admin_settings'));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Test posting data to the page_load_progress settings form.
   */
  public function testPageLoadProgressSettingsPost() {
    // Post form with new values.
    $edit = [
      'page_load_progress_time' => 5000,
      'page_load_progress_elements' => '.sample_submit',
      'page_load_progress_esc_key' => FALSE,
    ];
    $this->drupalPostForm(Url::fromRoute('page_load_progress.admin_settings'), $edit, 'Save configuration');

    // Load settings form page and test for new values.
    $this->drupalGet(Url::fromRoute('page_load_progress.admin_settings'));
    $this->assertSession()->fieldValueEquals('edit-page-load-progress-time', $edit['page_load_progress_time']);
    $this->assertSession()->fieldValueEquals('edit-page-load-progress-elements', $edit['page_load_progress_elements']);
    $this->assertSession()->fieldValueEquals('edit-page-load-progress-esc-key', $edit['page_load_progress_esc_key']);
  }

}
