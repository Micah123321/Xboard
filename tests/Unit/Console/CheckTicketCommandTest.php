<?php

namespace Tests\Unit\Console;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckTicketCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_replied_ticket_closes_after_two_days_without_user_reply(): void
    {
        $now = time();
        $staleAdminReplied = $this->makeTicket([
            'user_id' => 1001,
            'reply_status' => Ticket::REPLY_STATUS_REPLIED,
            'last_reply_user_id' => 2001,
            'updated_at' => $now - 2 * 24 * 3600 - 1,
        ]);
        $recentAdminReplied = $this->makeTicket([
            'user_id' => 1002,
            'reply_status' => Ticket::REPLY_STATUS_REPLIED,
            'last_reply_user_id' => 2001,
            'updated_at' => $now - 2 * 24 * 3600 + 60,
        ]);
        $waitingForAdmin = $this->makeTicket([
            'user_id' => 1003,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => 1003,
            'updated_at' => $now - 3 * 24 * 3600,
        ]);

        $this->artisan('check:ticket')->assertExitCode(0);

        $this->assertSame(Ticket::STATUS_CLOSED, $staleAdminReplied->fresh()->status);
        $this->assertSame(Ticket::STATUS_OPENING, $recentAdminReplied->fresh()->status);
        $this->assertSame(Ticket::STATUS_OPENING, $waitingForAdmin->fresh()->status);
    }

    public function test_replied_ticket_is_not_closed_when_last_reply_is_user(): void
    {
        $ticket = $this->makeTicket([
            'user_id' => 1001,
            'reply_status' => Ticket::REPLY_STATUS_REPLIED,
            'last_reply_user_id' => 1001,
            'updated_at' => time() - 3 * 24 * 3600,
        ]);

        $this->artisan('check:ticket')->assertExitCode(0);

        $this->assertSame(Ticket::STATUS_OPENING, $ticket->fresh()->status);
    }

    private function makeTicket(array $attributes = []): Ticket
    {
        $now = time();

        return Ticket::create(array_merge([
            'user_id' => 1001,
            'subject' => 'test-ticket',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => 1001,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));
    }
}
