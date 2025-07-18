<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\CodeComponentDataProvider;
use Drupal\experience_builder\Entity\Page;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;

/**
 * @group experience_builder
 */
class CodeComponentDataProviderTest extends FunctionalTestBase {

  use ContribStrictConfigSchemaTestTrait;

  protected static $modules = [
    'experience_builder',
    'xb_test_code_components',
  ];

  protected $defaultTheme = 'stark';

  /**
   * @covers \Drupal\experience_builder\CodeComponentDataProvider::getXbDataBrandingV0
   * @covers \Drupal\experience_builder\CodeComponentDataProvider::getRequiredXbDataLibraries
   * @covers \Drupal\experience_builder\CodeComponentDataProvider::getPartialXbDataFromSettingsV0
   */
  public function testV0UsingDrupalSettingsGetSiteData(): void {
    $page = Page::create([
      'title' => 'Test page',
      'type' => 'page',
      'components' => [
        [
          'uuid' => XBTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'js.xb_test_code_components_using_drupalsettings_get_site_data',
        ],
      ],
    ]);
    $page->save();

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $this->drupalGet($page->toUrl());

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayHasKey(CodeComponentDataProvider::XB_DATA_KEY, $drupalSettings);
    self::assertSame([
      'baseUrl' => \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl(),
      'branding' => [
        'homeUrl' => '/user/login',
        'siteName' => 'Drupal',
        'siteSlogan' => '',
      ],
    ], $drupalSettings[CodeComponentDataProvider::XB_DATA_KEY][CodeComponentDataProvider::V0]);
  }

  /**
   * @covers \Drupal\experience_builder\CodeComponentDataProvider::getRequiredXbDataLibraries
   * @covers \Drupal\experience_builder\CodeComponentDataProvider::getPartialXbDataFromSettingsV0
   */
  public function testV0NotUsingDrupalSettings(): void {
    $page = Page::create([
      'title' => 'Test page',
      'type' => 'page',
      'components' => [
        [
          'uuid' => XBTestSetup::UUID_COMPONENT_SDC,
          'component_id' => 'js.xb_test_code_components_using_imports',
        ],
      ],
    ]);
    $page->save();

    $regular_user = $this->drupalCreateUser(['access content']);
    $this->assertInstanceOf(AccountInterface::class, $regular_user);
    $this->drupalLogin($regular_user);

    $this->drupalGet($page->toUrl());

    $drupalSettings = $this->getDrupalSettings();
    $this->assertArrayNotHasKey(CodeComponentDataProvider::XB_DATA_KEY, $drupalSettings);
  }

}
