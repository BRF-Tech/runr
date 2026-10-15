<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Mail\AlertMail;
use App\Models\Observability\AlertChannel;
use App\Services\Observability\Alerts\AlertMessage;
use Illuminate\Support\Facades\Mail;

/** E-mail through the installation's mailer (MAIL_* settings). */
class EmailChannel implements ChannelSender
{
    public function rules(): array
    {
        return [
            'config.emails' => ['required', 'array', 'min:1', 'max:20'],
            'config.emails.*' => ['required', 'email:rfc', 'max:254'],
        ];
    }

    public function send(AlertChannel $channel, AlertMessage $message): DeliveryResult
    {
        $emails = array_values(array_filter((array) ($channel->config['emails'] ?? []), 'is_string'));
        if (! $emails) {
            return DeliveryResult::failed(__('observability.alerts.no_recipients'), false);
        }

        try {
            Mail::to($emails)->send(new AlertMail($message));
        } catch (\Throwable $e) {
            return DeliveryResult::failed($e->getMessage());
        }

        return new DeliveryResult(true);
    }
}
