<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexProductRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const ALLOWED_SORTS = [
        'name',
        'price',
        'stock',
        'created_at',
        'updated_at',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sortValues = [];

        foreach (self::ALLOWED_SORTS as $field) {
            $sortValues[] = $field;
            $sortValues[] = '-'.$field;
        }

        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
            'min_price' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'in_stock' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in($sortValues)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('min_price') || ! $this->filled('max_price')) {
                return;
            }

            if ($this->toCents((string) $this->input('min_price')) > $this->toCents((string) $this->input('max_price'))) {
                $validator->errors()->add('min_price', 'The min price must be less than or equal to the max price.');
            }
        });
    }

    private function toCents(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');

        return (int) ($whole.str_pad($fraction, 2, '0'));
    }
}
