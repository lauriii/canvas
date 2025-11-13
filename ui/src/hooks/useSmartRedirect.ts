import { useNavigate } from 'react-router-dom';

import { useAppSelector } from '@/app/hooks';
import { selectHomepagePath } from '@/features/configuration/configurationSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetContentListQuery } from '@/services/content';

/**
 * Hook that provides smart redirect functionality for page/template deletions.
 * Follows the hierarchy: homepage → first available page → /editor
 */
export const useSmartRedirect = () => {
  const navigate = useNavigate();
  const { setEditorEntity } = useEditorNavigation();
  const homepagePath = useAppSelector(selectHomepagePath);

  const { data: pageItems = [] } = useGetContentListQuery({
    entityType: 'canvas_page',
    search: '',
  });

  const redirectToNextBestPage = (excludePageId?: string) => {
    // Filter out the page being deleted if specified
    const availablePages = excludePageId
      ? pageItems.filter((page) => String(page.id) !== excludePageId)
      : pageItems;

    const homepage = pageItems.find(
      (page) => page.internalPath === homepagePath,
    );

    // Check if homepage is available (not being deleted)
    const isHomepageAvailable =
      homepage && (!excludePageId || String(homepage.id) !== excludePageId);

    if (isHomepageAvailable) {
      // Redirect to the canvas_page that is set as the homepage
      setEditorEntity('canvas_page', String(homepage.id));
    } else if (availablePages.length > 0) {
      // Redirect to the first available canvas_page
      setEditorEntity('canvas_page', String(availablePages[0].id));
    } else {
      // Redirect to /editor if there are no canvas_pages (last resort)
      navigate('/editor');
    }
  };

  return { redirectToNextBestPage, pageItems };
};
