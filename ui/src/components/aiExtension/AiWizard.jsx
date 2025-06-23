/**
 * ⚠️ This is highly experimental and *will* be refactored.
 */
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useEffect, useRef, useState, useCallback } from 'react';
import { DeepChat } from 'deep-chat-react';
import './AiWizard.css';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';
import { useNavigate } from 'react-router-dom';
import { useCreateCodeComponentMutation } from '@/services/componentAndLayout';
import { getDrupalSettings } from '@/utils/drupal-globals';

const simplePropertyHandler = (property, propKey) => ({
  canHandle: (msg) => property in msg && msg[property],
  handle: async ({ message, dispatch }) => {
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
  canHandle: (msg) => 'component_structure' in msg && msg.component_structure,
  handle: async ({ message, createCodeComponent, navigate }) => {
    const component = message.component_structure;
    await createCodeComponent(component).unwrap();
    navigate(`/code-editor/component/${component.machineName}`);
  },
};

const propsMetadataHandler = {
  canHandle: (msg) => 'props_metadata' in msg && msg.props_metadata,
  handle: async ({ message, dispatch }) => {
    const parsedProps = JSON.parse(message.props_metadata);
    dispatch(setCodeComponentProperty(['props', parsedProps]));
  },
};

const messageHandlers = [
  cssStructureHandler,
  jsStructureHandler,
  componentStructureHandler,
  propsMetadataHandler,
];

function getHandlersForMessage(message) {
  return messageHandlers.filter((handler) => handler.canHandle(message));
}

const AiWizard = () => {
  const dispatch = useAppDispatch();
  const drupalSettings = getDrupalSettings();
  const chatElementRef = useRef(null);
  const [csrfToken, setCsrfToken] = useState(null);
  const [createCodeComponent] = useCreateCodeComponentMutation();
  const navigate = useNavigate();
  const codeComponentName = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );

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
    async (message) => {
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
      chatElementRef.current.responseInterceptor = (response) => {
        return receiveMessage(response);
      };
    }
  }, [csrfToken, receiveMessage]);

  return csrfToken ? (
    <div className="ai-wizard">
      <div className="ai-header">
        <p>
          <strong className="blue-text">Hello 👋</strong>
        </p>
        <h3>How can I help you today?</h3>
      </div>
      <DeepChat
        ref={chatElementRef}
        style={{
          width: '220px',
          height: '550px',
        }}
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
          },
        }}
      />
    </div>
  ) : (
    <p>Loading Experience Builder AI...</p>
  );
};

export default AiWizard;
