<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\IndexProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(IndexProductRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = Product::query()
            ->select([
                'id',
                'category_id',
                'name',
                'slug',
                'description',
                'sku',
                'price',
                'stock',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->with('category:id,name,slug,description,is_active,created_at,updated_at');

        if (! empty($validated['search'])) {
            $term = addcslashes(trim((string) $validated['search']), '%_\\');
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('slug', 'like', '%'.$term.'%')
                    ->orWhere('sku', 'like', '%'.$term.'%');
            });
        }

        if (array_key_exists('category_id', $validated)) {
            $query->where('category_id', $validated['category_id']);
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if (array_key_exists('min_price', $validated)) {
            $query->where('price', '>=', $validated['min_price']);
        }

        if (array_key_exists('max_price', $validated)) {
            $query->where('price', '<=', $validated['max_price']);
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
            'Products retrieved successfully.',
            $paginator,
            ProductResource::collection($paginator->getCollection()),
        );
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category:id,name,slug,description,is_active,created_at,updated_at');

        return $this->successResponse(
            'Product retrieved successfully.',
            new ProductResource($product),
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $product = Product::query()->create([
                'category_id' => $validated['category_id'],
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? Product::uniqueSlug($validated['name']),
                'description' => $validated['description'] ?? null,
                'sku' => $validated['sku'] ?? null,
                'price' => $validated['price'],
                'stock' => $validated['stock'],
                'is_active' => $validated['is_active'] ?? true,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->errorResponse('A product with this slug or SKU already exists.', 409);
            }

            throw $exception;
        }

        $product->load('category:id,name,slug,description,is_active,created_at,updated_at');

        return $this->successResponse(
            'Product created successfully.',
            new ProductResource($product),
            201,
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $validated = $request->validated();
        $payload = [];

        foreach (['name', 'description', 'category_id', 'sku', 'price', 'stock', 'is_active'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('slug', $validated)) {
            $payload['slug'] = $validated['slug'] ?? Product::uniqueSlug(
                $validated['name'] ?? $product->name,
                $product->id,
            );
        }

        try {
            $product->update($payload);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->errorResponse('A product with this slug or SKU already exists.', 409);
            }

            throw $exception;
        }

        $product->refresh()->load('category:id,name,slug,description,is_active,created_at,updated_at');

        return $this->successResponse(
            'Product updated successfully.',
            new ProductResource($product),
        );
    }

    public function destroy(Product $product): Response
    {
        $this->authorize('delete', $product);

        $product->delete();

        return response()->noContent();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, IndexProductRequest::ALLOWED_SORTS, true)) {
            return ['created_at', 'desc'];
        }

        return [$column, $direction];
    }
}
