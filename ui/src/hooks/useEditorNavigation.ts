import { useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import { getBaseUrl, getXbSettings } from '@/utils/drupal-globals';

const xbSettings = getXbSettings();

/**
 * Hook for editor navigation functions
 * Handles URL/route based navigation for regions and entities
 */
export function useEditorNavigation() {
  const navigate = useNavigate();
  const baseUrl = getBaseUrl();

  const setSelectedRegion = useCallback(
    (regionId: string) => {
      const baseUrl = '/editor';
      if (regionId === DEFAULT_REGION) {
        navigate(`${baseUrl}`);
      } else {
        navigate(`${baseUrl}/region/${regionId}`);
      }
    },
    [navigate],
  );

  // @todo revisit approach (like using routing) and see if timeout can be removed in https://www.drupal.org/i/3502887
  const setEditorEntity = useCallback(
    (entityType: string, entityId: string) => {
      // Use a timeout to ensure that RTK query cleans up its subscriptions first before navigating away.
      // Without this timeout, RTK throws an error because it tries to make a request following cache invalidation while
      // the window.location.href is in progress.
      setTimeout(() => {
        window.location.href = `${baseUrl}xb/${entityType}/${entityId}`;
      }, 100);
    },
    [baseUrl],
  );

  const editorNavUtils = {
    setSelectedRegion,
    setEditorEntity,
  };

  xbSettings.navUtils = editorNavUtils;

  return editorNavUtils;
}

export default useEditorNavigation;
