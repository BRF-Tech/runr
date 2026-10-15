<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Mail\ContactFormMail;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(ContactRequest $request)
    {
        $data = $request->validated();

        Mail::to(config('mail.from.address'))
            ->send(new ContactFormMail($data));

        return response()->json([
            'message' => __('api.contact.sent'),
        ]);
    }
}
