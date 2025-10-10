import { useCallback, useEffect } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectPreviewHtml } from '@/features/pagePreview/previewSlice';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';

import {
  selectActiveExtension,
  selectSubscriptions,
  setSubscriptions,
} from './extensionsSlice';

export default function useExtensions() {
  const dispatch = useAppDispatch();
  const activeExtension = useAppSelector(selectActiveExtension);
  const subscriptions = useAppSelector(selectSubscriptions);
  const previewHtml = useAppSelector(selectPreviewHtml);
  const selectedComponentUuid = useAppSelector(selectSelectedComponentUuid);

  // Helper function to get data for a specific type.
  const getDataForType = useCallback(
    (dataType: string): any => {
      switch (dataType) {
        case 'previewHtml':
          return previewHtml;
        case 'selectedComponentUuid':
          return selectedComponentUuid;
        default:
          return null;
      }
    },
    [previewHtml, selectedComponentUuid],
  );

  // Listen for data requests and subscription requests from extensions.
  const handleMessage = useCallback(
    (event: MessageEvent) => {
      if (!activeExtension) return;

      // Validate origin.
      let expectedOrigin = window.location.origin;
      if (
        activeExtension.url.startsWith('http://') ||
        activeExtension.url.startsWith('https://')
      ) {
        try {
          expectedOrigin = new URL(activeExtension.url).origin;
        } catch {
          return;
        }
      }

      if (event.origin !== expectedOrigin) return;

      const messageType = event.data.type;

      // Handle one-time data requests: canvas:data:get:<type>
      if (messageType?.startsWith('canvas:data:get:')) {
        const dataType = messageType.replace('canvas:data:get:', '');
        const iframe = document.getElementById(
          `canvas-extension-iframe-${activeExtension.id}`,
        ) as HTMLIFrameElement;

        if (iframe?.contentWindow) {
          const data = getDataForType(dataType);
          iframe.contentWindow.postMessage(
            {
              type: `canvas:data:get:${dataType}`,
              payload: data,
            },
            expectedOrigin,
          );
        }
      }

      // Handle subscription requests: canvas:data:subscribe:<type>
      if (messageType?.startsWith('canvas:data:subscribe:')) {
        const dataType = messageType.replace('canvas:data:subscribe:', '');
        if (!subscriptions.includes(dataType)) {
          dispatch(setSubscriptions([...subscriptions, dataType]));
        }
      }

      // Handle unsubscribe requests: canvas:data:unsubscribe:<type>
      if (messageType?.startsWith('canvas:data:unsubscribe:')) {
        const dataType = messageType.replace('canvas:data:unsubscribe:', '');
        dispatch(setSubscriptions(subscriptions.filter((s) => s !== dataType)));
      }
    },
    [activeExtension, dispatch, getDataForType, subscriptions],
  );

  useEffect(() => {
    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
  }, [handleMessage]);

  // Send subscription data to the active extension iframe when data changes.
  useEffect(() => {
    if (!activeExtension || subscriptions.length === 0) {
      return;
    }

    const iframe = document.getElementById(
      `canvas-extension-iframe-${activeExtension.id}`,
    ) as HTMLIFrameElement;

    if (!iframe?.contentWindow) return;

    try {
      // For relative URLs, use the same origin as the parent window.
      let targetOrigin = window.location.origin;

      // If the URL is absolute, extract its origin.
      if (
        activeExtension.url.startsWith('http://') ||
        activeExtension.url.startsWith('https://')
      ) {
        targetOrigin = new URL(activeExtension.url).origin;
      }

      // Send separate message for each subscription.
      subscriptions.forEach((dataType) => {
        const data = getDataForType(dataType);
        iframe.contentWindow?.postMessage(
          {
            type: `canvas:data:subscribe:${dataType}`,
            payload: data,
          },
          targetOrigin,
        );
      });
    } catch (error) {
      console.error('Failed to send messages to extension:', error);
    }
  }, [activeExtension, subscriptions, getDataForType]);
}
