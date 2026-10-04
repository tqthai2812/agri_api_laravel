<?php

namespace App\Services;

use App\Contracts\Repositories\ContactRepositoryInterface;
use App\Contracts\Services\ContactServiceInterface;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ContactService implements ContactServiceInterface
{
    public function __construct(protected ContactRepositoryInterface $contacts) {}
    public function submit(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            // Serialize các request cùng khách; unique index là lớp bảo vệ bổ sung.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = $this->contacts->findByRequest($userId, $data['request_key']);
            if ($existing) {
                abort_unless(
                    $existing->subject === $data['subject'] && $existing->message === $data['message'],
                    409,
                    'Mã gửi yêu cầu đã được dùng với nội dung khác. Vui lòng tải lại trang để kiểm tra lịch sử.'
                );
                return ['contact' => $existing, 'created' => false];
            }
            return ['contact' => $this->contacts->create([
                'user_id' => $userId,
                'subject' => $data['subject'],
                'message' => $data['message'],
                'request_key' => $data['request_key'],
                'status' => Contact::STATUS_PENDING,
            ]), 'created' => true];
        }, 3);
    }
    public function forUser(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->contacts->paginate($filters, $perPage, $userId);
    }
    public function forUserById(int $userId, int $id): Contact
    {
        return $this->contacts->find($id, $userId);
    }
    public function index(array $filters, int $perPage): array
    {
        return ['contacts' => $this->contacts->paginate($filters, $perPage), 'summary' => $this->contacts->counts($filters)];
    }
    public function show(int $id): Contact
    {
        return $this->contacts->find($id);
    }
    public function update(int $id, array $data, int $actorId): Contact
    {
        DB::transaction(function () use ($id, $data, $actorId) {
            $contact = $this->contacts->find($id, lock: true);
            $note = $data['admin_note'] ?? null;
            // Gửi lại đúng thay đổi đã lưu không tăng phiên bản lần nữa.
            if ($contact->status === $data['status'] && $contact->admin_note === $note) return;
            abort_unless(
                $contact->lock_version === (int) $data['expected_version'],
                409,
                'Yêu cầu vừa được người khác cập nhật. Tải lại chi tiết trước khi lưu tiếp.'
            );
            $processedAt = $data['status'] === Contact::STATUS_PENDING ? null
                : ($contact->status === $data['status'] ? ($contact->processed_at ?? now()) : now());
            $this->contacts->update($contact, [
                'status' => $data['status'],
                'admin_note' => $note,
                'updated_by' => $actorId,
                'processed_at' => $processedAt,
                'lock_version' => $contact->lock_version + 1
            ]);
        }, 3);
        return $this->show($id);
    }
}
