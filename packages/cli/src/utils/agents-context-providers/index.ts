import { contentEntityReferenceExpressionsContextProvider } from './content-entity-reference-expressions';
import { contentEntityReferencePreviewContextProvider } from './content-entity-reference-preview';
import { contentTemplatesContextProvider } from './content-templates';

export default [
  contentTemplatesContextProvider,
  contentEntityReferenceExpressionsContextProvider,
  contentEntityReferencePreviewContextProvider,
];
