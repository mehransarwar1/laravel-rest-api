<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
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
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_]{0,63}$/', Rule::unique('products', 'sku')],
            'price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
