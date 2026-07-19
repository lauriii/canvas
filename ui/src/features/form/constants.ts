export const FORM_TYPES = {
  COMPONENT_INSTANCE_FORM: 'component_instance_form' as const,
  ENTITY_FORM: 'page_data_form' as const,
  // The stacked reference-editing panel's form: react-hook-form state only,
  // no Redux integration (the pageData slice belongs to the open entity).
  STACKED_ENTITY_FORM: 'stacked_entity_form' as const,
} as const;
