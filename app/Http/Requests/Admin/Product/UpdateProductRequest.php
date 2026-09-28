<?php

namespace App\Http\Requests\Admin\Product;

class UpdateProductRequest extends ProductWriteRequest
{
    protected bool $updating = true;
}
