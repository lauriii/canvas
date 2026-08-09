<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Functional\Form;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization\Plugin\SegmentCondition\UtmParameters;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic testing of the critical path for the segment forms.
 *
 * ⚠️ This is highly experimental and *will* be refactored or even removed.
 *
 * @todo Revisit in https://www.drupal.org/i/3527086
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
final class SegmentFormTest extends BrowserTestBase {

  use ContribStrictConfigSchemaTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'user',
    'canvas_personalization',
    // @todo Remove once ComponentSourceInterface is a public API, i.e. after https://www.drupal.org/i/3520484#stable is done.
    'canvas_dev_mode',
  ];

  /**
   * Tests creating a segment and managing its rules through the forms.
   */
  public function testCreatingSegment(): void {
    $admin_user = $this->drupalCreateUser([
      'administer site configuration',
      Segment::ADMIN_PERMISSION,
    ]);
    \assert($admin_user instanceof AccountInterface);
    $this->drupalLogin($admin_user);
    $this->drupalGet('/admin/structure/segment/add');
    $this->assertSession()->elementExists('xpath', '//table[@id="rules-id"]//td[text() = "No rules added yet."]');
    $edit = [
      'id' => 'my_segment',
      'label' => 'My segment',
      'description' => 'My segment description',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->addressEquals('admin/structure/segment');
    $this->assertSession()->statusMessageContains('Created new personalization segment My segment.');

    $this->clickLink('Edit');
    $this->assertSession()->addressEquals('admin/structure/segment/my_segment');
    $this->clickLink('New segment rule');
    $edit = [
      'plugin_id' => 'day_of_week',
      'settings[days][saturday]' => TRUE,
      'settings[days][sunday]' => TRUE,
      'settings[negate]' => FALSE,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusMessageContains('Updated personalization segment My segment.');

    $this->clickLink('New segment rule');
    $edit = [
      'plugin_id' => 'geolocation',
      'settings[countries]' => 'nl, be',
      'settings[regions]' => '',
      'settings[negate]' => TRUE,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusMessageContains('Updated personalization segment My segment.');
    $this->assertSession()->addressEquals('admin/structure/segment/my_segment');
    $this->assertSession()->elementTextContains('xpath', '//table[@id="rules-id"]', "Day of week");
    $this->assertSession()->elementTextContains('xpath', '//table[@id="rules-id"]', "Geolocation");

    $this->clickLink('New segment rule');
    $edit = [
      'plugin_id' => 'query_parameter',
      'settings[parameter]' => 'coupon',
      'settings[value]' => 'BLACKFRIDAY',
      'settings[matching]' => 'exact',
      'settings[negate]' => FALSE,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusMessageContains('Updated personalization segment My segment.');

    $this->clickLink('New segment rule');
    $edit = [
      'plugin_id' => 'utm_parameters',
      'settings[new_parameter][key]' => UtmParameters::CUSTOM,
      'settings[new_parameter][custom_key]' => 'utm_author',
      'settings[new_parameter][value]' => 'Jim Morrison',
      'settings[new_parameter][matching]' => 'exact',
      'settings[all]' => TRUE,
      'settings[negate]' => FALSE,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusMessageContains('Updated personalization segment My segment.');
    $this->assertSession()->addressEquals('admin/structure/segment/my_segment');
    $this->assertSession()->elementTextContains('xpath', '//table[@id="rules-id"]', "Query parameter");
    $this->assertSession()->elementTextContains('xpath', '//table[@id="rules-id"]', "UTM parameters");

    // As we cannot have repeated rules, verify the form doesn't fail
    // when none are available.
    $this->clickLink('New segment rule');
    $this->assertSession()->elementTextContains('xpath', '//form[contains(@class,"segment-add-rule-form-form")]', "No applicable conditions found.");

    // I can't delete a rule without a valid csrf token.
    $this->drupalGet('admin/structure/segment/my_segment/rule-delete/day_of_week');
    $this->assertSession()->statusCodeEquals(Response::HTTP_FORBIDDEN);

    // If I delete a rule, I can re-add it.
    $this->drupalGet('admin/structure/segment/my_segment');
    $this->clickLink('Delete Day of week');
    $this->assertSession()->elementTextNotContains('xpath', '//table[@id="rules-id"]', "Day of week");
    $this->assertSession()->elementTextContains('xpath', '//table[@id="rules-id"]', "Geolocation");
    $this->assertSession()->elementTextContains('xpath', '//table[@id="rules-id"]', "UTM parameters");

    $this->clickLink('New segment rule');
    $edit = [
      'plugin_id' => 'day_of_week',
      'settings[days][saturday]' => TRUE,
      'settings[negate]' => FALSE,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusMessageContains('Updated personalization segment My segment.');
    $this->assertSession()->addressEquals('admin/structure/segment/my_segment');
    $this->assertSession()->elementTextContains('xpath', '//table[@id="rules-id"]', "Day of week");

    $segment = Segment::load('my_segment');
    \assert($segment instanceof Segment);
    $this->assertEquals([
      'day_of_week' => [
        'id' => 'day_of_week',
        'negate' => FALSE,
        'days' => ['saturday'],
      ],
      'geolocation' => [
        'id' => 'geolocation',
        'negate' => TRUE,
        'countries' => ['BE', 'NL'],
        'regions' => [],
      ],
      'query_parameter' => [
        'id' => 'query_parameter',
        'negate' => FALSE,
        'parameter' => 'coupon',
        'value' => 'BLACKFRIDAY',
        'matching' => 'exact',
      ],
      'utm_parameters' => [
        'id' => 'utm_parameters',
        'negate' => FALSE,
        'all' => TRUE,
        'parameters' => [
          [
            'key' => 'utm_author',
            'value' => 'Jim%20Morrison',
            'matching' => 'exact',
          ],
        ],
      ],
    ], $segment->get('rules'));

    // The `orderby: key` in the config schema stores rules sorted by plugin
    // ID, independent of insertion order.
    $this->assertSame('day_of_week', array_key_first($segment->getSegmentRules()));
  }

}
