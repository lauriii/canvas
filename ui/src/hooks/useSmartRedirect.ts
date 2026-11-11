import { useNavigate } from 'react-router';

import { useAppSelector } from '@/app/hooks';
import { selectHomepagePath } from '@/features/configuration/configurationSlice';
import { useWorkingLanguage } from '@/hooks/useWorkingLanguage';
import { useGetContentListQuery } from '@/services/content';

/**
 * Hook that provides smart redirect functionality for page/template deletions.
 * Follows the hierarchy: homepage → first available page → /editor
 */
export const useSmartRedirect = () => {
  const navigate = useNavigate();
  const homepagePath = useAppSelector(selectHomepagePath);

  // In the working language, the request is the one the page lists make, and
  // the first page is one in the working language.
  const { workingLanguage, isResolved, isMultilingual, urlForPage } =
    useWorkingLanguage();
  const { data } = useGetContentListQuery(
    {
      entityType: 'canvas_page',
      search: '',
      langcode: isMultilingual ? workingLanguage?.id : undefined,
    },
    { skip: isMultilingual && !isResolved },
  );
  const pageItems = data?.items ?? [];

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

    // Opens a page in the working language, like the page lists.
    if (isHomepageAvailable) {
      // Redirect to the canvas_page that is set as the homepage
      navigate(urlForPage(homepage));
    } else if (availablePages.length > 0) {
      // Redirect to the first available canvas_page
      navigate(urlForPage(availablePages[0]));
    } else {
      // Redirect to /editor if there are no canvas_pages (last resort)
      navigate('/editor');
    }
  };

  return { redirectToNextBestPage, pageItems };
};
