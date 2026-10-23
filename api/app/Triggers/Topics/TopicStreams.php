<?php

namespace App\Triggers\Topics;

use App\Services\Manifests\QueueConsumerManifests;

/**
 * Redis Stream keys of topics: runr.topic.<name>, plus the organization suffix queue streams get
 * in a multi-tenant install (QueueConsumerManifests::resolveStreamName: "<stream>-<tenant>"). The
 * region's topic publisher builds the same key from the suffix in its token (TopicTokens).
 */
final class TopicStreams
{
    public const PREFIX = 'runr.topic.';

    public static function key(string $topic): string
    {
        return self::PREFIX.$topic.self::suffix();
    }

    /** The organization suffix of stream names ("" without tenancy). */
    public static function suffix(): string
    {
        return QueueConsumerManifests::resolveStreamName('');
    }
}
