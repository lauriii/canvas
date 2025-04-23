import { IconButton, Popover, Tooltip } from '@radix-ui/themes';
import { PinBottomIcon } from '@radix-ui/react-icons';
import styles from '@/features/code-editor/Import.module.css';
import CodeComponentImports from '@/features/code-editor/CodeComponentImports';
import Panel from '@/components/Panel';
import ErrorBoundary from '@/components/error/ErrorBoundary';

const ImportButton = () => {
  return (
    <Popover.Root>
      <Tooltip content="Import components">
        <Popover.Trigger>
          <IconButton
            aria-label="Import components"
            radius="full"
            data-testid="xb-import-button"
            className={styles.importButton}
          >
            <PinBottomIcon />
          </IconButton>
        </Popover.Trigger>
      </Tooltip>
      <Popover.Content width="100vw" maxWidth="550px" asChild align="center">
        <Panel className="xb-app">
          <ErrorBoundary
            title={`An unexpected error has occurred while fetching code components.`}
          >
            <CodeComponentImports />
          </ErrorBoundary>
        </Panel>
      </Popover.Content>
    </Popover.Root>
  );
};

export default ImportButton;
