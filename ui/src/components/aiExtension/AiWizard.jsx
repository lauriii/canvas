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
        // @todo Revisit this in https://www.drupal.org/i/3529968.
        if ('css_structure' in message && message.css_structure) {
          dispatch(
            setCodeComponentProperty([
              'source_code_css',
              message.css_structure,
            ]),
          );
        }
        if ('js_structure' in message && message.js_structure) {
          dispatch(
            setCodeComponentProperty(['source_code_js', message.js_structure]),
          );
        }
        if ('component_structure' in message && message.component_structure) {
          const component = message.component_structure;
          await createCodeComponent(component).unwrap();
          navigate(`/code-editor/component/${component.machineName}`);
        }
        if ('props_metadata' in message && message.props_metadata) {
          const parsedProps = JSON.parse(message.props_metadata);
          dispatch(setCodeComponentProperty(['props', parsedProps]));
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
