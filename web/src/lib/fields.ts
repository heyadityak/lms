import type { FormInstance } from 'antd';
import { ApiError } from '@/lib/api/client';

/** problem+json `errors` → Form.Item names (API_GUIDELINES §4). `days.0` maps to `days`. */
export function applyFieldErrors(form: FormInstance, error: unknown): boolean {
    if (!(error instanceof ApiError) || !error.problem.errors) return false;
    const byName = new Map<string, string[]>();
    for (const [key, msgs] of Object.entries(error.problem.errors)) {
        const name = key.split('.')[0] || key;
        byName.set(name, [...(byName.get(name) ?? []), ...msgs]);
    }
    form.setFields([...byName].map(([name, errors]) => ({ name, errors })));
    return true;
}

/** Show a banner when the problem is not already sitting on a field. */
export function unmappedError(error: unknown, mapped: boolean): unknown {
    if (!error) return null;
    if (mapped && error instanceof ApiError && error.status === 422) return null;
    return error;
}
