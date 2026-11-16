<?php

namespace App\Http\Requests\Admin\FarmUtility;

use Illuminate\Validation\Rule;

class ProductPurposeRequest extends FarmCatalogRequest
{
    public function rules(): array
    {
        $rules = $this->commonRules();
        $rules['name'][] = Rule::unique('product_purposes', 'name')->ignore($this->route('purpose')?->id);
        $rules['slug'][] = Rule::unique('product_purposes', 'slug')->ignore($this->route('purpose')?->id);
        $rules['description'] = ['nullable', 'string', 'max:16000'];

        return $rules;
    }
}