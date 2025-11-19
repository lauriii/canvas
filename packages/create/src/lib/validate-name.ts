import validate from 'validate-npm-package-name';

export default function validateName(name: string): {
  valid: boolean;
  problems: string[];
} {
  const nameValidation = validate(name);
  if (nameValidation.validForNewPackages) {
    return { valid: true, problems: [] };
  }

  return {
    valid: false,
    problems: [
      ...(nameValidation.errors || []),
      ...(nameValidation.warnings || []),
    ],
  };
}
