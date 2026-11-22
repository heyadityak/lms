// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CommentModal } from './CommentModal';

describe('CommentModal', () => {
    it('requires a reject comment of at least 3 characters', async () => {
        const onSubmit = vi.fn(async () => {});
        render(
            <CommentModal
                open
                title="Reject Ada's leave?"
                okText="Reject"
                required
                onSubmit={onSubmit}
                onClose={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
        expect(await screen.findByText('A comment is required')).toBeTruthy();
        expect(onSubmit).not.toHaveBeenCalled();

        fireEvent.change(screen.getByRole('textbox', { name: 'Comment' }), {
            target: { value: 'No' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
        expect(await screen.findByText('At least 3 characters')).toBeTruthy();
        expect(onSubmit).not.toHaveBeenCalled();

        fireEvent.change(screen.getByRole('textbox', { name: 'Comment' }), {
            target: { value: '  Not this week  ' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
        await screen.findByRole('button', { name: 'Reject' });
        await vi.waitFor(() => expect(onSubmit).toHaveBeenCalledWith('Not this week'));
    });
});
