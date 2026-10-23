import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';

export interface RestaurantProfile {
  id: string;
  name: string;
  description: string | null;
  status: string;
  currency: string;
  tax_rate_bp: number;
  base_delivery_fee: number;
  time_zone: string;
  cuisine_tags: string[];
}

export interface MenuCategory {
  id: string;
  restaurant_id: string;
  name: string;
  display_order: number;
  archived: boolean;
}

export interface MenuItem {
  id: string;
  restaurant_id: string;
  category_id: string;
  name: string;
  description: string | null;
  image_url: string | null;
  price_minor: number;
  is_available: boolean;
  display_order: number;
  archived: boolean;
}

// ---- Profile ----------------------------------------------------------------
export function useRestaurantProfile(restaurantId: string | null) {
  return useQuery({
    queryKey: ['restaurant-profile', restaurantId],
    queryFn: () => apiFetch<RestaurantProfile>(`/restaurants/${restaurantId}/profile`),
    enabled: restaurantId !== null,
  });
}

export function useUpdateRestaurantProfile(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (patch: Partial<RestaurantProfile> & { cuisine_tags: string[] }) =>
      apiFetch<RestaurantProfile>(`/restaurants/${restaurantId}/profile`, { method: 'PATCH', body: patch }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['restaurant-profile', restaurantId] });
    },
  });
}

export function useSubmitForReview(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<RestaurantProfile>(`/restaurants/${restaurantId}/submit-for-review`, { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurant-profile', restaurantId] }),
  });
}

// ---- Menu categories --------------------------------------------------------
export function useMenuCategories(restaurantId: string | null) {
  return useQuery({
    queryKey: ['menu-categories', restaurantId],
    queryFn: () => apiFetch<MenuCategory[]>(`/restaurants/${restaurantId}/menu/categories`),
    enabled: restaurantId !== null,
  });
}

export function useCreateCategory(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { name: string; display_order: number }) =>
      apiFetch<MenuCategory>(`/restaurants/${restaurantId}/menu/categories`, { method: 'POST', body: input }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-categories', restaurantId] }),
  });
}

export function useUpdateCategory(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { id: string; name: string; display_order: number }) =>
      apiFetch<MenuCategory>(`/restaurants/${restaurantId}/menu/categories/${input.id}`, {
        method: 'PATCH',
        body: { name: input.name, display_order: input.display_order },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-categories', restaurantId] }),
  });
}

// ---- Menu items -------------------------------------------------------------
export function useMenuItems(restaurantId: string | null, categoryId: string | null) {
  return useQuery({
    queryKey: ['menu-items', restaurantId, categoryId],
    queryFn: () => apiFetch<MenuItem[]>(`/restaurants/${restaurantId}/menu/categories/${categoryId}/items`),
    enabled: restaurantId !== null && categoryId !== null,
  });
}

export interface NewMenuItem {
  category_id: string;
  name: string;
  description?: string | null;
  image_url?: string | null;
  price_minor: number;
  is_available?: boolean;
  display_order: number;
}

export function useCreateItem(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: NewMenuItem) =>
      apiFetch<MenuItem>(`/restaurants/${restaurantId}/menu/items`, { method: 'POST', body: input }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}

export function useUpdateItem(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { id: string } & NewMenuItem) =>
      apiFetch<MenuItem>(`/restaurants/${restaurantId}/menu/items/${input.id}`, {
        method: 'PATCH',
        body: {
          category_id: input.category_id,
          name: input.name,
          description: input.description ?? null,
          image_url: input.image_url ?? null,
          price_minor: input.price_minor,
          display_order: input.display_order,
        },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}

export function useDeleteItem(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (itemId: string) =>
      apiFetch(`/restaurants/${restaurantId}/menu/items/${itemId}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}

export function useSetItemAvailability(restaurantId: string | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { itemId: string; is_available: boolean }) =>
      apiFetch<MenuItem>(`/restaurants/${restaurantId}/menu/items/${input.itemId}/availability`, {
        method: 'PATCH',
        body: { is_available: input.is_available },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['menu-items', restaurantId] }),
  });
}

// ---- Staff ------------------------------------------------------------------
export interface StaffMember {
  id: string;
  display_name: string;
  email: string;
  account_status: string;
}

export function useStaff(enabled: boolean) {
  return useQuery({
    queryKey: ['restaurant-staff'],
    queryFn: () => apiFetch<StaffMember[]>('/restaurant/staff'),
    enabled,
  });
}

export interface NewStaff {
  email: string;
  display_name: string;
  password: string;
}

export function useInviteStaff() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: NewStaff) => apiFetch<StaffMember>('/restaurant/staff', { method: 'POST', body: input }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurant-staff'] }),
  });
}

export function useRemoveStaff() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (staffId: string) => apiFetch(`/restaurant/staff/${staffId}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['restaurant-staff'] }),
  });
}

// ---- Order history (business-owner view) ------------------------------------
export interface Money {
  amount: number;
  currency: string;
}

export interface OrderSummary {
  id: string;
  order_number: string;
  status: string;
  currency: string;
  order_total: Money;
  line_count: number;
  lines: { quantity: number; name: string }[];
  created_at: string;
}

export interface OrderHistoryTotals {
  count: number;
  delivered: number;
  revenue: Money;
}

export interface OrderFilters {
  status?: string;
  from?: string;
  to?: string;
  search?: string;
}

export function useOrderHistory(filters: OrderFilters) {
  const params = new URLSearchParams();
  if (filters.status) params.set('status', filters.status);
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  if (filters.search) params.set('search', filters.search);
  const qs = params.toString();

  return useQuery({
    queryKey: ['order-history', filters],
    queryFn: () => apiFetch<OrderSummary[]>(`/restaurant/orders/history${qs ? `?${qs}` : ''}`),
  });
}

export interface OrderDetail {
  id: string;
  order_number: string;
  status: string;
  currency: string;
  breakdown: Record<string, Money>;
  lines: { id: string; quantity: number; unit_price: Money; subtotal: Money; snapshot: { name?: string } | null }[];
  delivery_address: Record<string, unknown> | null;
  created_at: string;
  payment?: { status: string; amount: Money; transaction_id: string | null };
  timeline?: { status: string; actor_role: string; notes: string | null; at: string }[];
}

export function useOrderDetail(orderId: string | null) {
  return useQuery({
    queryKey: ['order-detail', orderId],
    queryFn: () => apiFetch<OrderDetail>(`/orders/${orderId}`),
    enabled: orderId !== null,
  });
}
