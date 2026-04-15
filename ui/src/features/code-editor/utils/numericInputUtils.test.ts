import { describe, expect, it } from 'vitest';

import { getNumericInputError } from './numericInputUtils';

describe('getNumericInputError', () => {
  describe('empty value', () => {
    it('returns null for empty string (integer)', () => {
      expect(getNumericInputError('', 'integer')).toBeNull();
    });

    it('returns null for empty string (number)', () => {
      expect(getNumericInputError('', 'number')).toBeNull();
    });
  });

  describe('integer type', () => {
    describe('valid integers', () => {
      it('returns null for a positive integer', () => {
        expect(getNumericInputError('42', 'integer')).toBeNull();
      });

      it('returns null for zero', () => {
        expect(getNumericInputError('0', 'integer')).toBeNull();
      });

      it('returns null for a negative integer', () => {
        expect(getNumericInputError('-42', 'integer')).toBeNull();
      });

      it('returns null for a positive integer with explicit + sign', () => {
        expect(getNumericInputError('+42', 'integer')).toBeNull();
      });

      it('returns null for a large integer', () => {
        expect(getNumericInputError('1000000', 'integer')).toBeNull();
      });
    });

    describe('decimal values', () => {
      it('returns decimal error for a positive decimal', () => {
        expect(getNumericInputError('10.5', 'integer')).toBe(
          'Integers cannot have decimal values.',
        );
      });

      it('returns decimal error for a negative decimal', () => {
        expect(getNumericInputError('-10.5', 'integer')).toBe(
          'Integers cannot have decimal values.',
        );
      });

      it('returns decimal error for a value with trailing dot', () => {
        expect(getNumericInputError('10.', 'integer')).toBe(
          'Integers cannot have decimal values.',
        );
      });

      it('returns decimal error for a value with leading dot', () => {
        expect(getNumericInputError('.5', 'integer')).toBe(
          'Integers cannot have decimal values.',
        );
      });
    });

    describe('invalid non-numeric values', () => {
      it('returns invalid error for alphabetic input', () => {
        expect(getNumericInputError('abc', 'integer')).toBe(
          'Please enter a valid integer.',
        );
      });

      it('returns invalid error for alphanumeric input', () => {
        expect(getNumericInputError('12abc', 'integer')).toBe(
          'Please enter a valid integer.',
        );
      });

      it('returns invalid error for a lone minus sign', () => {
        expect(getNumericInputError('-', 'integer')).toBe(
          'Please enter a valid integer.',
        );
      });

      it('returns invalid error for a lone plus sign', () => {
        expect(getNumericInputError('+', 'integer')).toBe(
          'Please enter a valid integer.',
        );
      });

      it('returns invalid error for whitespace', () => {
        expect(getNumericInputError(' ', 'integer')).toBe(
          'Please enter a valid integer.',
        );
      });

      it('returns invalid error for scientific notation', () => {
        expect(getNumericInputError('1e5', 'integer')).toBe(
          'Please enter a valid integer.',
        );
      });
    });
  });

  describe('number type', () => {
    describe('valid numbers', () => {
      it('returns null for a positive integer', () => {
        expect(getNumericInputError('42', 'number')).toBeNull();
      });

      it('returns null for zero', () => {
        expect(getNumericInputError('0', 'number')).toBeNull();
      });

      it('returns null for a negative integer', () => {
        expect(getNumericInputError('-42', 'number')).toBeNull();
      });

      it('returns null for a positive decimal', () => {
        expect(getNumericInputError('3.14', 'number')).toBeNull();
      });

      it('returns null for a negative decimal', () => {
        expect(getNumericInputError('-3.14', 'number')).toBeNull();
      });

      it('returns null for a decimal with explicit + sign', () => {
        expect(getNumericInputError('+3.14', 'number')).toBeNull();
      });

      it('returns null for a value with trailing dot', () => {
        expect(getNumericInputError('10.', 'number')).toBeNull();
      });

      it('returns null for a value with leading dot', () => {
        expect(getNumericInputError('.5', 'number')).toBeNull();
      });

      it('returns null for a large decimal', () => {
        expect(getNumericInputError('123456.789', 'number')).toBeNull();
      });
    });

    describe('invalid non-numeric values', () => {
      it('returns invalid error for alphabetic input', () => {
        expect(getNumericInputError('abc', 'number')).toBe(
          'Please enter a valid number.',
        );
      });

      it('returns invalid error for alphanumeric input', () => {
        expect(getNumericInputError('12abc', 'number')).toBe(
          'Please enter a valid number.',
        );
      });

      it('returns invalid error for a lone minus sign', () => {
        expect(getNumericInputError('-', 'number')).toBe(
          'Please enter a valid number.',
        );
      });

      it('returns invalid error for a lone plus sign', () => {
        expect(getNumericInputError('+', 'number')).toBe(
          'Please enter a valid number.',
        );
      });

      it('returns invalid error for whitespace', () => {
        expect(getNumericInputError(' ', 'number')).toBe(
          'Please enter a valid number.',
        );
      });

      it('returns invalid error for scientific notation', () => {
        expect(getNumericInputError('1e5', 'number')).toBe(
          'Please enter a valid number.',
        );
      });

      it('returns invalid error for multiple dots', () => {
        expect(getNumericInputError('1.2.3', 'number')).toBe(
          'Please enter a valid number.',
        );
      });
    });
  });
});
