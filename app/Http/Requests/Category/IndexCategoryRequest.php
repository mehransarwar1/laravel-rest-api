<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCategoryRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    public const ALLOWED_SORTS = [
        'name',
        'slug',
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
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in($sortValues)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
