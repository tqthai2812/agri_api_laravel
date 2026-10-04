<?php

namespace App\Contracts\Services;

use App\Models\Contact;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContactServiceInterface
{
    public function submit(int $userId, array $data): array;
    public function forUser(int $userId, array $filters, int $perPage): LengthAwarePaginator;
    public function forUserById(int $userId, int $id): Contact;
    public function index(array $filters, int $perPage): array;
    public function show(int $id): Contact;
    public function update(int $id, array $data, int $actorId): Contact;
}
