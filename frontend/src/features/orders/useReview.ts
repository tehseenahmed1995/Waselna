import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';

export function useSubmitReview() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { orderId: string; rating: number; comment?: string }) =>
      apiFetch(`/orders/${input.orderId}/review`, {
        method: 'POST',
        body: { rating: input.rating, comment: input.comment },
      }),
    onSuccess: (_d, input) => {
      qc.invalidateQueries({ queryKey: ['order', input.orderId] });
      qc.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}
