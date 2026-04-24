<?php

namespace Tests\Unit;

use App\Models\Ticket;
use App\Services\TicketService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TicketServiceReplyStateTest extends TestCase
{
    public function test_user_reply_reopens_closed_ticket_and_marks_waiting(): void
    {
        $ticket = new Ticket([
            'user_id' => 1001,
            'status' => Ticket::STATUS_CLOSED,
            'reply_status' => Ticket::REPLY_STATUS_REPLIED,
            'last_reply_user_id' => 2002,
        ]);

        $this->applyReplyState($ticket, 1001);

        $this->assertSame(Ticket::STATUS_OPENING, $ticket->status);
        $this->assertSame(Ticket::REPLY_STATUS_WAITING, $ticket->reply_status);
        $this->assertSame(1001, $ticket->last_reply_user_id);
    }

    public function test_admin_reply_reopens_closed_ticket_and_marks_replied(): void
    {
        $ticket = new Ticket([
            'user_id' => 1001,
            'status' => Ticket::STATUS_CLOSED,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => 1001,
        ]);

        $this->applyReplyState($ticket, 3003);

        $this->assertSame(Ticket::STATUS_OPENING, $ticket->status);
        $this->assertSame(Ticket::REPLY_STATUS_REPLIED, $ticket->reply_status);
        $this->assertSame(3003, $ticket->last_reply_user_id);
    }

    private function applyReplyState(Ticket $ticket, int $userId): void
    {
        $service = new TicketService();
        $method = new ReflectionMethod(TicketService::class, 'applyReplyState');
        $method->setAccessible(true);
        $method->invoke($service, $ticket, $userId);
    }
}
