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

export interface PageStatusBadgeProps {
  isNew: boolean;
  hasAutosave: boolean;
  isPublished: boolean;
}

export const PageStatusBadge: React.FC<PageStatusBadgeProps> = ({
  isNew,
  hasAutosave,
  isPublished,
}) => {
  if (isNew) {
    return (
      <Badge size="1" variant="solid" color="blue">
        Draft
      </Badge>
    );
  }

  if (hasAutosave) {
    return (
      <Badge size="1" variant="solid" color="amber">
        Changed
      </Badge>
    );
  }

  if (isPublished) {
    return (
      <Badge size="1" variant="solid" color="green">
        Published
      </Badge>
    );
  }

  return (
    <Badge size="1" variant="solid" color="gray">
      Archived
    </Badge>
  );
};

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
      <PageStatusBadge
        isPublished={isPublished}
        isNew={isNew}
        hasAutosave={hasAutosave}
      />
    );
  }
};

export default PageStatus;
