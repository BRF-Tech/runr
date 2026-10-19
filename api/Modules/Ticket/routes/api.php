<?php

use Illuminate\Support\Facades\Route;
use Modules\Ticket\Http\Controllers\AdminSupportTicketController;
use Modules\Ticket\Http\Controllers\SupportTicketController;

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('/support-tickets', [SupportTicketController::class, 'index']);
    Route::post('/support-tickets', [SupportTicketController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/support-tickets/stats', [SupportTicketController::class, 'stats']);
    Route::get('/support-tickets/suggestions', [SupportTicketController::class, 'suggestions']);
    Route::get('/support-tickets/{supportTicket}', [SupportTicketController::class, 'show']);
    Route::post('/support-tickets/{supportTicket}/replies', [SupportTicketController::class, 'reply'])->middleware('throttle:30,1');
    Route::get('/support-tickets/{supportTicket}/attachments/{filename}', [SupportTicketController::class, 'downloadAttachment']);
    Route::post('/support-tickets/{supportTicket}/read', [SupportTicketController::class, 'markRead']);

    Route::get('/admin/support-tickets', [AdminSupportTicketController::class, 'index']);
    Route::get('/admin/support-tickets/stats', [AdminSupportTicketController::class, 'stats']);
    // Assignee choices of the staff view (before {id})
    Route::get('/admin/support-tickets/staff', [AdminSupportTicketController::class, 'staff']);
    Route::get('/admin/support-tickets/{id}', [AdminSupportTicketController::class, 'show']);
    Route::patch('/admin/support-tickets/{id}/status', [AdminSupportTicketController::class, 'updateStatus']);
    Route::patch('/admin/support-tickets/{id}/assign', [AdminSupportTicketController::class, 'assign']);
    Route::patch('/admin/support-tickets/{id}/tags', [AdminSupportTicketController::class, 'updateTags']);
    Route::post('/admin/support-tickets/{id}/replies', [AdminSupportTicketController::class, 'reply'])->middleware('throttle:30,1');
    Route::get('/admin/support-tickets/{id}/attachments/{filename}', [AdminSupportTicketController::class, 'downloadAttachment']);
    Route::post('/admin/support-tickets/{id}/read', [AdminSupportTicketController::class, 'adminMarkRead']);
});
