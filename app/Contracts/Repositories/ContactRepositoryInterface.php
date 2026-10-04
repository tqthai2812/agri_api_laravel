<?php

namespace App\Contracts\Repositories;

use App\Models\Contact;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContactRepositoryInterface
{
    public function paginate(array $filters, int $perPage, ?int $userId = null): LengthAwarePaginator;
    public function counts(array $filters): array;
    public function find(int $id, ?int $userId = null, bool $lock = false): Contact;
    public function findByRequest(int $userId, string $key): ?Contact;
    public function create(array $data): Contact;
    public function update(Contact $contact, array $data): void;
}
