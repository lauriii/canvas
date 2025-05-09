import { camelCase } from 'lodash';

export default function getStarterComponentTemplate(componentName: string) {
  const camelCased = camelCase(componentName);
  const variableName = camelCased.charAt(0).toUpperCase() + camelCased.slice(1);

  return `// Use React/Preact (https://preactjs.com) and Tailwind CSS 4
// (https://tailwindcss.com).
// Global CSS is added to all pages with a @theme directive.
// Tailwind theme variables must be added in Global CSS.
// @see https://tailwindcss.com/docs/theme.
// Do not include @import "tailwindcss"!

// Available third party packages:
// import { clsx } from 'clsx'
// import { cva } from 'class-variance-authority'
// import { twMerge } from 'tailwind-merge'

// Import your other code components to use within this component:
// import Heading from '@/components/my_heading'

// Use the built-in FormattedText component to render text with trusted HTML.
// @see https://git.drupalcode.org/project/experience_builder/-/blob/0.x/ui/lib/astro-hydration/src/lib/FormattedText.tsx
// @see https://react.dev/reference/react-dom/components/common#dangerously-setting-the-inner-html
// The content is safe when processed through Drupal's filter system that is correctly configured.
// @see https://www.drupal.org/docs/administering-a-drupal-site/security-in-drupal/configuring-text-formats-aka-input-formats-for-security
import FormattedText from "@/lib/FormattedText";
// Combine classes with the built-in cn() utility function.
// @see https://git.drupalcode.org/project/experience_builder/-/blob/0.x/ui/lib/astro-hydration/src/lib/utils.ts
import { cn } from "@/lib/utils";

const ${variableName} = ({
  // List component props and slots here. To expose them on the UI, add them
  // under "Component data".
  // The camelCased prop/slot name must match the argument name here
  // (eg. "Card variant" → "cardVariant").
  title = "<h3>${componentName}</h3>",
}) => {
  return (
    <div className="max-w-sm min-h-36 p-2 font-bold rounded-lg text-2xl gap-4 relative mx-auto flex w-full flex-col items-center justify-center bg-[#ffc423] text-[#12285f]">
      <ControlDots className="top-4 left-4 stroke-white absolute" />
      <FormattedText>{title}</FormattedText>
    </div>
  );
};

export default ${variableName};

const ControlDots = ({ className }) => (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 31 9"
    fill="none"
    strokeWidth="2"
    className={cn("w-12", className)}
  >
    <ellipse cx="4.13" cy="4.97" rx="3.13" ry="2.97" />
    <ellipse cx="15.16" cy="4.97" rx="3.13" ry="2.97" />
    <ellipse cx="26.19" cy="4.97" rx="3.13" ry="2.97" />
  </svg>
);
`;
}
