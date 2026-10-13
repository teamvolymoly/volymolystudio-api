<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MerchantOrderController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewOrders', $store);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Order::statuses())],
            'query' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $ordersQuery = $store->orders()->latest('placed_at')->latest('id');
        if (! empty($filters['status'])) {
            $ordersQuery->where('status', $filters['status']);
        }
        if (! empty($filters['query'])) {
            $search = trim($filters['query']);
            $ordersQuery->where(function (Builder $query) use ($search): void {
                $query->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        $orders = $ordersQuery->paginate((int) ($filters['per_page'] ?? 25));
        $summary = $store->orders()
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status IN ('pending', 'confirmed', 'processing') THEN 1 ELSE 0 END) as open_orders")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_sales")
            ->first();

        return response()->json([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency,
            ],
            'orders' => collect($orders->items())->map(fn (Order $order) => $this->orderPayload($order)),
            'summary' => [
                'total_orders' => (int) ($summary?->total_orders ?? 0),
                'open_orders' => (int) ($summary?->open_orders ?? 0),
                'completed_orders' => (int) ($summary?->completed_orders ?? 0),
                'paid_sales' => number_format((float) ($summary?->paid_sales ?? 0), 2, '.', ''),
            ],
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
            'filters' => [
                'status' => $filters['status'] ?? null,
                'query' => isset($filters['query']) ? trim($filters['query']) : null,
            ],
        ]);
    }

    public function show(Request $request, Store $store, Order $order): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewOrders', $store);
        abort_unless($order->store_id === $store->id, 404);

        return response()->json(['order' => $this->orderPayload($order, true)]);
    }

    public function update(Request $request, Store $store, Order $order): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manageOrders', $store);
        abort_unless($order->store_id === $store->id, 404);

        $changes = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(Order::statuses())],
            'payment_status' => ['sometimes', 'required', Rule::in(Order::paymentStatuses())],
            'fulfillment_status' => ['sometimes', 'required', Rule::in(Order::fulfillmentStatuses())],
        ]);
        if ($changes === []) {
            throw ValidationException::withMessages([
                'order' => ['Select at least one order field to update.'],
            ]);
        }

        $updatedOrder = DB::transaction(function () use ($store, $order, $changes): Order {
            $lockedOrder = Order::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->findOrFail($order->id);
            $allowedTransitions = $lockedOrder->allowedTransitions();
            $errors = [];

            foreach ($changes as $field => $nextValue) {
                $currentValue = $lockedOrder->getAttribute($field);
                if ($nextValue !== $currentValue && ! in_array($nextValue, $allowedTransitions[$field], true)) {
                    $label = str($field)->replace('_', ' ')->toString();
                    $errors[$field] = ["The {$label} cannot change from {$currentValue} to {$nextValue}."];
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $lockedOrder->fill($changes);
            $lockedOrder->save();

            return $lockedOrder->refresh();
        });

        return response()->json([
            'message' => 'Order updated successfully.',
            'order' => $this->orderPayload($updatedOrder, true),
        ]);
    }

    /** @return array<string, mixed> */
    private function orderPayload(Order $order, bool $includeTransitions = false): array
    {
        $payload = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'channel' => $order->channel,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'customer' => [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            'items_count' => $order->items_count,
            'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount,
            'shipping_amount' => $order->shipping_amount,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'placed_at' => $order->placed_at?->toISOString(),
            'created_at' => $order->created_at?->toISOString(),
            'updated_at' => $order->updated_at?->toISOString(),
        ];

        if ($includeTransitions) {
            $payload['allowed_transitions'] = $order->allowedTransitions();
        }

        return $payload;
    }
}
