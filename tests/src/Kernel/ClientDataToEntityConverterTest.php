<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\ClientDataToEntityConverter;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;

class ClientDataToEntityConverterTest extends KernelTestBase {

  use XBFieldTrait {
    getValidClientJson as traitGetValidClientJson;
  }
  use ConstraintViolationsTestTrait;

  public function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
    $this->setUpImages();
  }

  /**
   * {@inheritdoc}
   */
  private function getValidClientJson(): array {
    $json = $this->traitGetValidClientJson();
    $content_region = \array_values(\array_filter($json['layout'], static fn(array $region) => $region['uuid'] === 'content'));
    return [
      'layout' => reset($content_region),
      'model' => $json['model'],
    ];
  }

  public function testConvert(): void {
    $this->assertConvert(
      $this->getValidClientJson(),
      []
    );

    $invalid_heading_client_json = $this->getValidClientJson();
    $invalid_heading_client_json['model'][self::TEST_HEADING_UUID]['style'] = 'not-a-style';
    $this->assertConvert(
      $invalid_heading_client_json,
      ['model.' . self::TEST_HEADING_UUID . '.style' => 'Does not have a value in the enumeration ["primary","secondary"]']
    );

    $invalid_missing_heading_props_client_json = $this->getValidClientJson();
    unset($invalid_missing_heading_props_client_json['model'][self::TEST_HEADING_UUID]);
    $this->assertConvert(
      $invalid_missing_heading_props_client_json,
      ['model.' . self::TEST_HEADING_UUID => 'The required properties are missing.']
    );
  }

  protected function assertConvert(array $client_json, array $expected_errors): void {
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'field_xb_demo' => [
        'tree' => json_encode([
          ComponentTreeStructure::ROOT_UUID => [],
        ]),
        'props' => '{}',
      ],
    ]);
    $this->assertSame(SAVED_NEW, $node->save());
    $violations = $this->container->get(ClientDataToEntityConverter::class)->convert($client_json, $node);
    $this->assertSame($node->id(), $violations->getEntity()->id());
    $this->assertSame($expected_errors, self::violationsToArray($violations));
  }

}
