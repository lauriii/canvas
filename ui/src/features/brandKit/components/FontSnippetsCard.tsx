import { CopyIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import {
  buildTailwindHtmlSnippet,
  buildTailwindThemeSnippet,
} from '@/features/brandKit/fontCss';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontSnippetsCardProps = {
  copiedSnippetId: string | null;
  font: AssetLibraryFont;
  isBusy: boolean;
  onCopySnippet: (text: string, snippetId: string) => Promise<void>;
};

const FontSnippetsCard = ({
  copiedSnippetId,
  font,
  isBusy,
  onCopySnippet,
}: FontSnippetsCardProps) => {
  const themeSnippet = buildTailwindThemeSnippet(font);
  const htmlSnippet = buildTailwindHtmlSnippet(font);

  return (
    <>
      <Flex direction="column" gap="2">
        <Text size="1" color="gray">
          Tailwind theme (CSS)
        </Text>
        <Box className={styles.snippetWrapper}>
          <Box className={styles.snippetBox}>
            <pre
              data-testid={`canvas-brand-kit-font-theme-snippet-${font.id}`}
              className={styles.snippetPre}
            >
              {themeSnippet}
            </pre>
          </Box>
          <Box className={styles.snippetCopyButton}>
            <Button
              size="1"
              variant="solid"
              onClick={() => void onCopySnippet(themeSnippet, `${font.id}:css`)}
              disabled={isBusy}
            >
              <CopyIcon />
              {copiedSnippetId === `${font.id}:css` ? 'Copied' : 'Copy CSS'}
            </Button>
          </Box>
        </Box>
      </Flex>
      <Flex direction="column" gap="2">
        <Text size="1" color="gray">
          HTML example
        </Text>
        <Box className={styles.snippetWrapper}>
          <Box className={styles.snippetBox}>
            <pre
              data-testid={`canvas-brand-kit-font-snippet-${font.id}`}
              className={styles.snippetPre}
            >
              {htmlSnippet}
            </pre>
          </Box>
          <Box className={styles.snippetCopyButton}>
            <Button
              size="1"
              variant="solid"
              onClick={() => void onCopySnippet(htmlSnippet, `${font.id}:html`)}
              disabled={isBusy}
            >
              <CopyIcon />
              {copiedSnippetId === `${font.id}:html` ? 'Copied' : 'Copy HTML'}
            </Button>
          </Box>
        </Box>
      </Flex>
    </>
  );
};

export default FontSnippetsCard;
