import { useEffect, useId, useRef, useState } from 'react';
import { ImageIcon, UploadIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Grid, Spinner, Text } from '@radix-ui/themes';

import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import TextField from '@/components/form/components/TextField';

import {
  browseMedia,
  formatEndpointError,
  uploadMedia,
} from './mediaEndpoints';

import type {
  MediaBrowseItem,
  MediaBrowsePager,
  MediaInputsResolved,
} from './mediaEndpoints';

/**
 * One media item as held by the media widget's internal value: enough to
 * render the closed-state selection list and to produce model values.
 */
export interface MediaSelectionItem {
  id: number | string;
  label: string;
  thumbnailUrl: string | null;
  inputsResolved: MediaInputsResolved | null;
}

export const toSelectionItem = (item: MediaBrowseItem): MediaSelectionItem => ({
  id: item.id,
  label: item.label,
  thumbnailUrl: item.thumbnailUrl,
  inputsResolved: item.inputs_resolved,
});

const SEARCH_DEBOUNCE_MS = 300;

const tileStyle: React.CSSProperties = {
  display: 'block',
  width: '100%',
  padding: 'var(--space-1)',
  border: '2px solid transparent',
  borderRadius: 'var(--radius-2)',
  background: 'transparent',
  cursor: 'pointer',
  textAlign: 'left',
};

const tileSelectedStyle: React.CSSProperties = {
  ...tileStyle,
  borderColor: 'var(--accent-9)',
  background: 'var(--accent-2)',
};

const thumbStyle: React.CSSProperties = {
  display: 'block',
  width: '100%',
  height: '72px',
  objectFit: 'cover',
  borderRadius: 'var(--radius-1)',
  background: 'var(--gray-3)',
};

const tileLabelStyle: React.CSSProperties = {
  display: 'block',
  overflow: 'hidden',
  textOverflow: 'ellipsis',
  whiteSpace: 'nowrap',
};

interface MediaBrowserProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The media type (bundle) to browse and upload into. */
  mediaType: string;
  /** The widget's current selection; seeds the dialog's selection state. */
  initialSelection: MediaSelectionItem[];
  /** Maximum selectable items; null means unlimited. */
  maxItems: number | null;
  onInsert: (items: MediaSelectionItem[]) => void;
}

/**
 * The media widget's browse dialog: text search, a thumbnail grid with
 * click-to-toggle selection, Previous/Next pagination, and an upload flow
 * that collects required alternative text, creates a new media entity, and
 * auto-selects it.
 */
const MediaBrowser = ({
  open,
  onOpenChange,
  mediaType,
  initialSelection,
  maxItems,
  onInsert,
}: MediaBrowserProps) => {
  const [items, setItems] = useState<MediaBrowseItem[]>([]);
  const [pager, setPager] = useState<MediaBrowsePager | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [pendingFile, setPendingFile] = useState<File | null>(null);
  const [pendingAlt, setPendingAlt] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(0);
  const [selection, setSelection] = useState<MediaSelectionItem[]>([]);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const wasOpen = useRef(false);
  const altInputId = useId();

  // Re-seed dialog state from the widget's current selection each time the
  // dialog opens; edits inside the dialog stay local until Insert.
  useEffect(() => {
    if (open && !wasOpen.current) {
      setSelection(initialSelection);
      setUploadError(null);
      setPendingFile(null);
      setPendingAlt('');
      setSearchInput('');
      setSearch('');
      setPage(0);
    }
    wasOpen.current = open;
  }, [open, initialSelection]);

  // Debounce free-typed search text into the fetched query.
  useEffect(() => {
    if (!open) {
      return;
    }
    const timer = setTimeout(() => {
      setSearch(searchInput);
      setPage(0);
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [searchInput, open]);

  useEffect(() => {
    if (!open) {
      return;
    }
    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    browseMedia(mediaType, { search: search || undefined, page })
      .then((response) => {
        if (!cancelled) {
          setItems(response.items);
          setPager(response.pager);
        }
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setLoadError(formatEndpointError(error));
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [open, mediaType, search, page]);

  const addToSelection = (item: MediaSelectionItem) => {
    setSelection((current) => {
      if (current.some((selected) => String(selected.id) === String(item.id))) {
        return current;
      }
      if (maxItems === 1) {
        return [item];
      }
      if (maxItems !== null && current.length >= maxItems) {
        return current;
      }
      return [...current, item];
    });
  };

  const toggleItem = (item: MediaBrowseItem) => {
    const key = String(item.id);
    if (selection.some((selected) => String(selected.id) === key)) {
      setSelection((current) =>
        current.filter((selected) => String(selected.id) !== key),
      );
      return;
    }
    addToSelection(toSelectionItem(item));
  };

  const handleFileChosen = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    // Reset the input so re-selecting the same file re-fires the event.
    event.target.value = '';
    if (!file) {
      return;
    }
    // Hold the file and collect alternative text before posting; the upload
    // endpoint accepts `alt`, and image media types commonly require it.
    setPendingFile(file);
    setPendingAlt('');
    setUploadError(null);
  };

  const cancelPendingUpload = () => {
    setPendingFile(null);
    setPendingAlt('');
    setUploadError(null);
  };

  const handleUpload = async () => {
    const alt = pendingAlt.trim();
    if (!pendingFile || alt === '') {
      return;
    }
    setUploading(true);
    setUploadError(null);
    try {
      const response = await uploadMedia(mediaType, pendingFile, { alt });
      const item: MediaBrowseItem = {
        id: response.id,
        uuid: response.uuid,
        // The upload response carries no label; the file name matches what
        // Drupal names the media entity by default.
        label: pendingFile.name,
        thumbnailUrl: response.inputs_resolved?.src ?? null,
        inputs_resolved: response.inputs_resolved,
      };
      setItems((current) => [
        item,
        ...current.filter(
          (existing) => String(existing.id) !== String(item.id),
        ),
      ]);
      addToSelection(toSelectionItem(item));
      setPendingFile(null);
      setPendingAlt('');
    } catch (error) {
      setUploadError(formatEndpointError(error));
    } finally {
      setUploading(false);
    }
  };

  const handleInsert = () => {
    onInsert(selection);
    onOpenChange(false);
  };

  const totalPages = pager
    ? Math.max(1, Math.ceil(pager.total / pager.perPage))
    : 1;

  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title="Add media"
      width="480px"
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Insert',
        onConfirm: handleInsert,
      }}
    >
      <Flex direction="column" gap="2">
        <Flex gap="2" align="center">
          <Box flexGrow="1">
            <TextField
              attributes={{
                type: 'search',
                placeholder: 'Search media',
                'aria-label': 'Search media',
                value: searchInput,
                onChange: (event: React.ChangeEvent<HTMLInputElement>) =>
                  setSearchInput(event.target.value),
              }}
            />
          </Box>
          <Button
            size="1"
            variant="soft"
            disabled={uploading}
            onClick={() => fileInputRef.current?.click()}
          >
            <UploadIcon /> Upload
          </Button>
          <input
            ref={fileInputRef}
            type="file"
            hidden
            onChange={handleFileChosen}
            data-testid="canvas-media-upload-input"
          />
        </Flex>
        {pendingFile && (
          <Flex
            direction="column"
            gap="1"
            data-testid="canvas-media-upload-form"
          >
            <Text size="1" title={pendingFile.name} style={tileLabelStyle}>
              {pendingFile.name}
            </Text>
            <DialogFieldLabel htmlFor={altInputId}>
              Alternative text
            </DialogFieldLabel>
            <TextField
              attributes={{
                id: altInputId,
                type: 'text',
                required: true,
                autoFocus: true,
                value: pendingAlt,
                'data-testid': 'canvas-media-upload-alt',
                onChange: (event: React.ChangeEvent<HTMLInputElement>) =>
                  setPendingAlt(event.target.value),
                onKeyDown: (event: React.KeyboardEvent<HTMLInputElement>) => {
                  if (event.key === 'Enter') {
                    event.preventDefault();
                    void handleUpload();
                  }
                },
              }}
            />
            <Flex gap="2" justify="end">
              <Button
                size="1"
                variant="soft"
                disabled={uploading}
                onClick={cancelPendingUpload}
              >
                Cancel
              </Button>
              <Button
                size="1"
                loading={uploading}
                disabled={pendingAlt.trim() === ''}
                onClick={() => void handleUpload()}
              >
                Upload
              </Button>
            </Flex>
          </Flex>
        )}
        {uploadError && (
          <Text size="1" color="red">
            {uploadError}
          </Text>
        )}
        {loadError && (
          <Text size="1" color="red">
            {loadError}
          </Text>
        )}
        {loading ? (
          <Flex justify="center" py="4">
            <Spinner />
          </Flex>
        ) : (
          <Grid columns="4" gap="2">
            {items.map((item) => {
              const isSelected = selection.some(
                (selected) => String(selected.id) === String(item.id),
              );
              return (
                <button
                  key={String(item.id)}
                  type="button"
                  aria-pressed={isSelected}
                  style={isSelected ? tileSelectedStyle : tileStyle}
                  onClick={() => toggleItem(item)}
                >
                  {item.thumbnailUrl ? (
                    <img src={item.thumbnailUrl} alt="" style={thumbStyle} />
                  ) : (
                    <Flex align="center" justify="center" style={thumbStyle}>
                      <ImageIcon />
                    </Flex>
                  )}
                  <Text size="1" style={tileLabelStyle} title={item.label}>
                    {item.label}
                  </Text>
                </button>
              );
            })}
          </Grid>
        )}
        {!loading && !loadError && items.length === 0 && (
          <Text size="1" color="gray">
            No media found.
          </Text>
        )}
        <Flex justify="between" align="center">
          <Button
            size="1"
            variant="ghost"
            disabled={page === 0 || loading}
            onClick={() => setPage((current) => current - 1)}
          >
            Previous
          </Button>
          <Text size="1" color="gray">
            Page {page + 1} of {totalPages}
          </Text>
          <Button
            size="1"
            variant="ghost"
            disabled={page + 1 >= totalPages || loading}
            onClick={() => setPage((current) => current + 1)}
          >
            Next
          </Button>
        </Flex>
      </Flex>
    </Dialog>
  );
};

export default MediaBrowser;
