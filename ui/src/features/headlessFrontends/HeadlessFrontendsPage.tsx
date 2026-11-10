import { useState } from 'react';
import { toast } from 'sonner';
import { arrayMove } from '@dnd-kit/sortable';
import { GearIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Heading, Spinner, Text } from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import ErrorCard from '@/components/error/ErrorCard';
import {
  useGetFrontendsQuery,
  useSetFrontendsMutation,
} from '@/services/headlessFrontends';
import { setCanvasHeadlessFrontends } from '@/utils/drupal-globals';

import AddFrontendDialog from './AddFrontendDialog';
import FrontendsList from './FrontendsList';
import RemoveFrontendDialog from './RemoveFrontendDialog';
import SetupGuide from './SetupGuide';

import type {
  ConnectionStatus,
  HeadlessFrontend,
  PackageManager,
} from './types';

import styles from './HeadlessFrontends.module.css';

const HeadlessFrontendsPage = () => {
  const { data: storedFrontends, isLoading, error } = useGetFrontendsQuery();
  const [setFrontends, { isLoading: isSaving }] = useSetFrontendsMutation();
  const [isAddDialogOpen, setIsAddDialogOpen] = useState(false);
  const [isSetupGuideOpen, setIsSetupGuideOpen] = useState(false);
  const [frontendToRemove, setFrontendToRemove] =
    useState<HeadlessFrontend | null>(null);
  // The status checks are client-side: the browser probes each frontend, so
  // statuses live next to the component instead of in the stored config.
  const [statuses, setStatuses] = useState<Record<string, ConnectionStatus>>(
    {},
  );
  // One package manager choice drives every command snippet on the page.
  const [packageManager, setPackageManager] = useState<PackageManager>('npm');

  // The URL doubles as the row id since the API refuses duplicates.
  const frontends: HeadlessFrontend[] = (storedFrontends ?? []).map(
    (frontend) => ({
      id: frontend.url,
      url: frontend.url,
      status: statuses[frontend.url] ?? 'checking',
    }),
  );

  // The setup content is one-time guidance: it is part of the page only until
  // the first frontend is connected, and lives in the setup guide dialog
  // afterwards.
  const hasFrontends = frontends.length > 0;

  const saveList = async (list: HeadlessFrontend[]) => {
    const storedFrontendsByUrl = new Map(
      (storedFrontends ?? []).map((frontend) => [frontend.url, frontend]),
    );
    const savedFrontends = await setFrontends(
      list.map((frontend) => ({
        url: frontend.url,
        components: storedFrontendsByUrl.get(frontend.url)?.components ?? [],
      })),
    ).unwrap();
    setCanvasHeadlessFrontends(savedFrontends.map((frontend) => frontend.url));
  };

  const handleAdd = async (url: string) => {
    await saveList([...frontends, { id: url, url, status: 'checking' }]);
  };

  const handleReorder = (oldIndex: number, newIndex: number) => {
    void saveList(arrayMove(frontends, oldIndex, newIndex)).catch(() => {
      toast.error('The frontend order could not be saved. Please try again.');
    });
  };

  const handleRemoveConfirm = async () => {
    try {
      await saveList(
        frontends.filter((item) => item.id !== frontendToRemove?.id),
      );
      // Dropping the status entry makes a later re-add of the same URL start
      // from a fresh check.
      setStatuses(({ [frontendToRemove?.url ?? '']: _, ...rest }) => rest);
      setFrontendToRemove(null);
    } catch {
      toast.error('The frontend could not be removed. Please try again.');
    }
  };

  const handleStatusChange = (id: string, status: ConnectionStatus) => {
    setStatuses((current) => ({ ...current, [id]: status }));
  };

  return (
    <Box
      width="100%"
      className={styles.page}
      data-testid="canvas-headless-frontends-page"
    >
      <Box maxWidth="760px" mx="auto" px="6" py="6">
        <Flex direction="column" gap="7">
          <Flex justify="between" align="start" gap="3">
            <Flex direction="column" gap="2">
              <Heading as="h1" size="5">
                Headless frontends
              </Heading>
              <Text size="2" color="gray">
                Connect the frontend apps that render your Drupal Canvas
                content, and get your codebase set up.
              </Text>
            </Flex>
            {hasFrontends && (
              <Button
                variant="ghost"
                color="gray"
                mt="1"
                onClick={() => setIsSetupGuideOpen(true)}
                data-testid="canvas-headless-setup-guide-button"
              >
                <GearIcon />
                Setup guide
              </Button>
            )}
          </Flex>

          <Flex direction="column" gap="3">
            <Heading as="h2" size="3">
              Frontends
            </Heading>
            {isLoading && (
              <Flex width="100%" justify="center" py="6">
                <Spinner size="3" loading={true} />
              </Flex>
            )}
            {!isLoading && error !== undefined && (
              <ErrorCard
                title="Failed to load the frontends."
                error="Please contact your site administrator if you believe this is an error."
              />
            )}
            {!isLoading && error === undefined && (
              <FrontendsList
                frontends={frontends}
                onAdd={() => setIsAddDialogOpen(true)}
                onReorder={handleReorder}
                onRemove={(id) =>
                  setFrontendToRemove(
                    frontends.find((item) => item.id === id) ?? null,
                  )
                }
                onStatusChange={handleStatusChange}
                onOpenSetupGuide={() => setIsSetupGuideOpen(true)}
                disabled={isSaving}
              />
            )}
          </Flex>

          {!isLoading && error === undefined && !hasFrontends && (
            <SetupGuide
              packageManager={packageManager}
              onPackageManagerChange={setPackageManager}
            />
          )}
        </Flex>
      </Box>
      <AddFrontendDialog
        open={isAddDialogOpen}
        onOpenChange={setIsAddDialogOpen}
        onAdd={handleAdd}
        existingUrls={frontends.map((frontend) => frontend.url)}
      />
      <RemoveFrontendDialog
        frontend={frontendToRemove}
        onOpenChange={(open) => {
          if (!open) {
            setFrontendToRemove(null);
          }
        }}
        onConfirm={() => void handleRemoveConfirm()}
        isRemoving={isSaving}
      />
      <Dialog
        open={isSetupGuideOpen}
        onOpenChange={setIsSetupGuideOpen}
        title="Setup guide"
        width="640px"
        headerClose
        footer={{ hidden: true }}
      >
        <Box pt="2">
          <SetupGuide
            packageManager={packageManager}
            onPackageManagerChange={setPackageManager}
          />
        </Box>
      </Dialog>
    </Box>
  );
};

export default HeadlessFrontendsPage;
