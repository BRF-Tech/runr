<?php

namespace App\Services\Observability\Alerts\Channels;

use App\Models\Observability\AlertChannel;
use App\Services\Observability\Alerts\AlertMessage;

/** One channel type: validates its configuration and sends a message. */
interface ChannelSender
{
    /**
     * Validation rules of the channel configuration (keys of config.*).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    public function send(AlertChannel $channel, AlertMessage $message): DeliveryResult;
}
