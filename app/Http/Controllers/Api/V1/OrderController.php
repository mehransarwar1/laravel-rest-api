<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OrderProcessingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\IndexOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function index(IndexOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = $request->user()->orders()->select([
            'id',
            'user_id',
            'order_number',
            'status',
            'subtotal',
            'tax',
            'total',
            'created_at',
            'updated_at',
        ]);

        if (array_key_exists('status', $validated)) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('from_date', $validated)) {
            $query->whereDate('created_at', '>=', $validated['from_date']);
        }

        if (array_key_exists('to_date', $validated)) {
            $query->whereDate('created_at', '<=', $validated['to_date']);
        }

        [$column, $direction] = $this->resolveSort($validated['sort'] ?? '-created_at');
        $query->orderBy($column, $direction);

        $paginator = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Orders retrieved successfully.',
            $paginator,
            OrderResource::collection($paginator->getCollection()),
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load('orderItems');

        return $this->successResponse(
            'Order retrieved successfully.',
            new OrderResource($order),
        );
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->create(
                $request->user(),
                $request->validated('items'),
            );
        } catch (OrderProcessingException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->status());
        }

        return $this->successResponse(
            'Order created successfully.',
            new OrderResource($order),
            201,
        );
    }

    public function update(): JsonResponse
    {
        return $this->errorResponse('Orders cannot be updated. Cancel the order instead.', 405);
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->authorize('delete', $order);

        try {
            $order = $this->orderService->cancel($order);
        } catch (OrderProcessingException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->status());
        }

        return $this->successResponse(
            'Order cancelled successfully.',
            new OrderResource($order),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, IndexOrderRequest::ALLOWED_SORTS, true)) {
            return ['created_at', 'desc'];
        }

        return [$column, $direction];
    }
}
