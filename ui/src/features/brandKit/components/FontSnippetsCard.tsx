import { CopyIcon, InfoCircledIcon } from '@radix-ui/react-icons';
import { Box, Callout, Flex, IconButton, Text } from '@radix-ui/themes';

import {
  buildFontFaceSnippet,
  buildFontVariantName,
  buildTailwindHtmlSnippet,
  buildTailwindThemeSnippet,
  isVariableFont,
} from '@/features/brandKit/fontCss';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontSnippetsCardProps = {
  copiedSnippetId: string | null;
  font: AssetLibraryFont;
  isBusy: boolean;
  onCopySnippet: (text: string, snippetId: string) => Promise<void>;
};

type CodeBlockProps = {
  code: string;
  copiedSnippetId: string | null;
  isBusy: boolean;
  label: string;
  onCopySnippet: (text: string, snippetId: string) => Promise<void>;
  snippetId: string;
  subheading?: string;
  testId: string;
};

const CodeBlock = ({
  code,
  copiedSnippetId,
  isBusy,
  label,
  onCopySnippet,
  snippetId,
  subheading,
  testId,
}: CodeBlockProps) => (
  <Flex direction="column" gap="1">
    {subheading && (
      <Text as="span" size="1" weight="medium" color="gray">
        {subheading}
      </Text>
    )}
    <Box className={styles.codeBlock}>
      <pre data-testid={testId} className={styles.codeBlockPre}>
        {code}
      </pre>
      <Box className={styles.codeBlockCopyButton}>
        <IconButton
          className={styles.codeBlockCopyIcon}
          variant="ghost"
          size="1"
          disabled={isBusy}
          aria-label={`Copy ${label}`}
          onClick={() => void onCopySnippet(code, snippetId)}
        >
          <CopyIcon />
        </IconButton>
      </Box>
      <Text as="span" role="status" className="visually-hidden">
        {copiedSnippetId === snippetId ? `${label} copied` : ''}
      </Text>
    </Box>
  </Flex>
);

/**
 * The example code for the selected font, scoped to how it is currently set up.
 *
 * Generates code snippets matching the current font configuration (live sliders
 * for variable fonts, selected variant for static fonts). A callout clarifies
 * the state as snippets update in real time.
 */
const FontSnippetsCard = ({
  copiedSnippetId,
  font,
  isBusy,
  onCopySnippet,
}: FontSnippetsCardProps) => {
  const sharedProps = { copiedSnippetId, isBusy, onCopySnippet };

  return (
    <>
      <Callout.Root
        color="blue"
        size="1"
        data-testid="canvas-brand-kit-font-code-context"
      >
        <Callout.Icon>
          <InfoCircledIcon />
        </Callout.Icon>
        <Callout.Text>
          {isVariableFont(font) ? (
            <>
              <Text as="span" weight="medium">
                Example code
              </Text>
              <br />
              Based on current slider settings
            </>
          ) : (
            <>Variant: {buildFontVariantName(font)}</>
          )}
        </Callout.Text>
      </Callout.Root>

      <Flex direction="column" gap="2" className={styles.consoleSection}>
        <Text size="2" weight="medium">
          Tailwind CSS
        </Text>
        <CodeBlock
          {...sharedProps}
          code={buildTailwindThemeSnippet(font)}
          label="Tailwind theme declaration"
          subheading="Theme declaration"
          snippetId={`${font.id}:css`}
          testId={`canvas-brand-kit-font-theme-snippet-${font.id}`}
        />
        <CodeBlock
          {...sharedProps}
          code={buildTailwindHtmlSnippet(font)}
          label="Tailwind usage example"
          subheading="HTML usage example"
          snippetId={`${font.id}:html`}
          testId={`canvas-brand-kit-font-snippet-${font.id}`}
        />
      </Flex>

      <Flex direction="column" gap="2" className={styles.consoleSection}>
        <Text size="2" weight="medium">
          CSS @font-face
        </Text>
        <CodeBlock
          {...sharedProps}
          code={buildFontFaceSnippet(font)}
          label="CSS @font-face rule"
          snippetId={`${font.id}:font-face`}
          testId={`canvas-brand-kit-font-face-snippet-${font.id}`}
        />
      </Flex>
    </>
  );
};

export default FontSnippetsCard;
