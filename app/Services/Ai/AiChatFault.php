<?php

namespace App\Services\Ai;

class AiChatFault extends AiFault
{
    public function __construct(
        AiFault $fault,
        public string $chatId,
        public string $messageId,
    ) {
        parent::__construct(
            $fault->status,
            $fault->getMessage(),
            $fault->httpStatus,
        );
    }
    public function payload(): array
    {
        return parent::payload() + [
            "chat_id" => $this->chatId,
            "client_message_id" => $this->messageId,
        ];
    }
}