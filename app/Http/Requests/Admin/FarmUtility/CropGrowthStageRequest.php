<?php

namespace App\Http\Requests\Admin\FarmUtility;

use Illuminate\Validation\Rule;

class CropGrowthStageRequest extends FarmCatalogRequest
{
    public function rules(): array
    {
        $rules = $this->commonRules();
        $rules['crop_id'] = ['required', 'integer', Rule::exists('crops', 'id')];
        $rules['slug'][] = Rule::unique('crop_growth_stages', 'slug')
            ->where('crop_id', $this->input('crop_id'))->ignore($this->route('growthStage')?->id);
        $rules['description'] = ['nullable', 'string', 'max:16000'];
        $rules['sort_order'] = ['required', 'integer', 'min:0', 'max:65535'];

        return $rules;
    }
}