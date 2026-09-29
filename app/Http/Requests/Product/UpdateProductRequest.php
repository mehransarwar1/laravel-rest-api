<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            && ($this->user()?->can('update', $product) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->input('name')),
            ]);
        }

        if ($this->exists('slug')) {
            $slug = Str::slug((string) $this->input('slug'));
            $this->merge([
                'slug' => $slug === '' ? null : $slug,
            ]);
        }

        if ($this->exists('sku')) {
            $sku = trim((string) $this->input('sku'));
            $this->merge([
                'sku' => $sku === '' ? null : $sku,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->id : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9][A-Za-z0-9\-_]{0,63}$/',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
