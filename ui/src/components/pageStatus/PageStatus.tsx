import { useEffect, useState } from 'react';
import { useParams } from 'react-router';
import { Badge } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch } from '@/app/hooks';
import { HOMEPAGE_CONFIG_ID } from '@/components/pageInfo/PageInfo';
import { setHomepageStagedConfigExists } from '@/features/configuration/configurationSlice';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { useGetAllPendingChangesQuery } from '@/services/pendingChangesApi';
import { findInChanges } from '@/utils/function-utils';

export interface PageStatusBadgeProps {
  isNew: boolean;
  hasAutoSave: boolean;
  isPublished: boolean;
  hasUnsavedStatusChange?: boolean;
}

export const PageStatusBadge: React.FC<PageStatusBadgeProps> = ({
  isNew,
  hasAutoSave,
  isPublished,
  hasUnsavedStatusChange,
}) => {
  // Show "Draft" only if the page is new (draft) AND unpublished
  if (isNew && !isPublished) {
    return (
      <Badge size="1" variant="solid" color="blue">
        Draft
      </Badge>
    );
  }

  // Show "Changed" if there's an unsaved status change to unpublished
  // Show "Unpublished" if the page is unpublished (but not a new draft) and no unsaved changes
  if (!isPublished) {
    return hasUnsavedStatusChange ? (
      <Badge size="1" variant="solid" color="amber">
        Changed
      </Badge>
    ) : (
      <Badge size="1" variant="solid" color="gray">
        Unpublished
      </Badge>
    );
  }

  if (hasAutoSave) {
    return (
      <Badge size="1" variant="solid" color="amber">
        Changed
      </Badge>
    );
  }

  return (
    <Badge size="1" variant="solid" color="green">
      Published
    </Badge>
  );
};

const PageStatus = () => {
  const { data: changes, isSuccess: isGetPendingChangesSuccess } =
    useGetAllPendingChangesQuery();
  const { entityType, entityId } = useParams();
  const [hasAutoSave, setHasAutoSave] = useState(false);
  // skipToken prevents the query from running until both args are defined.
  // "Pass skipToken to a query selector to have that selector return an uninitialized state."
  const { data: fetchedLayout, isError } = useGetPageLayoutQuery(
    entityId && entityType ? { entityId, entityType } : skipToken,
  );
  const dispatch = useAppDispatch();

  useEffect(() => {
    if (changes) {
      const isChanged = findInChanges(changes, entityId, entityType);
      setHasAutoSave(isChanged);
    }
  }, [changes, fetchedLayout, entityId, entityType]);

  // Check if the homepage staged update exists in the current auto-save.
  useEffect(() => {
    if (isGetPendingChangesSuccess) {
      const containsHomepageConfig = Object.prototype.hasOwnProperty.call(
        changes,
        `staged_config_update:${HOMEPAGE_CONFIG_ID}`,
      );
      dispatch(setHomepageStagedConfigExists(containsHomepageConfig));
    }
  }, [changes, dispatch, isGetPendingChangesSuccess]);

  if (fetchedLayout && !isError) {
    const { isNew, isPublished, hasUnsavedStatusChange } = fetchedLayout;

    return (
      <PageStatusBadge
        isPublished={isPublished}
        isNew={isNew}
        hasAutoSave={hasAutoSave}
        hasUnsavedStatusChange={hasUnsavedStatusChange}
      />
    );
  }
};

export default PageStatus;
