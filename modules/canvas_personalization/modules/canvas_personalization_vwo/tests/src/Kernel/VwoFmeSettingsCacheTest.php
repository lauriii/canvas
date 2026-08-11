<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_personalization_vwo\Kernel;

use Drupal\canvas_personalization_vwo\VwoFmeAudienceResolver;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use wingify\Wingify;

/**
 * Tests that the cached VWO settings file is one the SDK still accepts.
 *
 * The resolver caches VWO's account-wide settings file so a page render does
 * not fetch it. That only works if what goes into the cache comes back out as
 * settings the SDK will use — and the SDK does not fail loudly when it will
 * not: it logs, swaps in empty settings, and hands back a working client that
 * reports every flag disabled. A cache entry that the SDK rejects therefore
 * un-personalizes the site silently for as long as the entry lives, which is
 * exactly the failure this covers.
 *
 * Both cases run without a network: a good cache entry must be usable on its
 * own, and a bad one must be refused rather than trusted.
 */
#[Group('canvas')]
#[Group('canvas_personalization')]
#[RunTestsInSeparateProcesses]
final class VwoFmeSettingsCacheTest extends KernelTestBase {

  /**
   * Credentials that could never work, so any network attempt fails the test.
   */
  private const string SDK_KEY = '00000000000000000000000000000000';
  private const int ACCOUNT_ID = 1;
  private const string FLAG = 'test-audience';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_personalization',
    'canvas_personalization_vwo',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!\class_exists(Wingify::class)) {
      $this->markTestSkipped('The VWO FME PHP SDK is not installed.');
    }
    parent::setUp();
    $this->installConfig(['canvas_personalization_vwo']);
    $this->setSetting(VwoFmeAudienceResolver::SETTINGS_KEY, [
      'account_id' => self::ACCOUNT_ID,
      'sdk_key' => self::SDK_KEY,
    ]);
    // The SDK writes its own diagnostics straight to stdout, on both the path
    // that succeeds and the path that fails.
    $this->expectOutputRegex('//s');
  }

  private static function cacheId(): string {
    return 'canvas_personalization_vwo:settings:' . \hash('xxh128', self::SDK_KEY . ':' . self::ACCOUNT_ID);
  }

  private function primeSettingsCache(string $json): void {
    $this->container->get('cache.default')->set(self::cacheId(), $json, \time() + 300);
  }

  /**
   * A settings file in the shape VWO returns, with one always-on flag.
   */
  private static function settingsJson(): string {
    return \json_encode([
      'version' => 1,
      'accountId' => self::ACCOUNT_ID,
      'campaigns' => [
        [
          'id' => 1,
          'type' => 'FLAG_ROLLOUT',
          'key' => 'test_rollout',
          'name' => 'test rollout',
          'status' => 'RUNNING',
          'percentTraffic' => 100,
          'isForcedVariationEnabled' => FALSE,
          'segments' => (object) [],
          'variations' => [
            [
              'id' => 1,
              'name' => 'Variation-1',
              'weight' => 100,
              'startRangeVariation' => 1,
              'endRangeVariation' => 10000,
              'variables' => [],
              'segments' => [],
            ],
          ],
          'metrics' => [],
          'variables' => [],
        ],
      ],
      'features' => [
        [
          'id' => 1,
          'key' => self::FLAG,
          'name' => 'test audience',
          'status' => 'ON',
          'type' => 'FLAG',
          'metrics' => [],
          'impactCampaign' => (object) [],
          'rules' => [
            [
              'ruleKey' => 'test_rollout',
              'campaignId' => 1,
              'type' => 'FLAG_ROLLOUT',
              'status' => 'RUNNING',
            ],
          ],
          'rulesLinkedCampaign' => [],
          'variables' => [],
        ],
      ],
    ], \JSON_THROW_ON_ERROR);
  }

  /**
   * A cached settings file is enough to answer, with no call to VWO.
   */
  public function testCachedSettingsAnswerWithoutContactingVwo(): void {
    $this->primeSettingsCache(self::settingsJson());
    $resolver = $this->container->get(VwoFmeAudienceResolver::class);
    \assert($resolver instanceof VwoFmeAudienceResolver);
    // The credentials are deliberately unusable, so reaching the network at
    // all would throw rather than return.
    $this->assertTrue($resolver->isInAudience(self::FLAG, 'D' . \str_repeat('A1B2C3D4', 4)));
  }

  /**
   * A cache entry the SDK would reject is refused, not quietly trusted.
   *
   * "{}" is what `json_encode()` produces for the SDK's own settings model,
   * whose properties are all private — the shape this cache is most likely to
   * be poisoned with. Trusting it would report every visitor a non-member for
   * the life of the entry; refusing it means falling back to a fetch, which
   * these credentials cannot complete, so it surfaces as a throwable the
   * condition logs and negatively caches.
   */
  public function testRejectedSettingsAreNotTrusted(): void {
    $this->primeSettingsCache('{}');
    $resolver = $this->container->get(VwoFmeAudienceResolver::class);
    \assert($resolver instanceof VwoFmeAudienceResolver);
    $this->expectException(\RuntimeException::class);
    $resolver->isInAudience(self::FLAG, 'D' . \str_repeat('A1B2C3D4', 4));
  }

}
