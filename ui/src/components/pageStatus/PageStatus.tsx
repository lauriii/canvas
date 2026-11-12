import { useEffect, useState } from 'react';
import { useLocation, useParams, useSearchParams } from 'react-router';
import { PlusIcon } from '@radix-ui/react-icons';
import { Badge, Button, DropdownMenu, Tooltip } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch } from '@/app/hooks';
import CustomizeLayoutDialog, {
  FORK_LINK_REL,
} from '@/components/languageSelect/CustomizeLayoutDialog';
import { HOMEPAGE_CONFIG_ID } from '@/components/pageInfo/PageInfo';
import { setHomepageStagedConfigExists } from '@/features/configuration/configurationSlice';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import {
  useTranslateAction,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import { EDITED_TRANSLATION_PARAM } from '@/services/baseQuery';
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
  const [searchParams] = useSearchParams();
  const language = searchParams.get('language') || undefined;
  const [hasAutoSave, setHasAutoSave] = useState(false);
  // skipToken prevents the query from running until both args are defined.
  // "Pass skipToken to a query selector to have that selector return an uninitialized state."
  // Pass current language to match the displayed page.
  const { isTemplatePreviewRoute } = useTemplateRef();
  const { pathname } = useLocation();
  const isPreviewRoute = pathname.startsWith('/preview/');
  // A template's translations come from the template, not this query.
  const isTemplateRoute =
    isTemplatePreviewRoute || pathname.startsWith('/template/');
  // The layout of this page in this language only: the status of the page
  // opened before is not shown while this one loads.
  const { currentData: fetchedLayout, isError } = useGetPageLayoutQuery(
    !isTemplatePreviewRoute && entityId && entityType
      ? { entityId, entityType, language }
      : skipToken,
  );
  const dispatch = useAppDispatch();
  const {
    workingLanguage,
    statusOf,
    translations,
    layoutStateOf,
    hasOwnLayout,
    isMultilingual,
  } = useWorkingLanguage();
  const translate = useTranslateAction()(workingLanguage?.id);
  const [isForkDialogOpen, setIsForkDialogOpen] = useState(false);
  // A translation with a custom layout has its own changes, and the original
  // and the translations that follow its layout have theirs. The preview
  // shows such a translation with `?language=`.
  const editedTranslation =
    searchParams.get(EDITED_TRANSLATION_PARAM) ??
    (language && hasOwnLayout(language) ? language : null);
  const forked = (translations?.forked as string[] | undefined) ?? [];
  const forkedKey = forked.join();

  useEffect(() => {
    if (changes) {
      const isChanged = findInChanges(
        changes,
        entityId,
        entityType,
        (langcode) =>
          editedTranslation
            ? langcode === editedTranslation
            : !langcode || !forkedKey.split(',').includes(langcode),
      );
      setHasAutoSave(isChanged);
    }
  }, [
    changes,
    fetchedLayout,
    entityId,
    entityType,
    editedTranslation,
    forkedKey,
  ]);

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

  // Whether the page is translated into the working language is not known
  // yet: no status, rather than one that changes once it is.
  if (isMultilingual && !translations && !isTemplateRoute) {
    return null;
  }

  // The page has no status of its own in a language it is not translated into.
  if (statusOf(workingLanguage?.id) === 'Not translated') {
    // It adds the translation, with a translation module, or as a copy with
    // its own layout. Not in the preview, which only shows the page.
    const forkUrl: string | undefined =
      entityType === 'canvas_page' && !isPreviewRoute
        ? translations?.links?.[workingLanguage!.id]?.[FORK_LINK_REL]
        : undefined;
    const forkLabel = 'Customize layout…';
    const addActions = [
      translate && { label: translate.label, run: translate.run },
      forkUrl && { label: forkLabel, run: () => setIsForkDialogOpen(true) },
    ].filter((action) => !!action);
    const mark = (
      <Button
        size="1"
        variant="soft"
        color="gray"
        data-testid="canvas-language-status"
        aria-label={`Not translated. Add ${workingLanguage!.name}`}
        onClick={addActions.length === 1 ? addActions[0].run : undefined}
      >
        Not translated
        <PlusIcon />
      </Button>
    );
    return (
      <>
        {addActions.length === 0 && (
          <Badge
            size="1"
            variant="soft"
            color="gray"
            data-testid="canvas-language-status"
          >
            Not translated
          </Badge>
        )}
        {addActions.length === 1 && (
          <Tooltip content={addActions[0].label}>{mark}</Tooltip>
        )}
        {addActions.length > 1 && (
          // Neither is the default: the choice decides how the page is
          // translated.
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>{mark}</DropdownMenu.Trigger>
            <DropdownMenu.Content>
              {addActions.map((action) => (
                <DropdownMenu.Item key={action.label} onSelect={action.run}>
                  {action.label}
                </DropdownMenu.Item>
              ))}
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        )}
        <CustomizeLayoutDialog
          languageId={isForkDialogOpen ? workingLanguage!.id : undefined}
          url={forkUrl}
          onClose={() => setIsForkDialogOpen(false)}
        />
      </>
    );
  }

  if (fetchedLayout && !isError) {
    const { hasUnsavedStatusChange } = fetchedLayout;
    // A translation started with its own layout is a draft until published:
    // visitors do not see it yet.
    const isDraftTranslation =
      !!editedTranslation &&
      layoutStateOf(editedTranslation) === 'pending_fork' &&
      !translations?.available?.includes(editedTranslation);
    const isNew = fetchedLayout.isNew || isDraftTranslation;
    const isPublished = fetchedLayout.isPublished && !isDraftTranslation;

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
