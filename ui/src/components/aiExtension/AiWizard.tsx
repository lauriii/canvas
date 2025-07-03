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
import { useCreateCodeComponentMutation } from '@/services/componentAndLayout';
import { getDrupalSettings } from '@/utils/drupal-globals';
import { Flex, Text } from '@radix-ui/themes';
import type { CodeComponent } from '@/types/CodeComponent';
import { setPageData } from '@/features/pageData/pageDataSlice';
import {
  selectModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';

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

const metadataHandler = {
  canHandle: (msg: any) => 'metadata' in msg && msg.metadata,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const value = JSON.parse(message.metadata);
    dispatch(setUpdatePreview(true));
    dispatch(
      setPageData({ 'description[0][value]': value.metatag_description }),
    );
  },
};

const messageHandlers = [
  cssStructureHandler,
  jsStructureHandler,
  componentStructureHandler,
  propsMetadataHandler,
  metadataHandler,
];

function getHandlersForMessage(message: any) {
  return messageHandlers.filter((handler) => handler.canHandle(message));
}

const AiWizard = () => {
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

  // Fetch CSRF token on mount.
  useEffect(() => {
    const fetchToken = async () => {
      try {
        const response = await fetch('/admin/api/xb/token', {
          credentials: 'same-origin',
        });
        const token = await response.text();
        setCsrfToken(token);
      } catch (error) {
        console.error('Failed to fetch CSRF token:', error);
      }
    };

    fetchToken();
  }, []);

  // Function to handle message response from AI.
  const receiveMessage = useCallback(
    async (message: any) => {
      try {
        const context = { message, dispatch, createCodeComponent, navigate };
        const handlers = getHandlersForMessage(message);
        for (const handler of handlers) {
          await handler.handle(context);
        }
        return { text: message.message };
      } catch (error) {
        return { text: 'Something went wrong. Please try again.' };
      }
    },
    [dispatch, createCodeComponent, navigate],
  );

  // Set the AI response handler once token is ready.
  useEffect(() => {
    if (chatElementRef.current && csrfToken) {
      // @todo figure out how to fix the issue of passing the
      //  selected components without refresh in https://www.drupal.org/i/3529328.
      chatElementRef.current.responseInterceptor = (response: any) => {
        return receiveMessage(response);
      };
    }
  }, [csrfToken, receiveMessage]);

  return csrfToken ? (
    <Flex
      direction="column"
      align="stretch"
      gap="4"
      className={styles.aiWizard}
    >
      <Flex direction="column">
        <Text size="3" weight="bold" color="blue">
          Hello 👋
        </Text>
        <Text size="2" weight="bold">
          How can I help you today?
        </Text>
      </Flex>
      <DeepChat
        ref={chatElementRef}
        requestBodyLimits={{
          maxMessages: 5,
        }}
        connect={{
          url: '/admin/api/xb/ai',
          method: 'POST',
          headers: {
            'X-CSRF-Token': csrfToken,
          },
          additionalBodyProps: {
            entity_type: drupalSettings.xb.entityType,
            entity_id: drupalSettings.xb.entity,
            selected_component: codeComponentName,
            layout: textPropsMapString,
          },
        }}
        textInput={{
          placeholder: { text: 'Build me a ...' },
          styles: {
            container: {
              height: '167px',
              width: '100%',
              padding: '8px',
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
                color: '#1C2024',
                fontSize: '14px',
                fontWeight: '400',
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
        "
      />
    </Flex>
  ) : (
    <p>Loading Experience Builder AI...</p>
  );
};

export default AiWizard;
