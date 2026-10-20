/**
 * ⚠️ This is highly experimental and *will* be refactored.
 *
 * Development variant of AiWizard. It drives the canvas_dev_ai endpoint
 * (/admin/api/canvas/ai-dev) as a client-side hop loop: the agent pauses after
 * each tool decision, and the turn is re-POSTed under one request_id until it
 * reports it is finished. Each hop's narration is rendered into a single
 * progress message, and the answer follows it on the final hop. Every turn
 * of one chat session is sent under one conversation_id, under which the
 * backend keeps the agent's own history between turns as checkpoints keyed
 * by request_id. Each turn names the last turn this chat accepted as
 * last_successful_request_id, and that is the checkpoint the backend
 * resumes: a turn the user stopped is never named, so whatever it left on
 * the server is never resumed. Rendered instead of AiWizard when
 * drupalSettings.canvas.aiDevMode is set by the canvas_dev_ai module.
 */
import { memo, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { DeepChat } from 'deep-chat-react';
import { useNavigate, useParams } from 'react-router';
import AiWelcome from '@assets/icons/ai-welcome.svg?react';
import { Box, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector, useAppStore } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
  setRequiredFromNames,
} from '@/features/code-editor/codeEditorSlice';
import {
  deserializeProps,
  deserializeSlots,
  serializeRequired,
} from '@/features/code-editor/utils/utils';
import { FORM_TYPES } from '@/features/form/constants';
import { setFieldValue } from '@/features/form/formStateSlice';
import {
  selectModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import {
  selectPageData,
  updatePageDataExternally,
} from '@/features/pageData/pageDataSlice';
import {
  useCreateCodeComponentMutation,
  useGetComponentsQuery,
} from '@/services/componentAndLayout';
import { getBaseUrl, getDrupalSettings } from '@/utils/drupal-globals';

import fixtureProps from '../../../../modules/canvas_ai/src/PropsSchema.json';
import ActiveToolPill from './ActiveToolPill';
import AiToolSelector from './AiToolSelector';
import { buildCurrentLayout } from './currentLayout';
import { progressToHtml, removeMediaFields } from './placementUtils';

import type { CustomButton } from 'deep-chat/dist/types/customButton';
import type { LayoutModelSliceState } from '@/features/layout/layoutModelSlice';
import type { CodeComponent } from '@/types/CodeComponent';
import type { CanvasComponent } from '@/types/Component';
import type { ProgressStatus } from './placementUtils';

import styles from './AiWizard.module.css';

// A separate database from the production AiWizard so mocked dev
// conversations do not leak into the production wizard's history.
const DB_NAME = 'aiWizardDevDB';
const STORE_NAME = 'chatHistory';
const KEY = 'history';

const withStore = (
  type: IDBTransactionMode,
  callback: (store: IDBObjectStore) => void,
): Promise<void> => {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () =>
      request.result.createObjectStore(STORE_NAME);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const tx = db.transaction(STORE_NAME, type);
      callback(tx.objectStore(STORE_NAME));
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    };
  });
};

const db = {
  get: (): Promise<any[]> =>
    new Promise((resolve) => {
      let req: IDBRequest;
      withStore('readonly', (store) => {
        req = store.get(KEY);
      }).then(() => resolve(req?.result || []));
    }),
  set: (data: any[]) => withStore('readwrite', (store) => store.put(data, KEY)),
  clear: () => withStore('readwrite', (store) => store.clear()),
};

const createHistoryStore = () => {
  let history: any[] = [];
  const subscribers = new Set<() => void>();

  db.get().then((initialHistory) => {
    history = initialHistory;
    subscribers.forEach((callback) => callback());
  });

  return {
    addMessage(message: any) {
      history = [...history, message];
      db.set(history);
    },
    clearHistory() {
      history = [];
      db.clear();
      subscribers.forEach((callback) => callback());
    },
    // @todo No subscribers remain since the history prop is frozen at mount; remove subscribe and the subscribers Set in https://git.drupalcode.org/project/canvas/-/work_items/3591731.
    subscribe(callback: () => void) {
      subscribers.add(callback);
      return () => subscribers.delete(callback);
    },
    getSnapshot() {
      return history;
    },
  };
};
const historyStore = createHistoryStore();

// Runs a message mutation, keeping the transcript pinned to the bottom only
// when it already was. Measures before the mutation, since adding to the list
// changes its scroll height. deep-chat keeps the list in its shadow root.
const withAutoScroll = (chatEl: any, mutate: () => void) => {
  const container = chatEl.shadowRoot?.querySelector('#messages');
  const pinned = container
    ? container.scrollHeight - container.scrollTop - container.clientHeight < 5
    : true;
  mutate();
  if (container && pinned) {
    setTimeout(() => {
      container.scrollTop = container.scrollHeight;
    }, 0);
  }
};

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
    // Deserialize from Record format to Array format.
    const deserializedProps = deserializeProps(parsedProps);
    dispatch(setCodeComponentProperty(['props', deserializedProps]));
  },
};

const slotsMetadataHandler = {
  canHandle: (msg: any) => 'slots_metadata' in msg && msg.slots_metadata,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const parsedSlots = JSON.parse(message.slots_metadata);
    // Deserialize from Record format to Array format.
    const deserializedSlots = deserializeSlots(parsedSlots);
    dispatch(setCodeComponentProperty(['slots', deserializedSlots]));
  },
};

const requiredPropsHandler = {
  canHandle: (msg: any) =>
    'required_props' in msg && Array.isArray(msg.required_props),
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    dispatch(setRequiredFromNames(message.required_props));
  },
};

const canvasPageDataHandler = {
  canHandle: (msg: any) => 'canvas_page_data' in msg && msg.canvas_page_data,
  handle: async ({ message, dispatch }: { message: any; dispatch: any }) => {
    const updates = message.canvas_page_data;
    if (Object.keys(updates).length > 0) {
      // Keep the formState slice in sync, mirroring what enhancedOnChange does
      // for a real keystroke (see docs/component-and-entity-forms.md). Without
      // this the formState slice retains the stale value, and the next field
      // change or AJAX event rebuilds pageData from it
      // (createPageDataFormStateHandler / PageDataForm's AJAX listener),
      // clobbering the AI value before it is auto-saved or published.
      // @todo Multi-value fields are written as raw arrays here, bypassing the
      //   multi-select normalization in createPageDataFormStateHandler. Only
      //   single-value fields (title, description) are set today. Normalize
      //   when extending to multi-value fields, see
      //   https://www.drupal.org/i/3587609.
      Object.entries(updates).forEach(([fieldName, value]) => {
        dispatch(
          setFieldValue({
            formId: FORM_TYPES.ENTITY_FORM,
            fieldName,
            value,
          }),
        );
      });
      // Flag the preview/auto-save POST and mirror the value into the RHF form
      // display via useRespondToPageDataStoreUpdates.
      dispatch(setUpdatePreview(true));
      dispatch(updatePageDataExternally(updates));
    }
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
    onPlaced,
    isStopped,
  }: {
    message: any;
    dispatch: any;
    availableComponents: any;
    layoutUtils: any;
    componentSelectionUtils: any;
    onPlaced: () => void;
    isStopped: () => boolean;
  }) => {
    // Logic for placing components (SDCs/Blocks/Code components) to the editor frame.
    for (const op of message.operations) {
      // Only 'Add' operation is supported for now.
      if (
        op.operation === 'ADD' &&
        op.components &&
        Array.isArray(op.components) &&
        availableComponents
      ) {
        for (const component of op.components) {
          // The user may stop the turn while a batch is being placed, one
          // component a second. What was placed stays; the rest never is.
          if (isStopped()) {
            return;
          }
          if (component.id && availableComponents[component.id]) {
            const componentToUse: CanvasComponent =
              availableComponents[component.id];
            const componentAfterFilteringImageProps = removeMediaFields(
              componentToUse,
              component,
            );
            dispatch(
              layoutUtils.addNewComponentToLayout(
                {
                  component: componentToUse,
                  withValues: componentAfterFilteringImageProps.fieldValues,
                  to: component.nodePath,
                  // Keep the backend-assigned UUID: the place_components tool
                  // result tells the model to chain reference_uuid on it, so
                  // the layout must contain that UUID, not a fresh one.
                  predefinedUUID: component.uuid,
                },
                componentSelectionUtils.setSelectedComponent,
              ),
            );
            onPlaced();
            // Wait for a second before placing the next component, for the UI to render the component.
            await delay(1000);
          }
        }
      }
    }
  },
};

const componentUpdatesHandler = {
  canHandle: (msg: any) => 'component_updates' in msg && msg.component_updates,
  handle: async ({
    message,
    dispatch,
    layoutUtils,
    isStopped,
  }: {
    message: any;
    dispatch: any;
    layoutUtils: any;
    isStopped: () => boolean;
  }) => {
    // The edit_components tool returns prop changes keyed by component UUID;
    // apply each to the layout so the canvas reflects the edit.
    for (const [componentToUpdateId, values] of Object.entries(
      message.component_updates,
    )) {
      // A stop lands between two edits the same way it lands between two
      // placements: the rest of the batch is left unapplied.
      if (isStopped()) {
        return;
      }
      await dispatch(
        layoutUtils.updateExistingComponentValues({
          componentToUpdateId,
          values,
        }),
      );
    }
  },
};

const messageHandlers = [
  canvasPageDataHandler,
  cssStructureHandler,
  jsStructureHandler,
  componentStructureHandler,
  propsMetadataHandler,
  slotsMetadataHandler,
  requiredPropsHandler,
  operationsHandler,
  componentUpdatesHandler,
];

function getHandlersForMessage(message: any) {
  return messageHandlers.filter((handler) => handler.canHandle(message));
}

// Stable references for static DeepChat props. These are defined at module
// scope so they keep the same identity across renders, preventing DeepChat from
// resetting (and clearing the typed prompt) when the component re-renders.
const DEEP_CHAT_IMAGES = {
  files: {
    acceptedFormats: '.jpg, .png, .jpeg',
    // For now we just support uploading 1 image at a time
    // if the user tries to upload another image the already
    // added image is replaced.
    maxNumberOfFiles: 1,
  },
  button: {
    position: 'inside-start',
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
} as const;

// Setting to -1 to allow sending the entire conversation history.
// @see https://deepchat.dev/docs/connect/#requestBodyLimits
const DEEP_CHAT_REQUEST_BODY_LIMITS = {
  maxMessages: -1,
} as const;

const DEEP_CHAT_TEXT_INPUT = {
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
} as const;

const DEEP_CHAT_STYLE = {
  width: '283px',
  height: '100%',
} as const;

const DEEP_CHAT_MESSAGE_STYLES = {
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
} as const;

// The position is set explicitly: with a custom button in the inside-end
// slot, deep-chat would otherwise move the submit button, and the loader that
// replaces it while a turn runs, to outside-end, past the input's edge.
const DEEP_CHAT_SUBMIT_BUTTON_STYLES = {
  position: 'inside-end',
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
} as const;

const DEEP_CHAT_AUXILIARY_STYLE = `
  :host {
    border: none !important;
  }
  .aiProgressStatus {
    display: flex;
    align-items: center;
    padding-top: 8px;
  }
  .aiLoader, .aiCompletedIcon, .aiStoppedIcon {
    display: inline-block;
    box-sizing: border-box;
    vertical-align: middle;
    margin-right: 8px;
  }
  .aiLoader {
    width: 12px;
    height: 12px;
    border: 2px solid #8B8D98;
    border-bottom-color: transparent;
    border-radius: 50%;
    animation: ai-wizard-rotation 0.8s linear infinite;
  }
  @keyframes ai-wizard-rotation {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
  .aiCompletedIcon {
    position: relative;
    width: 12px;
    height: 12px;
    border: 1.5px solid #30A46C;
    border-radius: 50%;
  }
  .aiCompletedIcon::after {
    content: '';
    position: absolute;
    top: 0px;
    left: 3px;
    width: 3px;
    height: 6px;
    border: solid #30A46C;
    border-width: 0 1.5px 1.5px 0;
    transform: rotate(45deg);
  }
  /* A stopped turn reads as neutral, never as the green completed check: the
     agent was interrupted with work still to do. */
  .aiStoppedIcon {
    position: relative;
    width: 12px;
    height: 12px;
    border: 1.5px solid #8B8D98;
    border-radius: 50%;
  }
  .aiStoppedIcon::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 3px;
    height: 3px;
    background-color: #8B8D98;
  }
  #chat-view:has(#messages:empty) {
    display: block;
  }
  #chat-view:has(#messages:empty) #input:has(#file-attachment-container[style*='display: block']) {
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
  .custom-button {
    margin-left: 40px;
  }
  /* Clears deep-chat's default gray filter on custom button icons. */
  .custom-button-container-default > svg,
  .custom-button-container-disabled > svg {
    filter: none;
  }

` as const;

// Layout of the Tools menu trigger, shared by its states: deep-chat unsets
// one state's container styles before applying the next state's.
const DEEP_CHAT_TOOL_BUTTON_CONTAINER = {
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  marginRight: '8px',
  marginBottom: '12px',
  backgroundColor: 'var(--blue-9)',
} as const;

// Tool popup menu trigger icon: Radix's MixerHorizontalIcon
// (@radix-ui/react-icons), white on a blue-9 background. Static regardless of
// hover or whether a tool is selected.
const DEEP_CHAT_TOOL_BUTTON_STYLES = {
  container: {
    default: DEEP_CHAT_TOOL_BUTTON_CONTAINER,
  },
  svg: {
    content: `
    <svg width="16" height="16" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path fill-rule="evenodd" clip-rule="evenodd" d="M5.5 3C4.67157 3 4 3.67157 4 4.5C4 5.32843 4.67157 6 5.5 6C6.32843 6 7 5.32843 7 4.5C7 3.67157 6.32843 3 5.5 3ZM3 5C3.01671 5 3.03323 4.99918 3.04952 4.99758C3.28022 6.1399 4.28967 7 5.5 7C6.71033 7 7.71978 6.1399 7.95048 4.99758C7.96677 4.99918 7.98329 5 8 5H13.5C13.7761 5 14 4.77614 14 4.5C14 4.22386 13.7761 4 13.5 4H8C7.98329 4 7.96677 4.00082 7.95048 4.00242C7.71978 2.86009 6.71033 2 5.5 2C4.28967 2 3.28022 2.86009 3.04952 4.00242C3.03323 4.00082 3.01671 4 3 4H1.5C1.22386 4 1 4.22386 1 4.5C1 4.77614 1.22386 5 1.5 5H3ZM11.9505 10.9976C11.7198 12.1399 10.7103 13 9.5 13C8.28967 13 7.28022 12.1399 7.04952 10.9976C7.03323 10.9992 7.01671 11 7 11H1.5C1.22386 11 1 10.7761 1 10.5C1 10.2239 1.22386 10 1.5 10H7C7.01671 10 7.03323 10.0008 7.04952 10.0024C7.28022 8.8601 8.28967 8 9.5 8C10.7103 8 11.7198 8.8601 11.9505 10.0024C11.9668 10.0008 11.9833 10 12 10H13.5C13.7761 10 14 10.2239 14 10.5C14 10.7761 13.7761 11 13.5 11H12C11.9833 11 11.9668 10.9992 11.9505 10.9976ZM8 10.5C8 9.67157 8.67157 9 9.5 9C10.3284 9 11 9.67157 11 10.5C11 11.3284 10.3284 12 9.5 12C8.67157 12 8 11.3284 8 10.5Z" fill="white"/>
    </svg>
  `,
  },
} as const;

// The trigger while a turn is in progress: dimmed, and the cursor says it
// cannot be activated. The icon is inherited from the default state.
const DEEP_CHAT_TOOL_BUTTON_DISABLED_STYLES = {
  container: {
    default: {
      ...DEEP_CHAT_TOOL_BUTTON_CONTAINER,
      opacity: '0.5',
      cursor: 'not-allowed',
    },
  },
} as const;

// Layout of the stop button, shared by its states: deep-chat unsets one
// state's container styles before applying the next state's. deep-chat gives
// every inside-end button the same absolute slot, so the wider right margin
// is what places this one beside the submit button and its loader, which
// keep the 8px slot, rather than on top of them.
const DEEP_CHAT_STOP_BUTTON_CONTAINER = {
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  marginRight: '40px',
  marginBottom: '12px',
  cursor: 'pointer',
} as const;

// The stop button while a turn is running. The icon was previously an
// override for deep-chat's own stop state, which the request path never
// enters; the control that shows it is this one.
const DEEP_CHAT_STOP_BUTTON_STYLES = {
  container: {
    default: DEEP_CHAT_STOP_BUTTON_CONTAINER,
  },
  svg: {
    content: `
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M0 3C0 1.34315 1.34315 0 3 0H21C22.6569 0 24 1.34315 24 3V21C24 22.6569 22.6569 24 21 24H3C1.34315 24 0 22.6569 0 21V3Z" fill="#0090FF"/>
      <rect width="16" height="16" transform="translate(4 4)" fill="white" fill-opacity="0.01"/>
      <path fill-rule="evenodd" clip-rule="evenodd" d="M6.1333 7.19997C6.1333 6.61087 6.61087 6.1333 7.19997 6.1333H16.8C17.3891 6.1333 17.8666 6.61087 17.8666 7.19997V16.8C17.8666 17.3891 17.3891 17.8666 16.8 17.8666H7.19997C6.61087 17.8666 6.1333 17.3891 6.1333 16.8V7.19997ZM16.8 7.19997H7.19997V16.8H16.8V7.19997Z" fill="white"/>
    </svg>
  `,
  },
} as const;

// Idle: there is no turn to stop, so the button is not shown at all. The
// entry stays in the customButtons array either way — removing it would
// change the array's identity and re-render deep-chat, clearing the typed
// prompt — so the idle state is a hidden one rather than a missing button.
const DEEP_CHAT_STOP_BUTTON_HIDDEN_STYLES = {
  container: {
    default: {
      ...DEEP_CHAT_STOP_BUTTON_CONTAINER,
      display: 'none',
    },
  },
} as const;

// Closes a turn the user stopped. On the request path onResponse() is the
// only thing that ends a turn, so the turn has to answer with something.
const TURN_STOPPED_MESSAGE =
  'You interrupted the agent. What would you like to change?';

// The turn in flight: what the stop button and the unmount cleanup signal.
type Turn = {
  stopped: boolean;
  placed: boolean;
  answered: boolean;
};

// Memoized DeepChat so it only re-renders when its props change identity.
const MemoDeepChat = memo(DeepChat);

const AiWizardDev = () => {
  const dispatch = useAppDispatch();
  const drupalSettings = getDrupalSettings();
  const tools = useMemo(
    () => drupalSettings.canvas.ai?.tools ?? [],
    [drupalSettings.canvas.ai],
  );
  const chatElementRef = useRef<any>(null);
  const [csrfToken, setCsrfToken] = useState<string | null>(null);
  // Selected Tool ID, sent as the `selected_tool` request parameter. Mirrored
  // into a ref that connectHandler reads at request time, keeping it out of
  // that handler's dependencies.
  const [selectedTool, setSelectedTool] = useState<string | null>(null);
  const selectedToolRef = useRef(selectedTool);
  const [isToolSelectorOpen, setIsToolSelectorOpen] = useState(false);
  // Whether a turn is running. The Tool is fixed for the whole turn (the
  // controller rejects a hop that resolves another agent), so the Tools menu
  // trigger and the active Tool pill are disabled while this is set. Mirrored
  // into a ref for the trigger's click handler, whose identity must not
  // change (see customButtons below).
  const [isTurnInProgress, setIsTurnInProgress] = useState(false);
  const isTurnInProgressRef = useRef(isTurnInProgress);

  // Identifies the conversation, sent as `conversation_id` with every turn.
  // The backend keeps the agent history a turn ends with under it, so the
  // next turn resumes that history instead of rebuilding it from the
  // transcript. The chat is cleared when this component mounts (see
  // handleComponentRender), so a mount is a conversation.
  const [conversationId] = useState(
    () => `conv_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`,
  );
  const [createCodeComponent] = useCreateCodeComponentMutation();
  const navigate = useNavigate();
  const params = useParams();
  const codeComponentName = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const codeComponentProps = useAppSelector(
    selectCodeComponentProperty('props'),
  );
  const codeComponentRequiredIds = useAppSelector(
    selectCodeComponentProperty('required'),
  );
  // Convert ID-based required to name-based for the AI backend.
  const codeComponentRequiredProps = useMemo(
    () => serializeRequired(codeComponentProps, codeComponentRequiredIds),
    [codeComponentProps, codeComponentRequiredIds],
  );
  // The layout, page data and selection a request carries are read straight
  // from the Redux store at request time. A ref mirrors them only after React
  // re-commits, which is a render cycle too late once a hop applies changes
  // and the next hop's body is built immediately after.
  const store = useAppStore();
  // deep-chat resets its view (clearing the typed prompt and any in-progress
  // messages) whenever the `history` prop reference changes, and
  // `historyStore.addMessage` swaps the snapshot without notifying
  // subscribers, so any unrelated re-render would push a new reference in.
  // Capture the snapshot once at mount to keep the prop stable; messages are
  // still written to IndexedDB.
  // @todo Decide whether IndexedDB chat persistence is still needed now that uploads are size-limited; restore or remove it in https://git.drupalcode.org/project/canvas/-/work_items/3591731.
  const initialHistoryRef = useRef(historyStore.getSnapshot());
  const isComponentRenderedRef = useRef(false);
  const welcomeTextRef = useRef<HTMLSpanElement>(null);
  // AbortController to cancel ongoing requests when component unmounts
  const abortControllerRef = useRef<AbortController | null>(null);
  // Ends the turn in flight: the hop loop, and a batch of operations the loop
  // is applying. Aborting the fetch only rejects the request the loop is
  // awaiting, so the loop and the batch are signaled separately.
  const turnRef = useRef<Turn | null>(null);
  // The request_id of the last turn this chat accepted: its final hop landed,
  // its operations were applied without an error, and the user did not stop
  // it. Every hop of the next turn names it as last_successful_request_id,
  // the checkpoint the backend resumes. A stopped or failed turn leaves it
  // unchanged. A mount is a conversation (see conversationId), so it starts
  // empty, and the chat is only ever cleared by mounting.
  const lastSuccessfulRequestIdRef = useRef('');

  // Ref for the values that cannot change during a turn: the route params and
  // the open code component.
  const currentValuesRef = useRef({
    codeComponentName,
    params,
    codeComponentRequiredProps,
  });

  // Update the ref whenever tracked values change.
  useEffect(() => {
    currentValuesRef.current = {
      codeComponentName,
      params,
      codeComponentRequiredProps,
    };
  }, [codeComponentName, params, codeComponentRequiredProps]);
  // Access layoutUtils and componentSelectionUtils from drupalSettings.canvas
  const layoutUtils = drupalSettings.canvas?.layoutUtils as any;
  const componentSelectionUtils = drupalSettings.canvas
    ?.componentSelectionUtils as any;

  const { data: availableComponents } = useGetComponentsQuery();
  // Mirrors the latest component list, so placements see components created
  // or refreshed during the session rather than the list loaded at mount.
  const componentsRef = useRef<any>(null);
  componentsRef.current = availableComponents ?? componentsRef.current;

  // Reads the layout and its model from the same store snapshot, so the
  // structure and the prop values describe the same state.
  const transformLayout = () => {
    const state = store.getState();
    const theLayout = state?.layoutModel?.present as
      LayoutModelSliceState | undefined;
    if (!theLayout?.layout) return null;
    return buildCurrentLayout(theLayout.layout, selectModel(state));
  };

  // Cleanup effect to abort requests when component unmounts
  useEffect(() => {
    return () => {
      // Abort any ongoing request, and end the turn the way the stop button
      // does: a hop that lands after this is not applied to a chat that is
      // gone.
      const turn = turnRef.current;
      if (turn && !turn.answered) {
        turn.stopped = true;
      }
      abortControllerRef.current?.abort();
    };
  }, []);

  // Fetch CSRF token on mount.
  useEffect(() => {
    const fetchToken = async () => {
      try {
        const baseUrl = getBaseUrl();
        const response = await fetch(`${baseUrl}admin/api/canvas/token`, {
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
        const event = new CustomEvent('canvas-csrf-token-error', {
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
          // Await every handler, operationsHandler included: the hop loop
          // rebuilds current_layout from the store for the next request, so
          // placements must have landed before this promise resolves.
          await handler.handle({
            message,
            dispatch,
            createCodeComponent,
            navigate,
            availableComponents: componentsRef.current,
            layoutUtils,
            componentSelectionUtils,
            onPlaced: () => {
              if (turnRef.current) {
                turnRef.current.placed = true;
              }
            },
            // Read at every step of a batch, so a stop pressed while the
            // batch is being applied ends it there.
            isStopped: () => turnRef.current?.stopped ?? false,
          });
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
      layoutUtils,
      componentSelectionUtils,
      navigate,
      createCodeComponent,
    ],
  );

  // Keep the latest receiveMessage and csrfToken in refs so connectHandler can
  // stay referentially stable. A changing connect prop re-renders MemoDeepChat,
  // which resets DeepChat and clears the typed prompt when page metadata
  // changes.
  const receiveMessageRef = useRef(receiveMessage);
  const csrfTokenRef = useRef(csrfToken);
  useEffect(() => {
    receiveMessageRef.current = receiveMessage;
    csrfTokenRef.current = csrfToken;
    selectedToolRef.current = selectedTool;
  }, [receiveMessage, csrfToken, selectedTool]);

  // Stable handler identities for the customButtons array below. deep-chat
  // fires a custom button's onClick in its disabled state too, so the trigger
  // ignores clicks itself while a turn is in progress.
  const toggleToolSelector = useCallback(() => {
    if (isTurnInProgressRef.current) {
      return;
    }
    setIsToolSelectorOpen((open) => !open);
  }, []);
  const dismissSelectedTool = useCallback(() => setSelectedTool(null), []);

  // Ends the turn in flight. The hop the loop is awaiting is dropped and its
  // result is never applied; a batch of operations being applied stops before
  // its next component. So the page stops changing as soon as this runs, and
  // the loop guards cover the case where that hop already landed. The server
  // finishes the hop it is executing either way — the request cannot be
  // recalled — and may store what it produced, but the next turn names the
  // last accepted turn's checkpoint rather than this one's, so none of it is
  // resumed. The backend is not told about the stop.
  const stopTurn = useCallback(() => {
    // deep-chat fires onClick in the hidden state too, so an idle chat has to
    // ignore the click itself.
    const turn = turnRef.current;
    if (!isTurnInProgressRef.current || !turn) {
      return;
    }
    turn.stopped = true;
    abortControllerRef.current?.abort();
  }, []);

  const activeTool = useMemo(
    () => tools.find((tool) => tool.id === selectedTool),
    [tools, selectedTool],
  );

  // The Tools menu trigger, built only while tools are configured.
  const toolsButton = useMemo(
    (): CustomButton | null =>
      tools.length > 0
        ? {
            position: 'inside-start',
            styles: {
              button: {
                default: DEEP_CHAT_TOOL_BUTTON_STYLES,
                disabled: DEEP_CHAT_TOOL_BUTTON_DISABLED_STYLES,
              },
            },
            onClick: toggleToolSelector,
          }
        : null,
    [tools.length, toggleToolSelector],
  );

  // The stop button, next to the submit button. Its hidden state is the
  // initial one, so it is only shown once a turn is running.
  const stopButton = useMemo(
    (): CustomButton => ({
      position: 'inside-end',
      styles: {
        button: {
          default: DEEP_CHAT_STOP_BUTTON_STYLES,
          disabled: DEEP_CHAT_STOP_BUTTON_HIDDEN_STYLES,
        },
      },
      initialState: 'disabled',
      onClick: stopTurn,
    }),
    [stopTurn],
  );

  // Every dependency is fixed for the life of the chat, so the array keeps a
  // stable identity and never re-renders MemoDeepChat.
  const customButtons = useMemo(
    (): CustomButton[] =>
      toolsButton ? [toolsButton, stopButton] : [stopButton],
    [toolsButton, stopButton],
  );

  // Locks the Tools trigger and reveals the stop button for the length of the
  // turn. deep-chat adds `setState` to the buttons it was handed when it
  // renders, so this switches their states without a new customButtons
  // identity, which would re-render MemoDeepChat. A menu that is open when
  // the turn starts is closed.
  useEffect(() => {
    isTurnInProgressRef.current = isTurnInProgress;
    toolsButton?.setState?.(isTurnInProgress ? 'disabled' : 'default');
    stopButton.setState?.(isTurnInProgress ? 'default' : 'disabled');
    if (isTurnInProgress) {
      setIsToolSelectorOpen(false);
    }
  }, [isTurnInProgress, toolsButton, stopButton]);

  // Stable handler for DeepChat's connect prop. It reads up-to-date data via
  // refs (currentValuesRef, receiveMessageRef, csrfTokenRef, chatElementRef,
  // abortControllerRef), so it never needs to be recreated and the connect
  // prop keeps a stable identity.
  const connectHandler = useCallback(
    async (body: any, signals: any) => {
      const csrfToken = csrfTokenRef.current;
      // MemoDeepChat only renders once csrfToken is set, but this handler is
      // defined before that render guard, so narrow the nullable type here.
      if (!csrfToken) {
        return;
      }

      // One progress message per turn, rewritten on every hop: the controller
      // returns the narration accumulated so far, not only this hop's part.
      // Its index stays valid because the `history` prop is frozen at mount,
      // so deep-chat never re-seeds the list mid-turn.
      let progressIndex = -1;
      let narration = '';
      const renderProgress = (progress: string, status: ProgressStatus) => {
        const chatEl = chatElementRef.current;
        // The final hop's narration is empty when everything the agent said
        // was its answer, so the last non-empty one is kept and re-rendered
        // to switch the status row over to finished.
        narration = progress || narration;
        if (!chatEl || !narration) {
          return;
        }
        const html = progressToHtml(narration, status);
        withAutoScroll(chatEl, () => {
          if (progressIndex < 0) {
            chatEl.addMessage({ html, role: 'ai' });
            progressIndex = chatEl.getMessages().length - 1;
          } else {
            chatEl.updateMessage({ html }, progressIndex);
          }
        });
      };

      // The turn in flight. Declared out here so the cleanup in `finally` can
      // reach it: a stopped turn leaves the loop through the catch or through
      // the break below, and its placements need the same cleanup a turn that
      // ran to the end gets.
      let turn: Turn | null = null;

      try {
        const MAX_FILE_SIZE = drupalSettings?.canvas?.canvasAiMaxFileSize;
        const files = body instanceof FormData ? body.getAll('files') : [];
        for (const file of files) {
          if (file instanceof File && file.size > MAX_FILE_SIZE) {
            signals.onResponse({
              text: `File is too large. Maximum allowed size is ${MAX_FILE_SIZE / (1024 * 1024)}MB.`,
              role: 'error',
            });
            return;
          }
        }

        // Identifies this chat turn. The controller keys the paused agent on
        // it, so every hop of the same turn must send the same value.
        const requestId = `req_${Date.now()}_${Math.random().toString(36).substring(2, 11)}`;
        // The checkpoint this turn resumes from, fixed for the turn. The
        // backend keeps it beside the checkpoint this turn writes, so a turn
        // that is stopped or fails leaves it in place for the next one.
        const lastSuccessfulRequestId = lastSuccessfulRequestIdRef.current;
        const abortController = new AbortController();
        abortControllerRef.current = abortController;
        // `answered` records whether the agent's answer was delivered. Only
        // the final hop has one, so a turn stopped before it still owes
        // deep-chat a response.
        turn = {
          stopped: false,
          placed: false,
          answered: false,
        };
        turnRef.current = turn;
        setIsTurnInProgress(true);
        // The component the user had selected when they sent the message is
        // the scope of the request for the whole turn. Placing a component
        // selects it, so reading the selection again on later hops would tell
        // the agent the user narrowed the request to what it just placed.
        const activeComponentUuid =
          store.getState().ui.selection.items[0] ?? '';
        // The Tool is fixed for the turn as well: every hop must resolve the
        // agent that parked the state, or the controller rejects it.
        const selectedTool = selectedToolRef.current;

        // With attachments deep-chat sends one `message<n>` JSON string per
        // message instead of a `messages` array. Later hops send JSON, so read
        // them back into the shape the controller expects. FormData preserves
        // insertion order, which is the message order.
        const formMessages: unknown[] = [];
        if (body instanceof FormData) {
          body.forEach((value, key) => {
            if (/^message\d+$/.test(key)) {
              formMessages.push(JSON.parse(value as string));
            }
          });
        }

        // The Drupal context a request carries. Rebuilt every hop, because the
        // previous hop may have placed components or written page data and the
        // agent needs the resulting state.
        const buildContext = (): Record<string, unknown> => {
          const current = currentValuesRef.current;
          const state = store.getState();
          const pageData = selectPageData(state);
          return {
            request_id: requestId,
            conversation_id: conversationId,
            last_successful_request_id: lastSuccessfulRequestId,
            entity_type: current.params.entityType,
            entity_id: current.params.entityId,
            // Prefer the code-editor route param: it identifies the open
            // component immediately after navigation (e.g. right after
            // creating one), whereas the Redux machineName is only set
            // once the editor finishes its async data load.
            selected_component:
              current.params.codeComponentId || current.codeComponentName,
            selected_component_required_props:
              current.codeComponentRequiredProps || [],
            active_component_uuid: activeComponentUuid,
            current_layout: transformLayout(),
            derived_proptypes: fixtureProps,
            page_title: pageData['title[0][value]'],
            page_description: pageData['description[0][value]'],
            // Omitted while no Tool is selected.
            ...(selectedTool ? { selected_tool: selectedTool } : {}),
          };
        };

        // The agent pauses after each tool decision and reports
        // `should_continue`. Re-POST the same turn to resume it from the state
        // the controller stored, until the agent reports it is finished.
        let data: any;
        let isFirstHop = true;
        do {
          const context = buildContext();
          const headers: Record<string, string> = {
            'X-CSRF-Token': csrfToken,
          };
          let requestBody: FormData | string;
          if (isFirstHop && body instanceof FormData) {
            // Attachments can only be sent as multipart, so each context value
            // becomes a form value. Non-string values are JSON-encoded and the
            // controller decodes them back.
            Object.entries(context).forEach(([key, value]) => {
              // FormData stringifies undefined to "undefined"; drop the key
              // instead, which is what JSON.stringify does in the other branch.
              if (value === undefined) {
                return;
              }
              body.append(
                key,
                typeof value === 'string' ? value : JSON.stringify(value),
              );
            });
            requestBody = body;
          } else {
            // Later hops of an attachment turn send JSON too: the images are
            // already in the agent's stored chat history, and appending to the
            // same FormData again would duplicate every context key.
            requestBody = JSON.stringify({
              ...(body instanceof FormData ? { messages: formMessages } : body),
              ...context,
            });
            headers['Content-Type'] = 'application/json';
          }
          isFirstHop = false;

          const response = await fetch('/admin/api/canvas/ai-dev', {
            method: 'POST',
            headers,
            body: requestBody,
            signal: abortController.signal,
          });
          if (!response.ok) {
            throw new Error(`HTTP error. Status: ${response.status}`);
          }
          data = await response.json();

          if (data.status === false) {
            throw new Error(
              data.message ||
                'An error occurred while processing your request. Please try again.',
            );
          }
          // A hop that lands after the user pressed stop is discarded whole:
          // its narration is not shown and its operations are not applied.
          // The abort usually rejects the fetch above before this is reached;
          // this covers the press that arrives once the response already has.
          if (turn.stopped) {
            break;
          }
          // The narration stays above the answer, and its status row spins
          // until the turn is finished.
          renderProgress(
            data.progress,
            data.should_continue ? 'running' : 'finished',
          );
          // Apply this hop's side effects: component placement, page data, code
          // component updates.
          const processedMessage = await receiveMessageRef.current(data);
          // A stop pressed while this hop's operations were being applied
          // ended the batch early. The turn closes on the stopped notice
          // below, not on the agent's answer, and no further hop is sent.
          if (turn.stopped) {
            break;
          }
          // Only the final hop has an answer for the user. Delivering a
          // response earlier would end the turn as far as deep-chat is
          // concerned, and stop its loader.
          if (!data.should_continue) {
            await signals.onResponse(processedMessage);
            turn.answered = true;
            // The turn is accepted: the next turn resumes the checkpoint this
            // one wrote. A turn whose operations could not be applied answered
            // with an error and is not accepted, which leaves the last
            // accepted turn in place.
            if (!(
              'role' in processedMessage && processedMessage.role === 'error'
            )) {
              lastSuccessfulRequestIdRef.current = requestId;
            }
          }
        } while (data.should_continue);

        // A turn stopped after its hop had already landed leaves the loop
        // here rather than through the catch, and still owes deep-chat a
        // response. Unmount ends a batch the same way, and then there is no
        // chat left to tell.
        if (!turn.answered) {
          renderProgress(narration, 'stopped');
          if (chatElementRef.current) {
            await signals.onResponse({ text: TURN_STOPPED_MESSAGE });
          }
        }
      } catch (error: any) {
        if (error.name === 'AbortError') {
          // The stop button drops the request the loop is awaiting. Keep the
          // narration of the hops that did land, on a status row that reports
          // stopped rather than finished, and close the turn on a notice
          // instead of an error. Unmount aborts too, and then there is no
          // chat left to tell.
          renderProgress(narration, 'stopped');
          if (chatElementRef.current) {
            await signals.onResponse({ text: TURN_STOPPED_MESSAGE });
          }
        } else {
          // Keep the narration, with its status row switched to finished.
          renderProgress(narration, 'finished');
          console.error('AI request failed:', error);
          await signals.onResponse({
            text: error.message
              ? error.message
              : 'An error occurred while processing your request. Please try again.',
            role: 'error',
          });
        }
      } finally {
        // Placing selects each component in turn. Once the build has landed —
        // or been stopped part way — leave nothing selected: the editor route
        // and the selection state must agree, or the contextual panel shows an
        // empty form.
        if (turn?.placed) {
          componentSelectionUtils.unsetSelectedComponent();
        }
        // The turn is over: neither the stop button nor the unmount cleanup
        // has anything left to stop.
        turnRef.current = null;
        // Also runs when the turn was stopped or the component unmounted, so
        // neither can leave the selection locked.
        setIsTurnInProgress(false);
      }
      setTimeout(() => {
        chatElementRef.current?.disableSubmitButton();
      }, 0);
    },
    // The handler is intentionally stable so MemoDeepChat is not re-rendered
    // (and the typed prompt cleared) when unrelated state such as page metadata
    // changes. It reads every dynamic value via refs (currentValuesRef,
    // receiveMessageRef, csrfTokenRef) and via the Redux store, both of which
    // keep a stable identity, so the deps stay empty.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  const connectConfig = useMemo(
    () => ({
      // Defining a handler instead of an object to ensure we can work with
      // up-to-date data. Otherwise `connect.additionalBodyProps` captures
      // the values at the time the component was mounted.
      // @see https://deepchat.dev/docs/connect/#Handler
      handler: connectHandler,
    }),
    [connectHandler],
  );

  const handleComponentRender = useCallback(() => {
    if (!isComponentRenderedRef.current) {
      chatElementRef.current.clearMessages();
      historyStore.clearHistory();
      chatElementRef.current.disableSubmitButton();
      isComponentRenderedRef.current = true;
    }
  }, []);

  useEffect(() => {
    const chatEl = chatElementRef.current;
    if (!chatEl) return;
    const handler = (event: { detail: { message: any; isHistory: any } }) => {
      const { message, isHistory } = event.detail;
      if (!isHistory) {
        if (welcomeTextRef.current) {
          welcomeTextRef.current.style.display = 'none';
        }
        historyStore.addMessage(message);
        const event = new CustomEvent('canvas-message', {
          detail: {
            message: message,
            time: new Date(),
          },
        });
        window.dispatchEvent(event);
      }
    };
    chatEl.addEventListener('message', handler);
    return () => {
      chatEl.removeEventListener('message', handler);
    };
  }, [csrfToken]);

  // Handle text input changes to enable/disable submit button. Memoized so its
  // identity stays stable across renders, which keeps MemoDeepChat from
  // re-rendering (and clearing the typed prompt) when page metadata changes.
  const handleTextInput = useCallback(() => {
    const chatEl = chatElementRef.current;
    const deepChatEl = document.querySelector('deep-chat') as any;
    const inputText =
      deepChatEl?.shadowRoot?.querySelector('#text-input')?.textContent || '';
    if (inputText.trim().length > 0) {
      chatEl.disableSubmitButton(false);
    } else {
      chatEl.disableSubmitButton();
    }
  }, []);

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
              <Text className={styles.aiWizardTitle}>Drupal Canvas AI</Text>
              <Text className={styles.aiWizardBeta}>Beta</Text>
            </Box>
          </Flex>
          <Text ref={welcomeTextRef} className={styles.aiWizardSubtitle}>
            Hello, how can I help you today?
          </Text>
        </Flex>
        {activeTool && (
          <ActiveToolPill
            tool={activeTool}
            onDismiss={dismissSelectedTool}
            disabled={isTurnInProgress}
          />
        )}
        <MemoDeepChat
          ref={chatElementRef}
          history={
            initialHistoryRef.current.length > 0
              ? initialHistoryRef.current
              : undefined
          }
          images={DEEP_CHAT_IMAGES}
          requestBodyLimits={DEEP_CHAT_REQUEST_BODY_LIMITS}
          connect={connectConfig}
          onInput={handleTextInput}
          onComponentRender={handleComponentRender}
          textInput={DEEP_CHAT_TEXT_INPUT}
          style={DEEP_CHAT_STYLE}
          messageStyles={DEEP_CHAT_MESSAGE_STYLES}
          submitButtonStyles={DEEP_CHAT_SUBMIT_BUTTON_STYLES}
          auxiliaryStyle={DEEP_CHAT_AUXILIARY_STYLE}
          customButtons={customButtons}
        />
        {tools.length > 0 && (
          <AiToolSelector
            tools={tools}
            open={isToolSelectorOpen}
            onOpenChange={setIsToolSelectorOpen}
            selectedTool={selectedTool}
            onSelect={setSelectedTool}
          />
        )}
        <Box className={styles.aiWizardLegalContainer}>
          <Text>
            These responses are generated by AI, which can make mistakes.
          </Text>
        </Box>
      </Flex>
    )
  );
};

export default AiWizardDev;
