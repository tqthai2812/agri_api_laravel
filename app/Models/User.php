<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'avatar', 'role', 'phone_number', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    const ROLE_ADMIN = 'admin';
    const ROLE_CUSTOMER = 'customer';

    public function shippingAddresses(): HasMany
    {
        return $this->hasMany(ShippingAddress::class);
    }
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }
    public function shoppingCart(): HasOne
    {
        return $this->hasOne(ShoppingCart::class);
    }
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
    public function news(): HasMany
    {
        return $this->hasMany(News::class);
    }
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'performed_by');
    }

    public function orderHistories(): HasMany
    {
        return $this->hasMany(OrderHistory::class, 'created_by');
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function newsComments(): HasMany
    {
        return $this->hasMany(NewsComment::class);
    }
}
