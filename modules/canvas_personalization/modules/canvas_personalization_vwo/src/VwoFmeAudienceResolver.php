<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use wingify\Wingify;
use wingify\WingifyClient;

/**
 * Resolves audience membership through VWO's FME PHP SDK.
 *
 * VWO has no API that answers "is visitor X in audience Y". Its audiences are
 * an evaluation *input* to a campaign or a feature flag, never a queryable
 * output: the REST API has no audiences, segments or profiles endpoints; the
 * one UUID-keyed endpoint returns campaigns and variations with no segment
 * field, is Enterprise-only, and is rate limited to one request per second per
 * token — unusable in a render path; and Data360 segments are documented as
 * post-segmentation for reports only.
 *
 * So an audience is modeled as an FME feature flag whose rule targets it, and
 * membership is that flag being enabled for the visitor. The SDK evaluates the
 * flag locally against a downloaded settings file, which is what makes this
 * viable at render time at all.
 *
 * Two consequences a site builder has to know, both VWO's, not Canvas':
 * - Only targeting the SDK can evaluate from the settings file applies.
 *   Behavioral and historical conditions, and VWO Testing or Data360
 *   audiences, are not reachable from a server at all.
 * - Geolocation and user-agent targeting need VWO's self-hosted Gateway
 *   Service, which this module does not wire up; a flag whose rules use them
 *   will not evaluate correctly here.
 *
 * @see https://developers.wingify.com/v2/docs/fme-unified-experimentation-identity-synchronization.md
 * @see https://developers.wingify.com/v2/docs/fme-php-flags.md
 */
final class VwoFmeAudienceResolver implements VwoAudienceResolverInterface {

  /**
   * Where the credentials live: $settings['canvas_personalization_vwo'].
   *
   * Kept out of configuration on purpose. Segment rules are exported with site
   * config and readable over the segment HTTP API, so a secret placed there
   * leaks; the plugin holds only the flag key.
   */
  public const string SETTINGS_KEY = 'canvas_personalization_vwo';

  /**
   * The SDK's own settings-fetch timeout defaults to 50 seconds.
   *
   * That is a page-hanging default for a synchronous runtime, so a timeout is
   * always passed explicitly.
   */
  private const int DEFAULT_TIMEOUT_MS = 2000;

  /**
   * Retries are disabled so `timeout_ms` is the whole budget, not one attempt.
   *
   * The SDK retries a failed network call three times by default, sleeping 2,
   * 4, then 8 seconds between attempts with a synchronous `sleep()`. Against
   * an unreachable VWO that is roughly 22 seconds of blocked page render on
   * top of the configured timeout — which would make the timeout meaningless.
   * A visitor's variant is not worth retrying for; the failure is negatively
   * cached instead.
   *
   * @see \wingify\Packages\NetworkLayer\client\NetworkClient::makeCurlRequest()
   */
  private const array NO_RETRIES = ['shouldRetry' => FALSE];

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isInAudience(string $flag_key, string $visitor_uuid): bool {
    if (!\class_exists(Wingify::class)) {
      throw new \RuntimeException('The VWO FME PHP SDK is not installed; run `composer require wingify/wingify-fme-php-sdk`.');
    }
    $credentials = Settings::get(self::SETTINGS_KEY, []);
    $sdk_key = $credentials['sdk_key'] ?? NULL;
    $account_id = $credentials['account_id'] ?? NULL;
    if (!\is_string($sdk_key) || $sdk_key === '' || $account_id === NULL) {
      throw new \RuntimeException(\sprintf('VWO credentials are missing; set $settings[\'%s\'] to an array with `sdk_key` and `account_id`.', self::SETTINGS_KEY));
    }

    $client = $this->client($sdk_key, (string) $account_id);
    // `useIdForWeb` makes the SDK reject a UUID it would not accept as a
    // browser-issued one instead of silently deriving a different, server-side
    // UUID — which would bucket the visitor differently from the browser and
    // serve a variant that disagrees with what VWO reports.
    $flag = $client->getFlag($flag_key, [
      'id' => $visitor_uuid,
      'useIdForWeb' => TRUE,
    ]);
    return (bool) $flag->isEnabled();
  }

  /**
   * An initialized SDK client, reusing a cached settings file where possible.
   *
   * PHP is shared-nothing, so without this every lookup would fetch VWO's
   * settings file from their CDN inside the page render. The settings file is
   * account-wide and small; caching it for `settings_ttl` turns the common
   * case into a local evaluation with no network at all.
   */
  private function client(string $sdk_key, string $account_id): WingifyClient {
    $settings = $this->configFactory->get('canvas_personalization_vwo.settings');
    $timeout = (int) $settings->get('timeout_ms');
    $options = [
      'sdkKey' => $sdk_key,
      'accountId' => $account_id,
      'settingsConfig' => ['timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_MS],
      'retryConfig' => self::NO_RETRIES,
      // A PHP request is shared-nothing; nothing here should phone home on its
      // own schedule.
      'isUsageStatsDisabled' => TRUE,
    ];

    $cid = 'canvas_personalization_vwo:settings:' . \hash('xxh128', $sdk_key . ':' . $account_id);
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE && \is_string($cached->data)) {
      $client = Wingify::init($options + ['settings' => $cached->data]);
      if ($client !== NULL) {
        return $client;
      }
      // Stale or rejected settings: fall through and re-fetch once.
    }

    $client = Wingify::init($options);
    if ($client === NULL) {
      // Wingify::init() swallows every failure and returns NULL. Turn that
      // back into a throwable so the membership layer negatively caches it
      // rather than retrying on every render.
      throw new \RuntimeException('Initializing the VWO FME SDK failed.');
    }
    $fetched = \json_encode($client->originalSettings);
    if (\is_string($fetched) && $fetched !== 'null') {
      $settings_ttl = (int) $settings->get('settings_ttl');
      $ttl = $settings_ttl > 0 ? $settings_ttl : 300;
      $this->cache->set($cid, $fetched, $this->time->getRequestTime() + $ttl);
    }
    return $client;
  }

}
