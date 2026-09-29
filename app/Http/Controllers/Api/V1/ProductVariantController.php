<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductVariant\IndexProductVariantRequest;
use App\Http\Requests\ProductVariant\StoreProductVariantRequest;
use App\Http\Requests\ProductVariant\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductVariantController extends Controller
{
    public function index(IndexProductVariantRequest $request, Product $product): JsonResponse
    {
        $this->ensureProductExposesVariants($product);

        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = $product->variants()->select([
            'id',
            'product_id',
            'name',
            'sku',
            'price',
            'stock',
            'is_active',
            'created_at',
            'updated_at',
        ]);

        if (! empty($validated['search'])) {
            $term = addcslashes(trim((string) $validated['search']), '%_\\');
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('sku', 'like', '%'.$term.'%');
            });
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if (array_key_exists('in_stock', $validated)) {
            $request->boolean('in_stock')
                ? $query->where('stock', '>', 0)
                : $query->where('stock', '=', 0);
        }

        [$column, $direction] = $this->resolveSort($validated['sort'] ?? '-created_at');
        $query->orderBy($column, $direction);

        $paginator = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Product variants retrieved successfully.',
            $paginator,
            ProductVariantResource::collection($paginator->getCollection()),
        );
    }

    public function show(Product $product, ProductVariant $variant): JsonResponse
    {
        $this->ensureProductExposesVariants($product);

        return $this->successResponse(
            'Product variant retrieved successfully.',
            new ProductVariantResource($variant),
        );
    }

    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();

        try {
            $variant = $product->variants()->create([
                'name' => $validated['name'],
                'sku' => $validated['sku'],
                'price' => $validated['price'] ?? null,
                'stock' => $validated['stock'],
                'is_active' => $validated['is_active'] ?? true,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->errorResponse('A variant with this SKU already exists.', 409);
            }

            throw $exception;
        }

        return $this->successResponse(
            'Product variant created successfully.',
            new ProductVariantResource($variant),
            201,
        );
    }

    public function update(UpdateProductVariantRequest $request, Product $product, ProductVariant $variant): JsonResponse
    {
        $validated = $request->validated();
        $payload = [];

        foreach (['name', 'sku', 'price', 'stock', 'is_active'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        try {
            $variant->update($payload);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->errorResponse('A variant with this SKU already exists.', 409);
            }

            throw $exception;
        }

        return $this->successResponse(
            'Product variant updated successfully.',
            new ProductVariantResource($variant->refresh()),
        );
    }

    public function destroy(Product $product, ProductVariant $variant): JsonResponse|Response
    {
        $this->authorize('delete', $variant);

        if ($variant->orderItems()->exists()) {
            return $this->errorResponse(
                'Variant cannot be deleted because it is referenced by existing orders.',
                409,
            );
        }

        $variant->delete();

        return response()->noContent();
    }

    private function ensureProductExposesVariants(Product $product): void
    {
        if (! $product->is_active) {
            abort(404);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, IndexProductVariantRequest::ALLOWED_SORTS, true)) {
            return ['created_at', 'desc'];
        }

        return [$column, $direction];
    }
}
