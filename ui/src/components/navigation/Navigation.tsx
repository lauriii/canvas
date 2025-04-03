import type { ContentStub } from '@/types/Content';
import {
  AlertDialog,
  Box,
  Button,
  Callout,
  DropdownMenu,
  Flex,
  Heading,
  IconButton,
  ScrollArea,
  Text,
  TextField,
} from '@radix-ui/themes';
import {
  Component1Icon,
  DotsVerticalIcon,
  FileIcon,
  InfoCircledIcon,
  MagnifyingGlassIcon,
  PlusIcon,
  ChevronDownIcon,
} from '@radix-ui/react-icons';
import styles from './Navigation.module.css';
import type { FormEvent } from 'react';

const ContentGroup = ({
  title,
  items,
  onSelect,
  onRename,
  onDuplicate,
  onSetHomepage,
  onDelete,
}: {
  title: string;
  items: ContentStub[];
  onSelect?: (value: ContentStub) => void;
  onRename?: (page: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  if (items.length === 0) {
    return (
      <Callout.Root size="1" color="gray">
        <Callout.Icon>
          <InfoCircledIcon />
        </Callout.Icon>
        <Callout.Text>No pages found.</Callout.Text>
      </Callout.Root>
    );
  }

  return (
    <div>
      <Heading as="h5" size="1" color="gray">
        {title}
      </Heading>
      <Flex direction="column" gap="2" mt="2">
        {items.map((item) => {
          return (
            <Flex
              direction={'row'}
              align={'center'}
              mr="4"
              p="1"
              pr="2"
              className={styles.item}
              key={item.id}
            >
              <Flex
                className={styles.pageLink}
                flexGrow="1"
                onClick={onSelect ? () => onSelect(item) : undefined}
              >
                <Box px="3">
                  <FileIcon />
                </Box>
                <Flex flexGrow="1" align="center">
                  <Text as="span" size="1">
                    {item.autoSaveLabel || item.title}{' '}
                    <span className={styles.path}>
                      {item.autoSavePath || item.path}
                    </span>
                  </Text>
                </Flex>
              </Flex>
              <DropdownMenu.Root>
                <DropdownMenu.Trigger>
                  <IconButton
                    variant="ghost"
                    color="gray"
                    className={styles.optionsButton}
                    aria-label={`Page options for ${item.title}`}
                  >
                    <DotsVerticalIcon />
                  </IconButton>
                </DropdownMenu.Trigger>
                <DropdownMenu.Content>
                  <DropdownMenu.Item
                    onClick={(event) => event.stopPropagation()}
                    onSelect={onRename ? () => onRename(item) : undefined}
                  >
                    Rename page
                  </DropdownMenu.Item>
                  <DropdownMenu.Item
                    onClick={(event) => event.stopPropagation()}
                    onSelect={onDuplicate ? () => onDuplicate(item) : undefined}
                  >
                    Duplicate page
                  </DropdownMenu.Item>
                  <DropdownMenu.Separator />
                  <AlertDialog.Root>
                    <AlertDialog.Trigger>
                      <DropdownMenu.Item
                        onClick={(event) => event.stopPropagation()}
                        onSelect={(event) => event.preventDefault()}
                      >
                        Set as homepage
                      </DropdownMenu.Item>
                    </AlertDialog.Trigger>
                    <AlertDialog.Content>
                      <AlertDialog.Title>
                        Set {item.title} as homepage
                      </AlertDialog.Title>
                      <AlertDialog.Description size="2">
                        This action will set the selected page as homepage. This
                        action cannot be undone.
                      </AlertDialog.Description>
                      <Flex gap="3" mt="4" justify="end">
                        <AlertDialog.Cancel>
                          <Button variant="soft" color="gray">
                            Cancel
                          </Button>
                        </AlertDialog.Cancel>
                        <AlertDialog.Action>
                          <DropdownMenu.Item
                            onClick={(event) => event.stopPropagation()}
                            onSelect={() =>
                              onSetHomepage ? onSetHomepage(item) : undefined
                            }
                          >
                            <Button variant="solid" color="blue">
                              Set as homepage
                            </Button>
                          </DropdownMenu.Item>
                        </AlertDialog.Action>
                      </Flex>
                    </AlertDialog.Content>
                  </AlertDialog.Root>
                  <DropdownMenu.Separator />
                  <AlertDialog.Root>
                    <AlertDialog.Trigger>
                      <DropdownMenu.Item
                        onClick={(event) => event.stopPropagation()}
                        onSelect={(event) => event.preventDefault()}
                      >
                        Delete page
                      </DropdownMenu.Item>
                    </AlertDialog.Trigger>
                    <AlertDialog.Content>
                      <AlertDialog.Title>
                        Delete {item.title} page
                      </AlertDialog.Title>
                      <AlertDialog.Description size="2">
                        This action will permanently delete the page and all of
                        it’s contents. This action cannot be undone.
                      </AlertDialog.Description>
                      <Flex gap="3" mt="4" justify="end">
                        <AlertDialog.Cancel>
                          <Button variant="soft" color="gray">
                            Cancel
                          </Button>
                        </AlertDialog.Cancel>
                        <AlertDialog.Action>
                          <DropdownMenu.Item
                            onClick={(event) => event.stopPropagation()}
                            onSelect={() =>
                              onDelete ? onDelete(item) : undefined
                            }
                          >
                            <Button variant="solid" color="red">
                              Delete page
                            </Button>
                          </DropdownMenu.Item>
                        </AlertDialog.Action>
                      </Flex>
                    </AlertDialog.Content>
                  </AlertDialog.Root>
                </DropdownMenu.Content>
              </DropdownMenu.Root>
            </Flex>
          );
        })}
      </Flex>
    </div>
  );
};

const Navigation = ({
  loading = false,
  items = [],
  onNewPage,
  onSearch,
  onSelect,
  onRename,
  onDuplicate,
  onSetHomepage,
  onDelete,
}: {
  loading: boolean;
  items: ContentStub[];
  onNewPage?: () => void;
  onSearch?: (value: string) => void;
  onSelect?: (value: ContentStub) => void;
  onRename?: (page: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  return (
    <div data-testid="xb-navigation-content">
      <Flex direction="row" gap="2" mb="4">
        <form
          className={styles.search}
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            const form = event.currentTarget;
            const formElements = form.elements as typeof form.elements & {
              'xb-navigation-search': HTMLInputElement;
            };
            onSearch?.(formElements['xb-navigation-search'].value);
          }}
        >
          <TextField.Root
            id="xb-navigation-search"
            placeholder="Search…"
            radius="medium"
            size="1"
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
        </form>
        <DropdownMenu.Root>
          <DropdownMenu.Trigger>
            <Button
              variant="soft"
              data-testid="xb-navigation-new-button"
              size="1"
            >
              <PlusIcon />
              New
              <ChevronDownIcon />
            </Button>
          </DropdownMenu.Trigger>
          <DropdownMenu.Content>
            <DropdownMenu.Item
              onClick={onNewPage}
              data-testid="xb-navigation-new-page-button"
            >
              <FileIcon />
              New page
            </DropdownMenu.Item>
            <DropdownMenu.Item>
              <Component1Icon />
              New component
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Root>
      </Flex>
      <ScrollArea scrollbars="vertical" style={{ height: 175 }}>
        {loading && <p>Loading...</p>}
        {!loading && (
          <ContentGroup
            title="Pages"
            items={items}
            onSelect={onSelect}
            onRename={onRename}
            onDuplicate={onDuplicate}
            onSetHomepage={onSetHomepage}
            onDelete={onDelete}
          />
        )}
      </ScrollArea>
    </div>
  );
};
export default Navigation;
