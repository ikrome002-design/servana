import { describe, expect, it } from 'vitest';
import type { ApiError } from '@/types/api';
import { useForm } from './useForm';

describe('useForm', () => {
  it('initialises with provided values', () => {
    const { values } = useForm({ email: '', name: 'Test' });
    expect(values.email).toBe('');
    expect(values.name).toBe('Test');
  });

  it('tracks dirty state', () => {
    const { values, dirty } = useForm({ email: '' });
    expect(dirty.value).toBe(false);
    values.email = 'x@example.com';
    expect(dirty.value).toBe(true);
  });

  it('resets to initial values', () => {
    const { values, dirty, reset } = useForm({ email: 'a@b.com' });
    values.email = 'changed@b.com';
    reset();
    expect(values.email).toBe('a@b.com');
    expect(dirty.value).toBe(false);
  });

  it('sets a field error', () => {
    const { errors, setFieldError } = useForm({ email: '' });
    setFieldError('email', ['Email is invalid.']);
    expect(errors.email).toEqual(['Email is invalid.']);
  });

  it('merges server 422 field errors', () => {
    const { errors, mergeServerErrors } = useForm({ email: '', phone: '' });
    const apiError: ApiError = {
      code: 'validation_error',
      message: 'Validation failed.',
      fields: {
        email: ['The email field is required.'],
        phone: ['The phone field must be 10 digits.'],
      },
      meta: {},
    };
    mergeServerErrors(apiError);
    expect(errors.email).toEqual(['The email field is required.']);
    expect(errors.phone).toEqual(['The phone field must be 10 digits.']);
  });

  it('prevents duplicate submit', async () => {
    const { submitting, handleSubmit } = useForm({ x: '' });
    let calls = 0;
    const slowFn = async (): Promise<void> => {
      calls++;
      await new Promise<void>((r) => setTimeout(r, 10));
    };
    const submit = handleSubmit(slowFn);
    // Second concurrent call is ignored; both promises must resolve before asserting.
    await Promise.all([submit(), submit()]);
    expect(calls).toBe(1);
    expect(submitting.value).toBe(false);
  });

  it('touches a field', () => {
    const { touched, touch } = useForm({ name: '' });
    expect(touched.name).toBeUndefined();
    touch('name');
    expect(touched.name).toBe(true);
  });

  it('resets submitting on error', async () => {
    const { submitting, handleSubmit } = useForm({ x: '' });
    const submit = handleSubmit(async () => { throw new Error('oops'); });
    await expect(submit()).rejects.toThrow('oops');
    expect(submitting.value).toBe(false);
  });
});
