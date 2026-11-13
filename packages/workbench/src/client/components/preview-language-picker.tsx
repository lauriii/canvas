import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@wb/client/components/ui/select';

import type { InterfaceLanguage } from '@wb/lib/preview-translation';

/**
 * Selects the interface language that the preview translates into.
 */
export function PreviewLanguagePicker({
  languages,
  langcode,
  onSelect,
}: {
  languages: InterfaceLanguage[];
  langcode: string;
  onSelect: (langcode: string) => void;
}) {
  const selectId = 'canvas-preview-language-picker';
  return (
    <div className="flex items-center gap-2 border p-2 text-xs">
      <label htmlFor={selectId} className="text-muted-foreground">
        Language:
      </label>
      <Select
        items={languages.map(({ langcode: value, label }) => ({
          label,
          value,
        }))}
        value={langcode}
        onValueChange={(value) => {
          if (typeof value === 'string') {
            onSelect(value);
          }
        }}
      >
        <SelectTrigger id={selectId}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectGroup>
            {languages.map(({ langcode: value, label }) => (
              <SelectItem key={value} value={value}>
                {label}
              </SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
    </div>
  );
}
