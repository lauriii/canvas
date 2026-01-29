import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { setConfig } from '../config';
import { installTestSite, tearDownTestSite } from '../utils/testing';
import { createApiService } from './api';

const isConfigured =
  process.env.DRUPAL_TEST_BASE_URL !== undefined &&
  process.env.DRUPAL_TEST_DB_URL !== undefined &&
  process.env.TEST_CANVAS_CLIENT_ID !== undefined &&
  process.env.TEST_CANVAS_CLIENT_SECRET !== undefined;

describe.runIf(isConfigured)('api service integration', () => {
  let dbPrefix: string;
  beforeAll(
    async () => {
      const installData = await installTestSite();
      dbPrefix = installData.db_prefix;

      setConfig({
        siteUrl: process.env.DRUPAL_TEST_BASE_URL || '',
        clientId: process.env.TEST_CANVAS_CLIENT_ID || '',
        clientSecret: process.env.TEST_CANVAS_CLIENT_SECRET || '',
        userAgent: installData.user_agent,
      });
    },
    60 * 60 * 1000,
  );

  afterAll(
    async () => {
      if (dbPrefix) {
        await tearDownTestSite(dbPrefix);
      }
    },
    60 * 60 * 1000,
  );

  it('should list components', async () => {
    const apiService = await createApiService();
    const components = await apiService.listComponents();
    expect(components).toBeDefined();
    expect(Object.keys(components)).toEqual([
      'canvas_test_code_components_captioned_video',
      'canvas_test_code_components_interactive',
      'canvas_test_code_components_using_drupalsettings_get_site_data',
      'canvas_test_code_components_using_get_page_data',
      'canvas_test_code_components_using_imports',
      'canvas_test_code_components_vanilla_image',
      'canvas_test_code_components_with_enums',
      'canvas_test_code_components_with_link_prop',
      'canvas_test_code_components_with_no_props',
      'canvas_test_code_components_with_props',
      'canvas_test_code_components_with_slots',
    ]);
  });

  it('should allow updating a component', async () => {
    const apiService = await createApiService();
    const machineName = 'canvas_test_code_components_with_props';
    const component = await apiService.getComponent(machineName);
    expect(component).toBeDefined();
    expect(component.machineName).toBe(
      'canvas_test_code_components_with_props',
    );

    const updatedComponent = await apiService.updateComponent(machineName, {
      sourceCodeCss: 'div { font-weight: bold; font-size: 20px; }',
    });
    expect(updatedComponent.sourceCodeCss).toBe(
      'div { font-weight: bold; font-size: 20px; }',
    );

    const restoredComponent = await apiService.updateComponent(machineName, {
      sourceCodeCss: component.sourceCodeCss,
    });
    expect(restoredComponent.sourceCodeCss).toBe('div { font-weight: bold; }');

    expect((await apiService.getComponent(machineName)).sourceCodeCss).toBe(
      'div { font-weight: bold; }',
    );
  });

  it('should allow creating a component', async () => {
    const apiService = await createApiService();
    const machineName = 'canvas_test_code_components_temporary_component';
    const newComponent = await apiService.createComponent({
      compiledCss: '',
      compiledJs: '',
      dataDependencies: {},
      importedJsComponents: [],
      props: {},
      required: [],
      slots: {},
      sourceCodeCss: '',
      status: false,
      machineName,
      name: 'Temporary Component',
      sourceCodeJs:
        'export default function TemporaryComponent() { return <div>Temporary Component</div>; }',
    });
    expect(newComponent).toBeDefined();
    expect(newComponent.machineName).toBe(machineName);

    const components = await apiService.listComponents();
    expect(components).toBeDefined();
    expect(Object.keys(components)).toEqual([
      'canvas_test_code_components_captioned_video',
      'canvas_test_code_components_interactive',
      'canvas_test_code_components_temporary_component',
      'canvas_test_code_components_using_drupalsettings_get_site_data',
      'canvas_test_code_components_using_get_page_data',
      'canvas_test_code_components_using_imports',
      'canvas_test_code_components_vanilla_image',
      'canvas_test_code_components_with_enums',
      'canvas_test_code_components_with_link_prop',
      'canvas_test_code_components_with_no_props',
      'canvas_test_code_components_with_props',
      'canvas_test_code_components_with_slots',
    ]);

    // @ts-expect-error allow accessing client directly in the test.
    await apiService.client.delete(
      `/canvas/api/v0/config/js_component/${machineName}`,
    );
  });

  it('should allow updating the global asset library', async () => {
    const apiService = await createApiService();
    const assetLibrary = await apiService.getGlobalAssetLibrary();
    const originalAssetLibrary = assetLibrary;
    expect(assetLibrary).toBeDefined();
    expect(assetLibrary).toEqual({
      id: 'global',
      label: 'Global CSS',
      css: {
        original: '',
        compiled: '',
      },
      js: {
        original: '',
        compiled: '',
      },
    });
    const updatedAssetLibrary = await apiService.updateGlobalAssetLibrary({
      css: {
        original: 'body { background-color: red; }',
        compiled: 'body { background-color: red; }',
      },
      js: {
        original: 'console.log("Hello, world!");',
        compiled: 'console.log("Hello, world!");',
      },
    });
    expect(updatedAssetLibrary.css?.original).toBe(
      'body { background-color: red; }',
    );
    expect(updatedAssetLibrary.js?.original).toBe(
      'console.log("Hello, world!");',
    );

    // Restore original asset library
    const restoredAssetLibrary =
      await apiService.updateGlobalAssetLibrary(originalAssetLibrary);
    expect(restoredAssetLibrary).toEqual(originalAssetLibrary);
  });
});
