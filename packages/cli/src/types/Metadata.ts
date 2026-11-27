import type { CodeComponentSerialized } from '@drupal-canvas/ui/types/CodeComponent';

export interface Metadata extends Pick<
  CodeComponentSerialized,
  | 'name'
  | 'machineName'
  | 'status'
  | 'required'
  | 'slots'
  | 'styles'
  | 'styleParts'
> {
  props: {
    properties: CodeComponentSerialized['props'];
  };
  dataDependencies?: CodeComponentSerialized['dataDependencies'];
}
