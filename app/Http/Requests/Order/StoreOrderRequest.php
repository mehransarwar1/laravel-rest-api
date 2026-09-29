<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Order::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $variantId = $item['product_variant_id'] ?? null;
                $productId = $item['product_id'] ?? null;

                if ($variantId === null || $productId === null) {
                    continue;
                }

                $belongs = ProductVariant::query()
                    ->whereKey($variantId)
                    ->where('product_id', $productId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add(
                        "items.$index.product_variant_id",
                        'The selected variant does not belong to the given product.',
                    );
                }
            }
        });
    }
}
