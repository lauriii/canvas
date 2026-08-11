<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization\Kernel;

use Drupal\canvas_personalization\SegmentCondition\EnumeratesAudiencesInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionManager;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a provider can offer its audiences to the authoring UI.
 *
 * Without this a third-party audience is an opaque string an author types, and
 * a typo is indistinguishable from an audience nobody is in — both render the
 * page and serve the default variant, with nothing logged and nothing on the
 * status report.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
#[RunTestsInSeparateProcesses]
final class SegmentConditionAudiencesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'canvas_personalization',
    'canvas_personalization_test',
  ];

  /**
   * A condition that cannot enumerate is listed without an audiences key.
   */
  public function testConditionsWithoutAudiencesOmitTheKey(): void {
    $manager = $this->container->get(SegmentConditionManager::class);
    \assert($manager instanceof SegmentConditionManager);
    $utm = $manager->createInstance('utm_parameters');
    $this->assertNotInstanceOf(EnumeratesAudiencesInterface::class, $utm);
  }

  /**
   * A provider that can enumerate has its audiences offered for selection.
   */
  public function testEnumeratedAudiencesAreOffered(): void {
    $this->container->get('state')->set('canvas_personalization_test.audiences', [
      'loyal-customers' => 'Loyal customers',
      'first-visit' => 'First visit',
    ]);
    $manager = $this->container->get(SegmentConditionManager::class);
    \assert($manager instanceof SegmentConditionManager);
    $condition = $manager->createInstance('test_external_provider');
    \assert($condition instanceof EnumeratesAudiencesInterface);
    $this->assertSame([
      'loyal-customers' => 'Loyal customers',
      'first-visit' => 'First visit',
    ], $condition->listAudiences());
  }

  /**
   * A provider that cannot be reached returns nothing rather than throwing.
   *
   * The authoring UI has to keep opening while the provider is down; it just
   * falls back to letting the identifier be typed.
   */
  public function testUnreachableProviderOffersNothing(): void {
    $this->container->get('state')->set('canvas_personalization_test.audiences', 'throw');
    $manager = $this->container->get(SegmentConditionManager::class);
    \assert($manager instanceof SegmentConditionManager);
    $condition = $manager->createInstance('test_external_provider');
    \assert($condition instanceof EnumeratesAudiencesInterface);
    $this->assertSame([], $condition->listAudiences());
  }

}
