import type { Preview } from '@storybook/react';
import { Theme } from "@radix-ui/themes";
import { installDrupalTranslationStub } from '@tests/support/drupal-translation-stub';
import '@/styles/radix-themes';
import '@/styles/index.css';

// Components call Drupal.t() directly, which core/misc/drupal.js provides in
// the browser but nothing provides here.
installDrupalTranslationStub();

const preview: Preview = {
  decorators: [
    (Story) => (
      <Theme
        accentColor="blue"
        hasBackground={false}
        panelBackground="solid"
        appearance="light"
      >
        <div className="canvas-app">
          {Story()}
        </div>
      </Theme>
    )
  ]
};

export default preview;
