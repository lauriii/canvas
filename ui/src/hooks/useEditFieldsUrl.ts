import { useParams } from 'react-router';

import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';

/**
 * Template editor: the edited bundle's "Manage fields" URL, if accessible.
 */
const useEditFieldsUrl = (): string | undefined => {
  const { entityType, bundle } = useParams();
  const { data: templates } = useGetContentTemplatesQuery();
  if (!entityType || !bundle) {
    return undefined;
  }
  return templates?.[entityType]?.bundles?.[bundle]?.editFieldsUrl;
};

export default useEditFieldsUrl;
