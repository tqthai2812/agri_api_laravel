<?php

namespace App\Http\Requests\Admin\FarmUtility;

use Illuminate\Validation\Rule;

class CropRequest extends FarmCatalogRequest
{
    public function rules(): array
    {
        $rules = $this->commonRules();
        $rules['slug'][] = Rule::unique('crops', 'slug')->ignore($this->route('crop')?->id);

        return $rules;
    }
}