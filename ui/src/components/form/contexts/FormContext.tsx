import { createContext, useContext } from 'react';
import {
  FormProvider as RHFFormProvider,
  useForm,
  useFormContext as useRHFFormContext,
} from 'react-hook-form';

import type { ReactNode } from 'react';
import type { UseFormReturn } from 'react-hook-form';
import type { FormId } from '@/features/form/formStateSlice';

interface FormContextValue {
  formId: FormId;
  methods: UseFormReturn<any>;
}

const FormContext = createContext<FormContextValue | null>(null);

// Safe version that returns null instead of throwing
export const useSafeFormContext = () => {
  const context = useContext(FormContext);
  if (!context) {
    console.warn('useFormContext must be used within a FormProvider');
  }
  return context;
};

// Safe version of react-hook-form's useFormContext that returns null instead of throwing
export const useSafeRHFContext = () => {
  try {
    return useRHFFormContext();
  } catch {
    return null;
  }
};

interface FormProviderProps {
  formId: FormId;
  children: ReactNode;
  defaultValues?: Record<string, any>;
}

export const FormProvider = ({
  formId,
  children,
  defaultValues = {},
}: FormProviderProps) => {
  const methods = useForm({
    defaultValues,
    mode: 'onChange',
  });

  const value: FormContextValue = {
    formId,
    methods,
  };

  return (
    <FormContext.Provider value={value}>
      <RHFFormProvider {...methods}>{children}</RHFFormProvider>
    </FormContext.Provider>
  );
};
