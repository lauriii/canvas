import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import {
  closeAllDialogs,
  selectDialogStates,
  selectSelectedCodeComponent,
} from '@/features/ui/codeComponentDialogSlice';
import { selectPreviouslyEdited } from '@/features/ui/uiSlice';
import {
  useDeleteCodeComponentMutation,
  useGetComponentUsageDetailsQuery,
  useGetComponentUsageListQuery,
} from '@/services/componentAndLayout';

import type { ComponentUsageDetailsResponse } from '@/services/componentAndLayout';
import type { CodeComponentSerialized } from '@/types/CodeComponent';

const DeleteCodeComponentDialog = () => {
  const selectedComponent = useAppSelector(
    selectSelectedCodeComponent,
  ) as CodeComponentSerialized;
  const [deleteCodeComponent, { isLoading, isSuccess, isError, error, reset }] =
    useDeleteCodeComponentMutation();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const { isDeleteDialogOpen } = useAppSelector(selectDialogStates);
  const componentListName = `js.${selectedComponent?.machineName}`;
  const [statefulUsageDetails, setStatefulUsageDetails] =
    useState<ComponentUsageDetailsResponse | null>(null);
  const { codeComponentId } = useParams();
  const previouslyEdited = useAppSelector(selectPreviouslyEdited);

  // Get the full component usage list to see if the currently selected component
  // is being used. If it is not, this means it has never been made external, and
  // thus has no usage data. We need to skip the usage details query to avoid
  // the 404 that occurs when we request usage details for a component that lacks usage data.
  const {
    data: componentUsageList,
    isSuccess: componentUsageListSuccess,
    isError: isComponentUsageListError,
    error: componentUsageListError,
    isFetching,
  } = useGetComponentUsageListQuery(undefined, { skip: !isDeleteDialogOpen });
  const componentInComponentList = !!(componentUsageList?.data || {})[
    componentListName
  ];

  // If the selected component is not in the loaded component list, we should
  // not check usage.
  const noUsageToCheck =
    !isFetching && componentUsageListSuccess && !componentInComponentList;

  const {
    data: usageDetails,
    isSuccess: usageLoadingSuccess,
    isError: isUsageError,
    error: usageError,
    isFetching: isUsageFetching,
  } = useGetComponentUsageDetailsQuery(`js.${selectedComponent?.machineName}`, {
    skip: !isDeleteDialogOpen || noUsageToCheck || !componentUsageList,
    refetchOnMountOrArgChange: true,
  });

  const handleDelete = async () => {
    if (!selectedComponent) return;
    await deleteCodeComponent(selectedComponent.machineName);
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      reset();
      setStatefulUsageDetails(null);
      dispatch(closeAllDialogs());
    }
  };

  useEffect(() => {
    if (usageDetails && !isUsageFetching) {
      setStatefulUsageDetails(usageDetails);
    }
  }, [usageDetails, isUsageFetching]);

  useEffect(() => {
    if (isSuccess) {
      dispatch(closeAllDialogs());
      reset();
      if (
        codeComponentId &&
        codeComponentId === selectedComponent?.machineName
      ) {
        navigate(previouslyEdited.path || '/editor');
      }
    }
  }, [
    isSuccess,
    dispatch,
    navigate,
    codeComponentId,
    selectedComponent?.machineName,
    previouslyEdited.path,
    reset,
  ]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to delete component:', error);
    }
  }, [isError, error]);

  useEffect(() => {
    if (isUsageError) {
      console.error('Failed to get component usage data:', usageError);
    }
  }, [isUsageError, usageError]);

  useEffect(() => {
    if (isComponentUsageListError) {
      console.error(
        'Failed to get component usage list:',
        componentUsageListError,
      );
    }
  }, [isComponentUsageListError, componentUsageListError]);

  const deleteErrorOutput = {
    title: 'Failed to delete component',
    message: `An error ${
      error && 'status' in error ? '(HTTP ' + error.status + ')' : ''
    } occurred while deleting the component. Please check the browser console for more details.`,
    resetButtonText: 'Try again',
    onReset: handleDelete,
  };

  const componentUsageListErrorOutput = {
    title: 'Failed to fetch component list',
    message: `An error ${
      componentUsageListError && 'status' in componentUsageListError
        ? '(HTTP ' + componentUsageListError.status + ')'
        : ''
    } occurred while fetching the component usage list. Please check the browser console for more details.`,
  };

  const usageErrorOutput = {
    title: 'Failed to fetch component usage details',
    message: `An error ${
      usageError && 'status' in usageError
        ? '(HTTP ' + usageError.status + ')'
        : ''
    } occurred while fetching component usage. Please check the browser console for more details.`,
  };

  if (!selectedComponent) return null;

  let pastRevisionsWarning: React.ReactNode | null = null;
  if (
    !isUsageFetching &&
    statefulUsageDetails?.content &&
    statefulUsageDetails?.content?.length > 0
  ) {
    if (statefulUsageDetails) {
      const length = statefulUsageDetails.content.length;
      pastRevisionsWarning = (
        <>
          This will break <b>{length}</b> past version{length > 1 ? 's' : ''}.
          Reverting to past versions that rely on this component will appear
          broken.
        </>
      );
    }
  }

  const formImpactingErrors = isUsageError || isComponentUsageListError;
  // Only show the dialog if no fetching in progress AND:
  // - there is no component usage to check OR
  // - component usage data is available OR
  // - there are errors loading usage or the component list (in which case
  //   the dialog exits to display the error and provide the option to cancel).
  const dialogReady =
    (noUsageToCheck ||
      usageLoadingSuccess ||
      isUsageError ||
      isComponentUsageListError) &&
    !isFetching &&
    !isUsageFetching;

  const description = (
    <>
      You are about to permanently delete the <b>{selectedComponent.name}</b>{' '}
      component.
      {pastRevisionsWarning && (
        <>
          <br />
          <br />
          {pastRevisionsWarning}
        </>
      )}
    </>
  );
  return (
    dialogReady && (
      <Dialog
        open={isDeleteDialogOpen}
        onOpenChange={handleOpenChange}
        title="Delete component"
        error={
          (isError && deleteErrorOutput) ||
          (isUsageError && usageErrorOutput) ||
          (isComponentUsageListError && componentUsageListErrorOutput) ||
          undefined
        }
        footer={{
          cancelText: 'Cancel',
          confirmText: (!formImpactingErrors && 'Delete') || undefined,
          onConfirm: (!formImpactingErrors && handleDelete) || undefined,
          isConfirmDisabled: false,
          isConfirmLoading: isLoading,
          isDanger: true,
        }}
        description={!formImpactingErrors && description}
      />
    )
  );
};

export default DeleteCodeComponentDialog;
