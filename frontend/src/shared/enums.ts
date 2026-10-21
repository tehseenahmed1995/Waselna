// Shared vocabularies for the Web_Client.
//
// Nothing about the Platform's enums is hardcoded here. Every value and label —
// roles, order statuses, restaurant statuses, rider statuses, currencies — is
// fetched from the backend `GET /api/v1/enums` endpoint via useEnums(), so the
// backend enums (App\Shared\Enums\*) are the single source of truth. Where the
// UI needs to branch on a specific status, the backend sends a derived boolean
// (e.g. a restaurant's `is_discoverable`) rather than the UI matching a string.

import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '../api/client';

export interface EnumOption {
  value: string;
  label: string;
}

export interface ReferenceData {
  roles: EnumOption[];
  order_statuses: EnumOption[];
  restaurant_statuses: EnumOption[];
  rider_statuses: EnumOption[];
  currencies: EnumOption[];
}

const EMPTY_REFERENCE_DATA: ReferenceData = {
  roles: [],
  order_statuses: [],
  restaurant_statuses: [],
  rider_statuses: [],
  currencies: [],
};

/**
 * Fetches the Platform's shared vocabularies once and caches them for the
 * session. The endpoint is public static data, so it is effectively immutable
 * for the life of the tab.
 */
export function useEnums() {
  const query = useQuery({
    queryKey: ['enums'],
    queryFn: () => apiFetch<ReferenceData>('/enums'),
    staleTime: Infinity,
    gcTime: Infinity,
  });

  const data = query.data ?? EMPTY_REFERENCE_DATA;
  const labelFor = (options: EnumOption[], value: string): string =>
    options.find((o) => o.value === value)?.label ?? value;

  return {
    ...query,
    data,
    roleLabel: (value: string) => labelFor(data.roles, value),
    orderStatusLabel: (value: string) => labelFor(data.order_statuses, value),
    restaurantStatusLabel: (value: string) => labelFor(data.restaurant_statuses, value),
    riderStatusLabel: (value: string) => labelFor(data.rider_statuses, value),
    currencyLabel: (value: string) => labelFor(data.currencies, value),
    // Currency dropdown shows "CODE - Name" (e.g. "USD - US Dollar").
    currencyOptions: data.currencies.map((c) => ({ value: c.value, label: `${c.value} - ${c.label}` })),
  };
}