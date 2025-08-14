<?php

namespace Drupal\xb_ai\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_agents\Plugin\AiFunctionCall\AiAgentWrapper;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
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
    $image_files = [];
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
      $filename = $file->getClientOriginalName();
      $tmp_name = 'temporary://' . $filename;
      $this->fileSystem->copy($file->getPathname(), $tmp_name, FileExists::Replace);
      // Create actual file entities.
      $file = $this->entityTypeManager()->getStorage('file')->create([
        'uid' => $this->currentUser()->id(),
        'filename' => $filename,
        'uri' => $tmp_name,
        'status' => 0,
      ]);
      $file->save();
      $binary = file_get_contents($tmp_name);
      if ($binary === FALSE) {
        return new JsonResponse([
          'status' => FALSE,
          'message' => 'An error occurred reading the uploaded file.',
        ]);
      }

      $image_files[] = new ImageFile($binary, $mime_type, $filename);
    }

    if (empty($prompt['messages'])) {
      return new JsonResponse([
        'status' => FALSE,
        'message' => 'No prompt provided',
      ]);
    }
    $task_message = array_pop($prompt['messages']);
    $agent->setChatInput(new ChatInput([
      new ChatMessage($task_message['role'], $task_message['text'], $image_files),
    ]));

    // Store the current layout in the temp store. This will be later used by
    // the ai agents.
    // @see \Drupal\xb_ai\Plugin\AiFunctionCall\GetCurrentLayout.
    $current_layout = $prompt['current_layout'] ?? '';
    if (!empty($current_layout)) {
      $this->xbAiTempStore->setData(XbAiTempStore::CURRENT_LAYOUT_KEY, Json::encode($current_layout));
    }

    $task = $prompt['messages'];
    $messages = [];
    foreach ($task as $message) {
      if (!empty($message['files'])) {
        $images = [];
        foreach ($message['files'] as $file_info) {
          if (!empty($file_info['src'])) {
            $binary = @file_get_contents($file_info['src']);
            preg_match('/^data:(.*?);base64,/', $file_info['src'], $matches);
            $mime_type = $matches[1] ?? '';
            if ($binary !== FALSE) {
              $images[] = new ImageFile($binary, $mime_type, 'temp');
            }
          }
        }
        // The text is intentionally kept empty while setting it in comments
        // so that the AI only takes the image as a context/history for the
        // next prompt not any text related to it.
        $messages[] = new ChatMessage($message['role'], '', $images);
        break;
      }
      else {
        $messages[] = new ChatMessage($message['role'] === 'user' ? 'user' : 'assistant', $message['text']);
      }
    }
    $agent->setChatHistory($messages);
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
