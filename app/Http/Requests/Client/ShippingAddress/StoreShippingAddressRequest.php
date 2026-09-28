<?php

namespace App\Http\Requests\Client\ShippingAddress;

class StoreShippingAddressRequest extends ShippingAddressWriteRequest
{
    protected function isUpdate(): bool
    {
        return false;
    }
}
