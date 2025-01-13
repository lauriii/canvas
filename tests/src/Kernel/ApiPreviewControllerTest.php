<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\experience_builder\Kernel\Traits\RequestTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @coversDefaultClass \Drupal\experience_builder\Controller\ApiPreviewController
 * @group experience_builder
 */
final class ApiPreviewControllerTest extends KernelTestBase {

  use RequestTrait {
    request as parentRequest;
  }
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system', 'block']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('config.factory')->getEditable('system.theme')->set('default', 'stark')->save();

    (new XBTestSetup())->setup();
    $this->setUpCurrentUser([], ['access administration pages']);
  }

  public function testEmpty(): void {
    $this->request(Request::create('/api/preview/node/1', content: json_encode([
      'layout' => [
        [
          'nodeType' => 'region',
          'name' => 'Content',
          'components' => [],
          'id' => 'content',
        ],
      ],
      'model' => [],
    ], JSON_THROW_ON_ERROR)));

    // Check that the root level is structured correctly.
    $root = $this->cssSelect('main div[data-xb-region][data-xb-uuid="content"]');
    $this->assertNotEmpty($root);
    $this->assertCount(0, $root[0]);
  }

  public function testMissingSlot(): void {
    $this->request(Request::create('/api/preview/node/1', content: json_encode([
      'layout' => [
        [
          'nodeType' => 'region',
          'name' => 'Content',
          'components' => [
            [
              'nodeType' => 'component',
              'slots' => [
                [
                  'components' => [],
                  'id' => 'c4074d1f-149a-4662-aaf3-615151531cf6/content',
                  'name' => 'content',
                  'nodeType' => 'slot',
                ],
              ],
              'type' => 'sdc.experience_builder.one_column',
              'uuid' => 'c4074d1f-149a-4662-aaf3-615151531cf6',
            ],
          ],
          'id' => 'content',
        ],
      ],
      'model' => [
        'c4074d1f-149a-4662-aaf3-615151531cf6' => [
          'width' => 'full',
        ],
      ],
    ], JSON_THROW_ON_ERROR)));

    // Check that the root level is structured correctly.
    $root = $this->cssSelect('main div[data-xb-region="content"]');

    $this->assertNotEmpty($root);
    $this->assertGreaterThan(0, $root[0]->count());
    preg_match_all('/(xb-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $root[0]->asXML() ?: '', $slot_and_component_comments);
    $this->assertSame(['c4074d1f-149a-4662-aaf3-615151531cf6'], $slot_and_component_comments[2]);
  }

  public function test(): void {
    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $model = json_decode($content, TRUE)['model'];
    $this->request(Request::create('/api/preview/node/1', content: $content));

    // Check that each level is structured correctly.
    $root = $this->cssSelect('main div[data-xb-region][data-xb-uuid="content"]');
    $this->assertNotEmpty($root);
    $this->assertGreaterThan(0, $root[0]->count());
    preg_match_all('/(xb-start-)(.*?)[\/ \t](.*?)(-->)(.*?)/', $root[0]->asXML() ?: '', $slot_and_component_comments);
    $this->assertCount(6, $slot_and_component_comments[2]);
    $this->assertSame(array_keys($model), $slot_and_component_comments[2]);
  }

  public function testWithGlobal(): void {
    $template = PageTemplate::createFromBlockLayout('stark');
    $template->enable()->save();

    // Load the test data from the layout controller.
    $content = $this->parentRequest(Request::create('/api/layout/node/1'))->getContent();
    $this->assertIsString($content);
    $json = json_decode($content, TRUE);
    $highlightedRegion = \array_filter($json['layout'], static fn (array $region) => ($region['id'] ?? NULL) === 'highlighted');
    self::assertCount(1, $highlightedRegion);
    self::assertGreaterThanOrEqual(1, \count(\reset($highlightedRegion)['components']));
    $this->request(Request::create('/api/preview/node/1', content: $content));

    // Check that regions exist and are wrapped.
    $crawler = new Crawler($this->content);
    self::assertCount(1, $crawler->filter('[data-xb-uuid="content"]'));
    self::assertCount(1, $crawler->filter('[data-xb-uuid="highlighted"]'));
  }

  /**
   * Unwrap the JSON response so we can perform assertions on it.
   */
  protected function request(Request $request): Response {
    $response = $this->parentRequest($request);
    $content = $response->getContent();
    $this->assertIsString($content);
    $this->setRawContent(json_decode($content, TRUE)['html']);
    return $response;
  }

}
