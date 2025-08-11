<?php

namespace Drupal\xb_ai\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_agents\Plugin\AiFunctionCall\AiAgentWrapper;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\Task\Task;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\xb_ai\Plugin\AiFunctionCall\AddMetadata;
use Drupal\xb_ai\Plugin\AiFunctionCall\CreateComponent;
use Drupal\xb_ai\Plugin\AiFunctionCall\EditComponentJs;
use Drupal\xb_ai\Plugin\AiFunctionCall\CreateFieldContent;
use Drupal\xb_ai\Plugin\AiFunctionCall\EditFieldContent;
use Drupal\xb_ai\Plugin\AiFunctionCall\SetAIGeneratedComponentStructure;
use Drupal\xb_ai\XbAiPageBuilderHelper;
use Drupal\xb_ai\XbAiTempStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders the Experience Builder AI calls.
 */
final class XbBuilder extends ControllerBase {

  /**
   * Constructs a new XbBuilder object.
   */
  public function __construct(
    protected AiProviderPluginManager $providerService,
    protected PluginManagerInterface $agentManager,
    protected CsrfTokenGenerator $csrfTokenGenerator,
    protected XbAiPageBuilderHelper $xbAiPageBuilderHelper,
    protected XbAiTempStore $xbAiTempStore,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('plugin.manager.ai_agents'),
      $container->get('csrf_token'),
      $container->get('xb_ai.page_builder_helper'),
      $container->get('xb_ai.tempstore'),
      $container->get('file_system'),
    );
  }

  /**
   * Renders the Experience Builder AI calls.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function render(Request $request): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token') ?? '';
    if (!$this->csrfTokenGenerator->validate($token, 'xb_ai.xb_builder')) {
      throw new AccessDeniedHttpException('Invalid CSRF token');
    }

    /** @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent */
    $agent = $this->agentManager->createInstance('xb_ai_orchestrator');
    $contentType = $request->getContentTypeFormat();
    $files = [];
    if ($contentType === 'json') {
      $prompt = Json::decode($request->getContent());
    }
    else {
      $prompt = $request->request->all();
      $files = $request->files->all();
    }
    // If $prompt['messages'] is missing or invalid, this code reconstructs it
    // by scanning for keys named 'message <number>', and
    // assembling them into an ordered 'messages' array, while cleaning up old keys
    // as we use $prompt['messages'] for further processing .
    if (!isset($prompt['messages']) || !is_array($prompt['messages'])) {
      $messages = [];
      $keys_to_remove = [];
      foreach ($prompt as $key => $value) {
        if (preg_match('/^message(\d+)$/', $key, $matches)) {
          $num = (int) $matches[1];
          $decoded = Json::decode($value);
          if ($decoded !== NULL) {
            $messages[$num] = $decoded;
            $keys_to_remove[] = $key;
          }
        }
      }
      if (!empty($messages)) {
        ksort($messages);
        $prompt['messages'] = array_values($messages);
        foreach ($keys_to_remove as $key) {
          unset($prompt[$key]);
        }
      }
    }
    $file_entities = [];
    foreach ($files as $file) {
      $allowed_image_types = ['image/jpeg', 'image/png'];
      $mime_type = $file->getClientMimeType();

      if (!in_array($mime_type, $allowed_image_types, TRUE)) {
        return new JsonResponse([
          'status' => FALSE,
          'message' => 'Only image files are allowed (jpeg, png, jpg).',
        ]);
      }
      // Copy the file to the temp directory.
      $tmp_name = 'temporary://' . $file->getClientOriginalName();
      $this->fileSystem->copy($file->getPathname(), $tmp_name, FileExists::Replace);
      // Create actual file entities.
      $file = $this->entityTypeManager()->getStorage('file')->create([
        'uid' => $this->currentUser()->id(),
        'filename' => $file->getClientOriginalName(),
        'uri' => $tmp_name,
        'status' => 0,
      ]);
      $file->save();
      $file_entities[] = $file;
    }

    if (empty($prompt['messages'])) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'No prompt provided',
      ]);
    }
    // Add dynamic comments.
    $comments = [];
    $task_message = array_pop($prompt['messages']);

    // Store the current layout in the temp store. This will be later used by
    // the ai agents.
    // @see \Drupal\xb_ai\Plugin\AiFunctionCall\GetCurrentLayout.
    $current_layout = $prompt['current_layout'] ?? '';
    if (!empty($current_layout)) {
      $this->xbAiTempStore->setData(XbAiTempStore::CURRENT_LAYOUT_KEY, Json::encode($current_layout));
    }

    $task = $prompt['messages'];
    foreach ($task as $message) {
      $comments[] = [
        'role' => $message['role'],
        'message' => $message['text'],
      ];
    }
    $task = new Task($task_message['text']);
    $agent->setTask($task);
    if (!empty($file_entities)) {
      $task->setFiles($file_entities);
    }
    $task->setComments($comments);
    $default = $this->providerService->getDefaultProviderForOperationType('chat');
    if (!is_array($default) || empty($default['provider_id']) || empty($default['model_id'])) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'No default provider found.',
      ]);
    }
    $config = $this->config('xb_ai.settings');
    $http_client_options = [
      'timeout' => $config->get('http_client_options.timeout') ?? 60,
    ];
    $provider = $this->providerService->createInstance(
      $default['provider_id'],
      ['http_client_options' => $http_client_options]
    );
    $agent->setAiProvider($provider);
    $agent->setModelName($default['model_id']);
    $agent->setAiConfiguration([]);
    $agent->setCreateDirectly(TRUE);
    $agent->setTokenContexts(['entity_type' => $prompt['entity_type'], 'entity_id' => $prompt['entity_id'], 'selected_component' => $prompt['selected_component'] ?? NULL, 'layout' => $prompt['layout'] ?? NULL, 'derived_proptypes' => JSON::encode($prompt['derived_proptypes']) ?? NULL, 'page_title' => $prompt['page_title'], 'page_description' => $prompt['page_description'] ?? NULL, 'active_component_uuid' => $prompt['active_component_uuid'] ?? 'None']);
    $solvability = $agent->determineSolvability();
    $status = FALSE;
    $message = '';
    $response = [];
    if ($solvability == AiAgentInterface::JOB_NOT_SOLVABLE) {
      $message = 'Something went wrong';
    }
    elseif ($solvability == AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION) {
      $message = $agent->answerQuestion();
    }
    elseif ($solvability == AiAgentInterface::JOB_INFORMS) {
      $message = $agent->inform();
      $status = TRUE;
    }
    elseif ($solvability == AiAgentInterface::JOB_SOLVABLE) {
      $response['status'] = TRUE;
      $tools = $agent->getToolResults(TRUE);
      $map = [
        EditComponentJs::class => ['js_structure', 'props_metadata'],
        CreateComponent::class => ['component_structure'],
        CreateFieldContent:: class => ['created_content'],
        EditFieldContent:: class => ['refined_text'],
        AddMetadata::class => ['metadata'],
        SetAIGeneratedComponentStructure::class => ['operations'],
      ];
      if (!empty($tools)) {
        foreach ($tools as $tool) {
          foreach ($map as $class => $keys) {
            if ($tool instanceof $class) {
              // @todo Refactor this after https://www.drupal.org/i/3529313 is fixed.
              $output = $tool->getReadableOutput();
              $data = Yaml::parse($output);
              foreach ($keys as $key) {
                if (!empty($data[$key])) {
                  $response[$key] = $data[$key];
                }
              }
            }
          }
          if ($tool instanceof AiAgentWrapper) {
            $response['message'] = $tool->getReadableOutput();
          }
          if ($tool->getPluginId() === 'ai_agents::ai_agent::experience_builder_page_builder_agent') {
            $this->xbAiTempStore->deleteData(XbAiTempStore::CURRENT_LAYOUT_KEY);
          }
        }
      }
      else {
        $response['message'] = $agent->solve();
      }
      return new JsonResponse(
        $response,
      );
    }
    return new JsonResponse([
      'status' => $status,
      'message' => $message,
    ]);
  }

  /**
   * Function to get the x-csrf-token.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function getCsrfToken(Request $request): Response {
    return new Response($this->csrfTokenGenerator->get('xb_ai.xb_builder'));
  }

}
