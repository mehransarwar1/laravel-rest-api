<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\IndexCategoryRequest;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(IndexCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 15);

        $query = Category::query()->select([
            'id',
            'name',
            'slug',
            'description',
            'is_active',
            'created_at',
            'updated_at',
        ]);

        if (! empty($validated['search'])) {
            $term = addcslashes(trim((string) $validated['search']), '%_\\');
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('slug', 'like', '%'.$term.'%');
            });
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        [$column, $direction] = $this->resolveSort($validated['sort'] ?? '-created_at');
        $query->orderBy($column, $direction);

        $paginator = $query->paginate($perPage);

        return $this->paginatedResponse(
            'Categories retrieved successfully.',
            $paginator,
            CategoryResource::collection($paginator->getCollection()),
        );
    }

    public function show(Category $category): JsonResponse
    {
        return $this->successResponse(
            'Category retrieved successfully.',
            new CategoryResource($category),
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = Category::query()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Category::uniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse(
            'Category created successfully.',
            new CategoryResource($category),
            201,
        );
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $validated = $request->validated();

        $payload = [];

        if (array_key_exists('name', $validated)) {
            $payload['name'] = $validated['name'];
        }

        if (array_key_exists('slug', $validated)) {
            $payload['slug'] = $validated['slug'] ?? Category::uniqueSlug(
                $validated['name'] ?? $category->name,
                $category->id,
            );
        }

        if (array_key_exists('description', $validated)) {
            $payload['description'] = $validated['description'];
        }

        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = $validated['is_active'];
        }

        $category->update($payload);

        return $this->successResponse(
            'Category updated successfully.',
            new CategoryResource($category->refresh()),
        );
    }

    public function destroy(Category $category): JsonResponse|Response
    {
        $this->authorize('delete', $category);

        if ($category->products()->exists()) {
            return $this->errorResponse(
                'Category cannot be deleted because it contains products.',
                409,
            );
        }

        $category->delete();

        return response()->noContent();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, IndexCategoryRequest::ALLOWED_SORTS, true)) {
            return ['created_at', 'desc'];
        }

        return [$column, $direction];
    }
}
