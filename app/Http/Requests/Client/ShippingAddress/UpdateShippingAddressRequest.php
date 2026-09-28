<?php

namespace App\Http\Requests\Client\ShippingAddress;

class UpdateShippingAddressRequest extends ShippingAddressWriteRequest
{
    protected function isUpdate(): bool
    {
        return true;
    }
}
