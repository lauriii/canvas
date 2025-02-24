import { Badge } from '@radix-ui/themes';
import { useAppSelector } from '@/app/hooks';
import {
  selectEntityId,
  selectEntityType,
} from '@/features/configuration/configurationSlice';
import { useGetAllPendingChangesQuery } from '@/services/pendingChangesApi';
import { useEffect, useState } from 'react';
import { useGetLayoutByIdQuery } from '@/services/componentAndLayout';
import { findInChanges } from '@/utils/function-utils';

const PageStatus = () => {
  const { data: changes } = useGetAllPendingChangesQuery();
  const entityId = useAppSelector(selectEntityId);
  const entityType = useAppSelector(selectEntityType);
  const [hasAutosave, setHasAutoSave] = useState(false);
  const { data: fetchedLayout } = useGetLayoutByIdQuery(entityId);

  useEffect(() => {
    if (changes) {
      const isChanged = findInChanges(changes, entityId, entityType);
      setHasAutoSave(isChanged);
    }
  }, [changes, fetchedLayout, entityId, entityType]);

  if (fetchedLayout) {
    const { isNew, isPublished } = fetchedLayout;

    return (
      <>
        {isNew && (
          <Badge size="1" color="blue" variant="solid">
            Draft
          </Badge>
        )}
        {!isNew && hasAutosave && (
          <Badge size="1" color="yellow" variant="solid">
            Changed
          </Badge>
        )}
        {!isNew && !hasAutosave && isPublished && (
          <Badge size="1" variant="solid" color="green">
            Published
          </Badge>
        )}
        {!isNew && !hasAutosave && !isPublished && (
          <Badge size="1" variant="solid" color="green">
            Published
          </Badge>
        )}
      </>
    );
  }
};

export default PageStatus;
