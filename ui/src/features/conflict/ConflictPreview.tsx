import { useMemo } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { Spinner } from '@radix-ui/themes';

import { useGetConflictPageLayoutQuery } from '@/services/componentAndLayout';
import { getViewportSizes } from '@/utils/viewports';

import styles from './ConflictPreview.module.css';

/**
 * A standalone conflict version preview component.
 *
 * Unlike the main PagePreview, this component is fully isolated from the
 * editor's Redux preview-update cycle. It fetches the layout HTML directly
 * via `useGetConflictPageLayoutQuery` (which has no side-effects) and renders
 * it in an iframe. The `?version=published` search param controls whether the
 * published version or the auto-save (new) version is displayed.
 */
const ConflictPreview = () => {
  const { entityId, entityType, width } = useParams<{
    entityId: string;
    entityType: string;
    width?: string;
  }>();
  const [searchParams] = useSearchParams();
  const isPublished = searchParams.get('version') === 'published';
  const viewportSizes = useMemo(() => getViewportSizes(), []);
  const widthVal = useMemo(() => {
    if (!width || width === 'full') {
      return '100%';
    }

    const viewportSize = viewportSizes.find((v) => v.id === width);
    return viewportSize ? `${viewportSize.width}px` : '100%';
  }, [width, viewportSizes]);

  const { data, isFetching, isError } = useGetConflictPageLayoutQuery(
    entityId && entityType
      ? {
          entityId,
          entityType: entityType as 'canvas_page',
          publishedVersion: isPublished,
        }
      : { entityId: '', entityType: 'canvas_page', publishedVersion: false },
    { skip: !entityId || !entityType },
  );

  if (isError) {
    return (
      <div className={styles.container}>
        <p className={styles.error}>
          Unable to load the {isPublished ? 'published' : 'auto-save'} version
          preview.
        </p>
      </div>
    );
  }

  if (isFetching || !data?.html) {
    return (
      <div className={styles.container}>
        <Spinner />
      </div>
    );
  }

  return (
    <div className={styles.container}>
      <iframe
        title={`Conflict ${isPublished ? 'published' : 'new'} version preview`}
        style={{ width: widthVal }}
        srcDoc={data.html}
        className={styles.iframe}
      />
    </div>
  );
};

export default ConflictPreview;
