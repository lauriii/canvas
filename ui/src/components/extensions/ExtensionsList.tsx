import { Flex, Heading, Link, Grid } from '@radix-ui/themes';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import ExtensionButton from '@/components/extensions/ExtensionButton';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import type React from 'react';

interface ExtensionsPopoverProps {}

const { drupalSettings } = window;

const ExtensionsList: React.FC<ExtensionsPopoverProps> = () => {
  let extensionsList = [];
  if (drupalSettings && drupalSettings.xbExtension) {
    extensionsList = Object.values(drupalSettings.xbExtension).map((value) => {
      return {
        ...value,
        imgSrc:
          value.imgSrc ||
          `${drupalSettings.path.baseUrl}${drupalSettings.xb.xbModulePath}/ui/assets/icons/extension-default-abstract.svg`,
        name: value.name,
        description: value.description,
      };
    });
  }

  return <ExtensionsListDisplay extensions={extensionsList || []} />;
};

interface ExtensionsListDisplayProps {
  extensions: Array<any>;
}

const ExtensionsListDisplay: React.FC<ExtensionsListDisplayProps> = ({
  extensions,
}) => {
  return (
    <>
      <Flex justify="between">
        <Heading as="h3" size="3" mb="4">
          Extensions
        </Heading>

        <Flex justify="end" asChild>
          <Link
            size="1"
            href=""
            target="_blank"
            onClick={(e: React.MouseEvent<HTMLAnchorElement>) => {
              e.preventDefault();
              handleNonWorkingBtn();
            }}
          >
            Browse extensions&nbsp; <ExternalLinkIcon />
          </Link>
        </Flex>
      </Flex>

      {extensions.length > 0 && (
        <Grid columns="3" gap="3">
          {extensions.map((extension) => (
            <ExtensionButton extension={extension} key={extension.id} />
          ))}
        </Grid>
      )}
      {extensions?.length === 0 && (
        <Flex justify="center">
          <p>No extensions found</p>
        </Flex>
      )}
    </>
  );
};

export { ExtensionsListDisplay };

export default ExtensionsList;
