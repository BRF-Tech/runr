<?php

namespace Modules\Ticket\Console\Concerns;

use App\Models\User;

trait TicketE2eApiTrait
{
    protected function runApiTests(array &$results): void
    {
        $results[] = $this->runTest('user: create ticket', function () {
            $r = $this->api($this->userToken)->post('/api/support-tickets', [
                'subject' => 'Test ticket subject',
                'description' => 'This is a test ticket description',
                'category' => 'other',
                'priority' => 'medium',
            ]);
            $this->assert($r->status() === 201, "Expected 201, got {$r->status()}: {$r->body()}");
            $this->assert(! empty($r->json('id')), 'No ticket id in response');
            $this->ticketId = $r->json('id');
        });

        $results[] = $this->runTest('user: list tickets', function () {
            $r = $this->api($this->userToken)->get('/api/support-tickets');
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert(count($r->json('data') ?? []) >= 1, 'No tickets in list');
        });

        $results[] = $this->runTest('user: get ticket detail', function () {
            $r = $this->api($this->userToken)->get("/api/support-tickets/{$this->ticketId}");
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert($r->json('id') === $this->ticketId, 'Ticket id mismatch');
        });

        $results[] = $this->runTest('user: ticket stats', function () {
            $r = $this->api($this->userToken)->get('/api/support-tickets/stats');
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert(isset($r->json()['open']), 'No open count in stats');
        });

        $results[] = $this->runTest('user: reply to ticket', function () {
            $r = $this->api($this->userToken)->post("/api/support-tickets/{$this->ticketId}/replies", [
                'message' => 'User reply message',
            ]);
            $this->assert($r->status() === 201, "Expected 201, got {$r->status()}: {$r->body()}");
            $this->assert(! empty($r->json('id')), 'No reply id in response');
        });

        $results[] = $this->runTest('user: mark ticket read', function () {
            $r = $this->api($this->userToken)->post("/api/support-tickets/{$this->ticketId}/read");
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
        });

        $results[] = $this->runTest('admin: list all tickets', function () {
            $r = $this->api($this->adminToken)->get('/api/admin/support-tickets');
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert(count($r->json('data') ?? []) >= 1, 'No tickets in admin list');
        });

        $results[] = $this->runTest('admin: get ticket detail', function () {
            $r = $this->api($this->adminToken)->get("/api/admin/support-tickets/{$this->ticketId}");
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert($r->json('id') === $this->ticketId, 'Ticket id mismatch');
        });

        $results[] = $this->runTest('admin: reply to ticket', function () {
            $r = $this->api($this->adminToken)->post("/api/admin/support-tickets/{$this->ticketId}/replies", [
                'message' => 'Staff reply message',
                'is_internal' => false,
            ]);
            $this->assert($r->status() === 201, "Expected 201, got {$r->status()}: {$r->body()}");
            $this->assert($r->json('is_staff') === true, 'Reply not marked as staff');
        });

        $results[] = $this->runTest('admin: internal note', function () {
            $r = $this->api($this->adminToken)->post("/api/admin/support-tickets/{$this->ticketId}/replies", [
                'message' => 'Internal note only staff sees',
                'is_internal' => true,
            ]);
            $this->assert($r->status() === 201, "Expected 201, got {$r->status()}");
            $this->assert($r->json('is_internal') === true, 'Reply not marked as internal');
        });

        $results[] = $this->runTest('admin: change status', function () {
            $r = $this->api($this->adminToken)->patch("/api/admin/support-tickets/{$this->ticketId}/status", [
                'status' => 'in_progress',
            ]);
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert($r->json('status') === 'in_progress', 'Status not updated');
        });

        $results[] = $this->runTest('admin: update tags', function () {
            $r = $this->api($this->adminToken)->patch("/api/admin/support-tickets/{$this->ticketId}/tags", [
                'tags' => ['billing', 'urgent'],
            ]);
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert(in_array('billing', $r->json('tags') ?? []), 'Tag not saved');
        });

        $results[] = $this->runTest('admin: mark ticket read', function () {
            $r = $this->api($this->adminToken)->post("/api/admin/support-tickets/{$this->ticketId}/read");
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
        });

        $results[] = $this->runTest('admin: ticket stats', function () {
            $r = $this->api($this->adminToken)->get('/api/admin/support-tickets/stats');
            $this->assert($r->successful(), "Expected 2xx, got {$r->status()}");
            $this->assert(isset($r->json()['total']), 'No total in admin stats');
        });

        $results[] = $this->runTest('user: cannot access admin endpoints', function () {
            $r = $this->api($this->userToken)->get('/api/admin/support-tickets');
            $this->assert($r->status() === 403 || $r->status() === 401, "Expected 401/403, got {$r->status()}");
        });

        $results[] = $this->runTest('user: cannot access other user ticket', function () {
            // Create a second user and try to access first user's ticket (no factory: the API
            // image ships without the dev dependencies, faker among them)
            User::create(['name' => 'Other User', 'username' => 'otheruser', 'email' => 'other@test.com', 'password' => bcrypt('pass1234')]);
            $token2 = $this->doLogin('otheruser', 'pass1234');
            $r = $this->api($token2)->get("/api/support-tickets/{$this->ticketId}");
            $this->assert($r->status() === 403, "Expected 403, got {$r->status()}");
        });
    }
}
