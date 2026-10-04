<?php

namespace App\Repositories;

use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ContactRepository implements ContactRepositoryInterface
{
    private function query(array $filters, ?int $userId = null): Builder
    {
        return Contact::query()
            ->when($userId !== null, fn($q) => $q->where('user_id', $userId))
            ->when(!empty($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['date_from']), fn($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->when(isset($filters['search']) && trim($filters['search']) !== '', function ($q) use ($filters, $userId) {
                $term = trim($filters['search']);
                $q->where(function ($search) use ($term, $userId) {
                    $search->where('subject', 'like', "%{$term}%")->orWhere('message', 'like', "%{$term}%");
                    $id = preg_replace('/^(LH|#)0*/i', '', $term);
                    if (ctype_digit($id)) $search->orWhere('id', (int) $id);
                    if ($userId === null) $search->orWhereHas('user', fn($u) => $u
                        ->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone_number', 'like', "%{$term}%"));
                });
            });
    }
    public function paginate(array $filters, int $perPage, ?int $userId = null): LengthAwarePaginator
    {
        return $this->query($filters, $userId)
            ->when($userId === null, fn($q) => $q->with(['user:id,name,email,phone_number', 'updater:id,name']))
            ->orderByDesc('id')->paginate($perPage)->withQueryString();
    }
    public function counts(array $filters): array
    {
        $counts = $this->query($filters)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        return [
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts['pending'] ?? 0),
            'resolved' => (int) ($counts['resolved'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0)
        ];
    }
    public function find(int $id, ?int $userId = null, bool $lock = false): Contact
    {
        return Contact::query()->whereKey($id)
            ->when($userId !== null, fn($q) => $q->where('user_id', $userId))
            ->when($lock, fn($q) => $q->lockForUpdate())
            ->when(!$lock && $userId === null, fn($q) => $q->with(['user:id,name,email,phone_number', 'updater:id,name']))
            ->firstOrFail();
    }
    public function findByRequest(int $userId, string $key): ?Contact
    {
        return Contact::query()->where('user_id', $userId)->where('request_key', $key)->lockForUpdate()->first();
    }
    public function create(array $data): Contact
    {
        return Contact::create($data);
    }
    public function update(Contact $contact, array $data): void
    {
        $contact->update($data);
    }
}
