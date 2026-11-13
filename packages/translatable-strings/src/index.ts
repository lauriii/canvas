export {
  analyzeModule,
  getTranslatableStringsFromAst,
  getTranslatableStringsFromSource,
  normalizeTranslatableStrings,
} from './extract';
export type {
  ModuleAnalysis,
  TranslatableString,
  TranslatableStringError,
} from './extract';
export { getComponentTranslatableStrings } from './graph';
export type {
  ComponentImportResolver,
  ComponentModule,
  ComponentTranslatableStrings,
  ModuleTranslatableStringError,
} from './graph';
