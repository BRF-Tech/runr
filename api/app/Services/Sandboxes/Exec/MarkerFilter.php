<?php

namespace App\Services\Sandboxes\Exec;

/**
 * Takes whole markers (fixed strings, each with the exec's id) out of a stream that also carries a
 * command's own bytes: the acknowledgements of acknowledged input on stderr (ExecScript::ackMarker,
 * ExecScript::ackEndMarker). An end of a chunk that may be the start of a marker is held back until
 * the next chunk shows whether it is one; flush() returns it at the end of the stream. Bytes that
 * are not a whole marker pass unchanged, a lone 0x06 or 0x1e included.
 */
final class MarkerFilter
{
    private string $tail = '';

    /** @param  list<string>  $markers */
    public function __construct(private readonly array $markers) {}

    /**
     * @return array{0: string, 1: list<string>} the bytes without the whole markers, and the markers
     *                                           found, in order
     */
    public function push(string $chunk): array
    {
        $data = $this->tail.$chunk;
        $this->tail = '';
        $out = '';
        $found = [];
        while (true) {
            $first = null;
            $at = null;
            foreach ($this->markers as $marker) {
                $pos = strpos($data, $marker);
                if ($pos !== false && ($at === null || $pos < $at)) {
                    [$first, $at] = [$marker, $pos];
                }
            }
            if ($first === null || $at === null) {
                break;
            }
            $out .= substr($data, 0, $at);
            $found[] = $first;
            $data = (string) substr($data, $at + strlen($first));
        }

        $keep = 0;
        foreach ($this->markers as $marker) {
            for ($n = min(strlen($data), strlen($marker) - 1); $n > $keep; $n--) {
                if (str_starts_with($marker, substr($data, -$n))) {
                    $keep = $n;
                    break;
                }
            }
        }
        if ($keep > 0) {
            $this->tail = substr($data, -$keep);
            $data = substr($data, 0, -$keep);
        }

        return [$out.$data, $found];
    }

    /** The end of the stream: what was held back, as it came. */
    public function flush(): string
    {
        $tail = $this->tail;
        $this->tail = '';

        return $tail;
    }
}
