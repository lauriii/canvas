import type { CodeComponentSerialized } from '@drupal-canvas/ui/types/CodeComponent';

export interface Metadata
  extends Pick<
    CodeComponentSerialized,
    'name' | 'machineName' | 'status' | 'required' | 'slots'
  > {
  props: {
    properties: CodeComponentSerialized['props'];
  };
}
