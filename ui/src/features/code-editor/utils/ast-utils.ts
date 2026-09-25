import type { File, Node } from '@babel/types';
import type { DataDependencies } from '@/types/CodeComponent';

/** The modules whose members need drupalSettings. */
const DATA_DEPENDENCY_SOURCES = new Set([
  '@/lib/drupal-utils',
  '@drupal-api-client/json-api-client',
  'drupal-canvas',
  'drupal-canvas/react',
  'drupal-canvas/drupal-utils',
  'drupal-canvas/jsonapi-client',
]);

// Keep in sync with the upgrade-path detection in PHP.
// @see \Drupal\canvas\CanvasConfigUpdater::updateJavaScriptComponent()
const DATA_DEPENDENCY_MAP: Record<string, string[]> = {
  JsonApiClient: ['v0.baseUrl', 'v0.jsonapiSettings'],
  getSiteData: ['v0.baseUrl', 'v0.branding'],
  getPageData: ['v0.breadcrumbs', 'v0.pageTitle', 'v0.mainEntity'],
  // The date formatting utilities read the active interface language.
  canvasFormatDate: ['v0.langcode'],
  canvasFormatDateTime: ['v0.langcode'],
  canvasFormatTime: ['v0.langcode'],
  canvasFormatDateRange: ['v0.langcode'],
  useJsonApiClient: ['v0.baseUrl', 'v0.jsonapiSettings'],
  useSiteContext: ['v0.baseUrl', 'v0.branding', 'v0.themeAssets'],
  usePageContext: ['v0.breadcrumbs', 'v0.pageTitle', 'v0.mainEntity'],
};

const isNode = (value: unknown): value is Node =>
  typeof value === 'object' &&
  value !== null &&
  typeof (value as Node).type === 'string';

/**
 * The members of namespace imports (`import * as ns from 'drupal-canvas'`)
 * that are used as `ns.member` anywhere in the file.
 */
const getNamespaceMembersUsed = (ast: File, locals: Set<string>): string[] => {
  if (locals.size === 0) {
    return [];
  }
  const members = new Set<string>();
  const visit = (node: Node) => {
    if (
      (node.type === 'MemberExpression' ||
        node.type === 'OptionalMemberExpression') &&
      node.object.type === 'Identifier' &&
      locals.has(node.object.name) &&
      node.property.type === 'Identifier' &&
      !node.computed
    ) {
      members.add(node.property.name);
    }
    for (const [key, value] of Object.entries(node)) {
      if (key === 'loc' || key.endsWith('Comments')) {
        continue;
      }
      if (Array.isArray(value)) {
        value.forEach((item) => isNode(item) && visit(item));
      } else if (isNode(value)) {
        visit(value);
      }
    }
  };
  visit(ast.program);
  return [...members];
};

/**
 * Extracts import statements from an AST.
 *
 * @param ast - ast object.
 * @param scope - An optional string to filter imports by a specific scope. If provided, only imports starting with this scope are included.
 */
export const getImportsFromAst = (ast: File, scope?: string) =>
  ast.program.body
    .filter((d) => d.type === 'ImportDeclaration')
    .reduce<string[]>((carry, d) => {
      const source = d.source.value;
      if (scope && !source.startsWith(scope)) {
        return carry;
      }
      return [...carry, scope ? source.slice(scope.length) : source];
    }, []);

/**
 * Exports data dependencies from AST.
 */
export const getDataDependenciesFromAst = (ast: File): DataDependencies => {
  const declarations = ast.program.body.filter(
    (d) => d.type === 'ImportDeclaration',
  );
  // Members reached through namespace imports (`ns.usePageContext()`) need
  // the same settings as named imports.
  const namespaceLocals = new Set(
    declarations
      .filter((d) => DATA_DEPENDENCY_SOURCES.has(d.source.value))
      .flatMap((d) => d.specifiers)
      .filter((specifier) => specifier.type === 'ImportNamespaceSpecifier')
      .map((specifier) => specifier.local.name),
  );
  const namespaceMembers = getNamespaceMembersUsed(ast, namespaceLocals);
  return declarations.reduce<DataDependencies>((carry, d, index) => {
    // @todo Parse out any URLs used by the JsonApiClient or fetch in
    //   https://drupal.org/i/3538273
    const source = d.source.value;
    if (!DATA_DEPENDENCY_SOURCES.has(source)) {
      return carry;
    }
    const map = DATA_DEPENDENCY_MAP;
    const drupalSettingsDependencies = carry.drupalSettings || [];
    const computedSettings = drupalSettingsDependencies
      .concat(
        // Namespace members count once, with the first relevant import.
        (index ===
        declarations.findIndex((declaration) =>
          DATA_DEPENDENCY_SOURCES.has(declaration.source.value),
        )
          ? namespaceMembers
          : []
        )
          .filter((item) => Object.keys(map).includes(item))
          .reduce<string[]>(
            (settings, item) =>
              settings.concat(...map[item as keyof typeof map]),
            [],
          ),
      )
      .concat(
        d.specifiers
          // First get the name of the imports from these modules.
          // @see https://github.com/babel/babel/blob/main/packages/babel-parser/ast/spec.md#importdeclaration
          .reduce<string[]>((imports, specifier) => {
            if (!('imported' in specifier)) {
              // This is a default import e.g. 'import Something from "something"'
              // but we don't have default exports in drupal-utils.ts or
              // jsonapi-client.ts, so we can ignore.
              return imports;
            }
            if ('name' in specifier.imported) {
              // Identifier.
              // @see https://github.com/babel/babel/blob/main/packages/babel-parser/ast/spec.md#identifier
              return [...imports, specifier.imported.name];
            }
            if ('value' in specifier.imported) {
              // StringLiteral.
              // @see https://github.com/babel/babel/blob/main/packages/babel-parser/ast/spec.md#stringliteral
              return [...imports, specifier.imported.value];
            }
            return imports;
          }, [])
          // Remove any imports other than getSiteData, getPageData or
          // JsonApiClient - we don't need drupalSettings for anything else.
          .filter((item) => Object.keys(map).includes(item))
          .reduce<string[]>(
            // Expand the dependencies from the map.
            (settings, item) =>
              settings.concat(...map[item as keyof typeof map]),
            [],
          ),
      )
      // Filter to unique values.
      .filter((item, ix, settings) => settings.indexOf(item) === ix);
    return {
      ...carry,
      ...(computedSettings.length > 0 && {
        drupalSettings: computedSettings,
      }),
    };
  }, {});
};
