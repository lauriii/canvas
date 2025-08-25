/**
 * ⚠️ This is highly experimental and *will* be refactored.
 */
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useEffect, useRef, useState, useCallback } from 'react';
import { DeepChat } from 'deep-chat-react';
import styles from './AiWizard.module.css';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';
import { useNavigate } from 'react-router-dom';
import {
  useCreateCodeComponentMutation,
  useGetComponentsQuery,
} from '@/services/componentAndLayout';
import { getDrupalSettings } from '@/utils/drupal-globals';
import { Box, Flex, Text } from '@radix-ui/themes';
import type { CodeComponent } from '@/types/CodeComponent';
import {
  selectPageData,
  updatePageDataExternally,
} from '@/features/pageData/pageDataSlice';
import {
  selectModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import type { XBComponent } from '@/types/Component';
import type {
  LayoutModelSliceState,
  ComponentNode,
} from '@/features/layout/layoutModelSlice';
import AiWelcome from '@assets/icons/ai-welcome.svg?react';
import fixtureProps from '../../../../modules/xb_ai/src/PropsSchema.json';

const simplePropertyHandler = (
  property: string,
  propKey: keyof CodeComponent,
) => ({
  canHandle: (msg: any) => property in msg && msg[property],
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    dispatch(setCodeComponentProperty([propKey, message[property]]));
  },
});

const cssStructureHandler = simplePropertyHandler(
  'css_structure',
  'sourceCodeCss',
);
const jsStructureHandler = simplePropertyHandler(
  'js_structure',
  'sourceCodeJs',
);

const componentStructureHandler = {
  canHandle: (msg: any) =>
    'component_structure' in msg && msg.component_structure,
  handle: async ({
    message,
    createCodeComponent,
    navigate,
  }: {
    message: any;
    createCodeComponent: any;
    navigate: any;
  }) => {
    const component = message.component_structure;
    await createCodeComponent(component).unwrap();
    navigate(`/code-editor/component/${component.machineName}`);
  },
};

const propsMetadataHandler = {
  canHandle: (msg: any) => 'props_metadata' in msg && msg.props_metadata,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const parsedProps = JSON.parse(message.props_metadata);
    dispatch(setCodeComponentProperty(['props', parsedProps]));
  },
};

const createdContentHandler = {
  canHandle: (msg: any) => 'created_content' in msg && msg.created_content,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const value = message.created_content;
    dispatch(setUpdatePreview(true));
    dispatch(updatePageDataExternally({ 'title[0][value]': value }));
  },
};

const editContentHandler = {
  canHandle: (msg: any) => 'refined_text' in msg && msg.refined_text,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const value = message.refined_text;
    dispatch(setUpdatePreview(true));
    dispatch(updatePageDataExternally({ 'title[0][value]': value }));
  },
};
const metadataHandler = {
  canHandle: (msg: any) => 'metadata' in msg && msg.metadata,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const value = message.metadata;
    dispatch(setUpdatePreview(true));
    dispatch(
      updatePageDataExternally({
        'description[0][value]': value.metatag_description,
      }),
    );
  },
};

// Helper to delay the placement of components.
const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const operationsHandler = {
  canHandle: (msg: any) => 'operations' in msg && msg.operations,
  handle: async ({
    message,
    dispatch,
    availableComponents,
    layoutUtils,
    componentSelectionUtils,
    navigate,
  }: {
    message: any;
    dispatch: any;
    availableComponents: any;
    layoutUtils: any;
    componentSelectionUtils: any;
    navigate: any;
  }) => {
    // Logic for placing components (SDCs/Blocks/Code components) to the canvas.
    for (const op of message.operations) {
      // Only 'Add' operation is supported for now.
      if (
        op.operation === 'ADD' &&
        op.components &&
        Array.isArray(op.components) &&
        availableComponents
      ) {
        for (const component of op.components) {
          if (
            component.id &&
            component.nodePath &&
            availableComponents[component.id]
          ) {
            let componentToUse: XBComponent = availableComponents[component.id];
            if (component.fieldValues) {
              // Create a copy of the component to set the field values
              // as it is not possible to update the original component
              // object directly.
              const componentCopy = structuredClone(
                availableComponents[component.id],
              );
              // Set the values to the props.
              Object.entries(component.fieldValues).forEach(
                ([fieldName, value]) => {
                  const propSource = (componentCopy as any).propSources?.[
                    fieldName
                  ];
                  if (propSource) {
                    // Ensure default_values exists
                    if (!propSource.default_values) {
                      propSource.default_values = {};
                    }
                    if (propSource.jsonSchema.format === 'uri-reference') {
                      propSource.default_values.source = [value];
                    } else {
                      // Ensure source exists and is an array
                      if (!Array.isArray(propSource.default_values.source)) {
                        propSource.default_values.source = [{}];
                      }
                      // Ensure the first element exists
                      if (!propSource.default_values.source[0]) {
                        propSource.default_values.source[0] = {};
                      }
                      // Now set the value
                      propSource.default_values.source[0].value = value;
                      // @todo Revisit once https://www.drupal.org/node/3538576 is in.
                      propSource.default_values.resolved = value;
                    }
                  }
                },
              );
              componentToUse = componentCopy;
            }
            dispatch(
              layoutUtils.addNewComponentToLayout(
                {
                  to: component.nodePath,
                  component: componentToUse,
                },
                componentSelectionUtils.setSelectedComponent,
              ),
            );
            // Wait for a second before placing the next component, for the UI to render the component.
            await delay(1000);
          }
        }
      }
    }
    // Redirect to /editor.
    navigate('/editor');
  },
};

const messageHandlers = [
  createdContentHandler,
  editContentHandler,
  cssStructureHandler,
  jsStructureHandler,
  componentStructureHandler,
  propsMetadataHandler,
  metadataHandler,
  operationsHandler,
];

function getHandlersForMessage(message: any) {
  return messageHandlers.filter((handler) => handler.canHandle(message));
}

const SESSION_STORAGE_KEY = 'aiWizardChatHistory';

function loadChatHistory() {
  const data = sessionStorage.getItem(SESSION_STORAGE_KEY);
  if (!data) return [];
  try {
    return JSON.parse(data);
  } catch {
    return [];
  }
}

const AiWizard = () => {
  const pageData = useAppSelector(selectPageData);
  const dispatch = useAppDispatch();
  const drupalSettings = getDrupalSettings();
  const chatElementRef = useRef<any>(null);
  const [csrfToken, setCsrfToken] = useState<string | null>(null);
  const [createCodeComponent] = useCreateCodeComponentMutation();
  const navigate = useNavigate();
  const codeComponentName = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const model = useAppSelector(selectModel);
  const textPropsMap = Object.fromEntries(
    Object.entries(model).map(([uuid, comp]) => [uuid, comp.resolved]),
  );
  const textPropsMapString = JSON.stringify(textPropsMap);
  const [, setChatHistory] = useState(() => loadChatHistory());
  let isComponentRendered = false;
  const welcomeTextRef = useRef<HTMLSpanElement>(null);

  // Create a ref to store current values for Deep Chat's connect prop.
  // Accessing these ensures we're working with fresh values even after the Deep
  // Chat component has been mounted.
  const currentValuesRef = useRef({
    codeComponentName,
    textPropsMapString,
    pageData,
  });

  // Update the ref whenever tracked values change.
  useEffect(() => {
    currentValuesRef.current = {
      codeComponentName,
      textPropsMapString,
      pageData,
    };
  }, [codeComponentName, textPropsMapString, pageData]);
  // Access layoutUtils and componentSelectionUtils from drupalSettings.xb
  const layoutUtils = drupalSettings.xb?.layoutUtils as any;
  const componentSelectionUtils = drupalSettings.xb
    ?.componentSelectionUtils as any;

  // Get the current layout, selected component, and available components from Redux state
  const theLayoutModel = useAppSelector(
    (state) => state?.layoutModel?.present as LayoutModelSliceState,
  );
  const layoutModelRef = useRef(theLayoutModel);
  useEffect(() => {
    layoutModelRef.current = theLayoutModel;
  }, [theLayoutModel]);
  const selectedComponent = useAppSelector(
    (state) => state.ui.selection.items[0],
  );
  const { data: availableComponents } = useGetComponentsQuery();
  const componentsRef = useRef<any>(null);

  useEffect(() => {
    if (availableComponents && !componentsRef.current) {
      componentsRef.current = availableComponents;
    }
  }, [availableComponents]);

  // Helper to transform the current layout into a JSON representation.
  const transformLayout = () => {
    const theLayout = layoutModelRef.current;
    if (!theLayout?.layout) return null;
    const result: any = { layout: {} };
    theLayout.layout.forEach((region, regionIndex) => {
      result.layout[region.id] = {
        nodePathPrefix: [regionIndex],
        components: [],
      };
      result.layout[region.id].components = processComponents(
        region.components,
      );
    });
    return result;
  };

  // Helper to recursively process components
  const processComponents = (
    components: ComponentNode[] | undefined,
    parentPath: string[] = [],
  ): any[] => {
    if (!components) return [];
    return components.map((component) => {
      let nodePath: number[] | null = null;
      try {
        nodePath = layoutUtils.findNodePathByUuid(
          theLayoutModel.layout,
          component.uuid,
        );
      } catch (e) {
        console.warn(`Could not find nodePath for ${component.uuid}`);
      }
      const transformedComponent: any = {
        name: component.type?.split('@')[0],
        uuid: component.uuid,
        nodePath: nodePath,
      };
      // Handle slots if they exist
      if (component.slots && component.slots.length > 0) {
        transformedComponent.slots = {};
        component.slots.forEach((slot) => {
          transformedComponent.slots[slot.id] = {
            components: processComponents(slot.components, [
              ...parentPath,
              component.uuid,
            ]),
          };
        });
      }
      return transformedComponent;
    });
  };

  // Fetch CSRF token on mount.
  useEffect(() => {
    const fetchToken = async () => {
      try {
        const response = await fetch('/admin/api/xb/token', {
          credentials: 'same-origin',
        });
        if (!response.ok) {
          throw new Error(
            `HTTP error: ${response.status} ${response.statusText}`,
          );
        }
        const token = await response.text();
        setCsrfToken(token);
      } catch (error) {
        console.error('Failed to fetch CSRF token:', error);
        const event = new CustomEvent('xb-csrf-token-error', {
          detail: {
            error,
            time: new Date(),
          },
        });
        window.dispatchEvent(event);
      }
    };

    fetchToken();
  }, []);

  // Function to handle message response from AI.
  const receiveMessage = useCallback(
    async (message: any) => {
      try {
        const handlers = getHandlersForMessage(message);
        for (const handler of handlers) {
          // If the handler is operationsHandler, do not await it here.
          if (handler === operationsHandler) {
            setTimeout(() => {
              // Do the async work in the background.
              operationsHandler.handle({
                message,
                dispatch,
                availableComponents: componentsRef.current,
                layoutUtils,
                componentSelectionUtils,
                navigate,
              });
            }, 0);
          } else {
            await handler.handle({
              message,
              dispatch,
              createCodeComponent,
              navigate,
              availableComponents: componentsRef.current,
              layoutUtils,
              componentSelectionUtils,
            });
          }
        }
        return { text: message.message };
      } catch (error) {
        console.error('AI response processing failed:', error);
        return {
          text: 'An error occurred while processing your request. Please try again.',
          role: 'error',
        };
      }
    },
    [
      dispatch,
      createCodeComponent,
      navigate,
      layoutUtils,
      componentSelectionUtils,
    ],
  );

  useEffect(() => {
    const chatEl = chatElementRef.current;
    if (!chatEl) return;
    const handler = (event: { detail: { message: any; isHistory: any } }) => {
      const { message, isHistory } = event.detail;
      if (!isHistory) {
        if (welcomeTextRef.current) {
          welcomeTextRef.current.style.display = 'none';
        }
        const oldHistoryStr = sessionStorage.getItem(SESSION_STORAGE_KEY);
        const oldHistory = oldHistoryStr ? JSON.parse(oldHistoryStr) : [];
        const updated = [...oldHistory, message];
        sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(updated));
      }
    };
    chatEl.addEventListener('message', handler);
    return () => {
      chatEl.removeEventListener('message', handler);
    };
  }, [csrfToken]);

  // Handle text input changes to enable/disable submit button.
  const handleTextInput = () => {
    const chatEl = chatElementRef.current;
    const deepChatEl = document.querySelector('deep-chat') as any;
    const inputText =
      deepChatEl?.shadowRoot?.querySelector('#text-input')?.textContent || '';
    if (inputText.trim().length > 0) {
      chatEl.disableSubmitButton(false);
    } else {
      chatEl.disableSubmitButton();
    }
  };

  return (
    csrfToken && (
      <Flex
        direction="column"
        align="stretch"
        gap="4"
        className={styles.aiWizard}
        onKeyDown={(e) => {
          e.stopPropagation();
        }}
      >
        <Flex direction="column" align="center">
          <Flex align="center">
            <AiWelcome />
          </Flex>
          <Flex direction="row" align="center" gap="0">
            <Box className={styles.aiWizardTitleContainer}>
              <Text className={styles.aiWizardTitle}>
                Experience Builder AI
              </Text>
              <Text className={styles.aiWizardBeta}>Beta</Text>
            </Box>
          </Flex>
          <Text ref={welcomeTextRef} className={styles.aiWizardSubtitle}>
            Hello, how can I help you today?
          </Text>
        </Flex>
        <DeepChat
          ref={chatElementRef}
          history={loadChatHistory()}
          images={{
            files: {
              acceptedFormats: '.jpg, .png, .jpeg',
              // For now we just support uploading 1 image at a time
              // if the user tries to upload another image the already
              // added image is replaced.
              maxNumberOfFiles: 1,
            },
            button: {
              position: 'inside-left',
              styles: {
                container: {
                  default: {
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    marginLeft: '8px',
                    marginBottom: '12px',
                    backgroundColor: '#F0F0F3',
                  },
                },
                svg: {
                  content: `
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect width="16" height="16" fill="white" fill-opacity="0.01"/>
                  <path fill-rule="evenodd" clip-rule="evenodd" d="M8.53324 2.93324C8.53324 2.63869 8.29445 2.3999 7.9999 2.3999C7.70535 2.3999 7.46657 2.63869 7.46657 2.93324V7.46657H2.93324C2.63869 7.46657 2.3999 7.70535 2.3999 7.9999C2.3999 8.29445 2.63869 8.53324 2.93324 8.53324H7.46657V13.0666C7.46657 13.3611 7.70535 13.5999 7.9999 13.5999C8.29445 13.5999 8.53324 13.3611 8.53324 13.0666V8.53324H13.0666C13.3611 8.53324 13.5999 8.29445 13.5999 7.9999C13.5999 7.70535 13.3611 7.46657 13.0666 7.46657H8.53324V2.93324Z" fill="#60646C"/>
                  </svg>
                `,
                },
              },
            },
          }}
          // @todo Revisit once https://www.drupal.org/node/3528730 is in.
          requestBodyLimits={{
            maxMessages: 3,
          }}
          connect={{
            // Defining a handler instead of an object to ensure we can work with
            // up-to-date data. Otherwise `connect.additionalBodyProps` captures
            // the values at the time the component was mounted.
            // @see https://deepchat.dev/docs/connect/#Handler
            handler: async (body, signals) => {
              try {
                const hasFiles = body instanceof FormData;
                let requestBody: FormData | string;
                let headers: Record<string, string> = {
                  'X-CSRF-Token': csrfToken,
                };

                if (hasFiles) {
                  requestBody = body as FormData;
                  requestBody.append(
                    'entity_type',
                    drupalSettings.xb.entityType,
                  );
                  requestBody.append('entity_id', drupalSettings.xb.entity);
                  requestBody.append(
                    'selected_component',
                    currentValuesRef.current.codeComponentName,
                  );
                  requestBody.append(
                    'layout',
                    currentValuesRef.current.textPropsMapString,
                  );
                } else {
                  requestBody = JSON.stringify({
                    ...body,
                    entity_type: drupalSettings.xb.entityType,
                    entity_id: drupalSettings.xb.entity,
                    selected_component:
                      currentValuesRef.current.codeComponentName,
                    layout: currentValuesRef.current.textPropsMapString,
                    active_component_uuid: selectedComponent ?? '',
                    current_layout: transformLayout(),
                    derived_proptypes: fixtureProps,
                    page_title:
                      currentValuesRef.current.pageData['title[0][value]'],
                    page_description:
                      currentValuesRef.current.pageData[
                        'description[0][value]'
                      ],
                  });
                  headers['Content-Type'] = 'application/json';
                }
                const response = await fetch('/admin/api/xb/ai', {
                  method: 'POST',
                  headers,
                  body: requestBody,
                });
                if (!response.ok) {
                  throw new Error(`HTTP error. Status: ${response.status}`);
                }

                const data = await response.json();
                const processedMessage = await receiveMessage(data);
                signals.onResponse(processedMessage);
              } catch (error) {
                console.error('AI request failed:', error);
                signals.onResponse({
                  text: 'An error occurred while processing your request. Please try again.',
                  role: 'error',
                });
              }
              setTimeout(() => {
                chatElementRef.current?.disableSubmitButton();
              }, 0);
            },
          }}
          onInput={handleTextInput}
          onComponentRender={() => {
            if (!isComponentRendered) {
              chatElementRef.current.clearMessages();
              sessionStorage.removeItem(SESSION_STORAGE_KEY);
              setChatHistory([]);
              chatElementRef.current.disableSubmitButton();
              isComponentRendered = true;
            }
          }}
          textInput={{
            placeholder: { text: 'Build me a ...' },
            styles: {
              text: {
                padding: '16px',
              },
              container: {
                height: '167px',
                width: '100%',
                padding: '0 0 40px 0',
              },
            },
          }}
          style={{
            width: '283px',
            height: '100%',
            border: 'none',
          }}
          messageStyles={{
            default: {
              shared: {
                bubble: {
                  width: '100%',
                  maxWidth: '100%',
                  color: 'var(--black-12)',
                  fontSize: '14px',
                  fontWeight: '400',
                  lineHeight: '1.26',
                  padding: '8px',
                  textAlign: 'left',
                },
              },
              user: {
                bubble: {
                  backgroundColor: '#F0F0F3',
                },
              },
              ai: {
                bubble: {
                  backgroundColor: 'white',
                },
              },
              error: {
                bubble: {
                  color: '#FF3333',
                },
              },
            },
          }}
          submitButtonStyles={{
            disabled: {
              container: {
                default: {
                  display: 'none',
                },
              },
            },
            submit: {
              container: {
                default: {
                  display: 'inherit',
                  marginRight: '8px',
                  marginBottom: '12px',
                },
              },
              svg: {
                content: `
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M0 3C0 1.34315 1.34315 0 3 0H21C22.6569 0 24 1.34315 24 3V21C24 22.6569 22.6569 24 21 24H3C1.34315 24 0 22.6569 0 21V3Z" fill="#0090FF"/>
                  <rect width="16" height="16" transform="translate(4 4)" fill="white" fill-opacity="0.01"/>
                  <path fill-rule="evenodd" clip-rule="evenodd" d="M11.6228 6.28952C11.8311 6.08123 12.1688 6.08123 12.3771 6.28952L16.6438 10.5562C16.852 10.7645 16.852 11.1021 16.6438 11.3104C16.4355 11.5187 16.0978 11.5187 15.8894 11.3104L12.5333 7.95422V17.3333C12.5333 17.6278 12.2945 17.8666 12 17.8666C11.7054 17.8666 11.4666 17.6278 11.4666 17.3333V7.95422L8.11041 11.3104C7.90213 11.5187 7.56444 11.5187 7.35617 11.3104C7.14788 11.1021 7.14788 10.7645 7.35617 10.5562L11.6228 6.28952Z" fill="white"/>
                </svg>
              `,
              },
            },
            stop: {
              svg: {
                content: `
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M0 3C0 1.34315 1.34315 0 3 0H21C22.6569 0 24 1.34315 24 3V21C24 22.6569 22.6569 24 21 24H3C1.34315 24 0 22.6569 0 21V3Z" fill="#0090FF"/>
                  <rect width="16" height="16" transform="translate(4 4)" fill="white" fill-opacity="0.01"/>
                  <path fill-rule="evenodd" clip-rule="evenodd" d="M6.1333 7.19997C6.1333 6.61087 6.61087 6.1333 7.19997 6.1333H16.8C17.3891 6.1333 17.8666 6.61087 17.8666 7.19997V16.8C17.8666 17.3891 17.3891 17.8666 16.8 17.8666H7.19997C6.61087 17.8666 6.1333 17.3891 6.1333 16.8V7.19997ZM16.8 7.19997H7.19997V16.8H16.8V7.19997Z" fill="white"/>
                </svg>
              `,
              },
            },
          }}
          auxiliaryStyle="
          #chat-view:has(#messages:empty) {
            display: block;
          }
          #input:has(#file-attachment-container[style*='display: block']) {
            margin-top: 40px;
          }
          .text-message h1 {
            font-size: var(--font-size-5);
          }
          .text-message h2 {
            font-size: var(--font-size-4);
          }
          .text-message h3 {
            font-size: var(--font-size-3);
          }
          .text-message h4 {
            font-size: var(--font-size-2);
          }
          .text-message h5 {
            font-size: var(--font-size-1);
          }
        "
        />
        <Box className={styles.aiWizardLegalContainer}>
          <Text>
            These responses are generated by AI, which can make mistakes.
          </Text>
        </Box>
      </Flex>
    )
  );
};

export default AiWizard;
