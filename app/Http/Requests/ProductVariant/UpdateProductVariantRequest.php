<?php

namespace App\Http\Requests\ProductVariant;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $variant = $this->route('variant');

        return $variant instanceof ProductVariant
            && ($this->user()?->can('update', $variant) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->input('name')),
            ]);
        }

        if ($this->exists('sku')) {
            $this->merge([
                'sku' => trim((string) $this->input('sku')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $variant = $this->route('variant');
        $variantId = $variant instanceof ProductVariant ? $variant->id : null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9][A-Za-z0-9\-_]{0,63}$/',
                Rule::unique('product_variants', 'sku')->ignore($variantId),
            ],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
