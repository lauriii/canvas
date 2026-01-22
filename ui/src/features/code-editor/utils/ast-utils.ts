import type { File } from '@babel/types';
import type { DataDependencies } from '@/types/CodeComponent';

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
export const getDataDependenciesFromAst = (ast: File): DataDependencies =>
  ast.program.body
    .filter((d) => d.type === 'ImportDeclaration')
    .reduce<DataDependencies>((carry, d) => {
      // @todo Parse out any URLs used by the JsonApiClient or fetch in
      //   https://drupal.org/i/3538273
      const source = d.source.value;
      if (
        // Only consider imports from these two modules.
        source !== '@/lib/drupal-utils' &&
        source !== '@drupal-api-client/json-api-client' &&
        source !== 'drupal-canvas'
      ) {
        return carry;
      }
      const map = {
        JsonApiClient: ['v0.baseUrl', 'v0.jsonapiSettings'],
        getSiteData: ['v0.baseUrl', 'v0.branding'],
        getPageData: ['v0.breadcrumbs', 'v0.pageTitle', 'v0.mainEntity'],
      };
      const drupalSettingsDependencies = carry.drupalSettings || [];
      const computedSettings = drupalSettingsDependencies
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
