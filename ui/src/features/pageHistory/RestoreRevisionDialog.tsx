import { AlertDialog, Button, Flex } from '@radix-ui/themes';

import { formatRevisionDate } from '@/features/pageHistory/formatRevisionDate';

import type { PageDraft, PageRevision } from '@/services/content';

interface RestoreRevisionDialogProps {
  // The version to restore; the dialog is open while there is one.
  revision: PageRevision | null;
  // The page's unpublished changes, which restoring replaces.
  draft: PageDraft | null;
  onCancel: () => void;
  onRestore: (revision: PageRevision) => void;
}

/**
 * Asks before restoring a revision, saying what it does and does not do.
 */
const RestoreRevisionDialog = ({
  revision,
  draft,
  onCancel,
  onRestore,
}: RestoreRevisionDialogProps) => (
  <AlertDialog.Root
    open={revision !== null}
    onOpenChange={(open) => !open && onCancel()}
  >
    <AlertDialog.Content maxWidth="450px">
      <AlertDialog.Title>Restore this version?</AlertDialog.Title>
      <AlertDialog.Description size="2">
        The page goes back to how it was on{' '}
        {revision && formatRevisionDate(revision.created)}, as unpublished
        changes. Nothing goes live until you publish.
      </AlertDialog.Description>
      {draft && (
        <AlertDialog.Description size="2" mt="3" color="red">
          This replaces the page&rsquo;s unpublished changes, last saved by{' '}
          {draft.author ?? 'a deleted user'} on{' '}
          {formatRevisionDate(draft.updated)}. They can&rsquo;t be brought back.
        </AlertDialog.Description>
      )}
      <Flex gap="3" mt="4" justify="end">
        <AlertDialog.Cancel>
          <Button variant="soft" color="gray">
            Cancel
          </Button>
        </AlertDialog.Cancel>
        <AlertDialog.Action>
          <Button
            color={draft ? 'red' : undefined}
            onClick={() => revision && onRestore(revision)}
          >
            {draft ? 'Replace changes' : 'Restore'}
          </Button>
        </AlertDialog.Action>
      </Flex>
    </AlertDialog.Content>
  </AlertDialog.Root>
);

export default RestoreRevisionDialog;
