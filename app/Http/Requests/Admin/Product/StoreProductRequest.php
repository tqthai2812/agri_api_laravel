<?php

namespace App\Http\Requests\Admin\Product;

class StoreProductRequest extends ProductWriteRequest
{
    protected bool $updating = false;
}
