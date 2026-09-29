<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexOrderRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const ALLOWED_SORTS = [
        'created_at',
        'updated_at',
        'total',
        'status',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Order::class) ?? false;
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
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in($sortValues)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'from_date' => ['sometimes', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('from_date') || ! $this->filled('to_date')) {
                return;
            }

            if ((string) $this->input('from_date') > (string) $this->input('to_date')) {
                $validator->errors()->add('from_date', 'The from date must be before or equal to the to date.');
            }
        });
    }
}
