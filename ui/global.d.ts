interface DrupalSettings {
  xb: {
    base: string;
    entityType: string;
    entity: string;
    global_assets: {
      css: string;
      js_header: string;
      js_footer: string;
    };
    selected_component: string;
    demo_mode: boolean;
  };
  path: {
    baseUrl: string;
  };
}

interface Window {
  drupalSettings: DrupalSettings;
}
