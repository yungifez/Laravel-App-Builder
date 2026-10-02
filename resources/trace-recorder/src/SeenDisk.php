<?php

namespace TraceRecorder;

use League\Flysystem\DecoratedAdapter;

/**
 * Stands around one disk of the app (see SeenFiles): it tells the recorder
 * of each file the app writes, then does what the disk does. Only that a
 * file is written is noted, never its name or what is in it.
 */
class SeenDisk extends DecoratedAdapter
{
    public ?Recorder $traceRecorder = null;

    public function write(...$arguments): void
    {
        $this->traceRecorder?->stored((string) ($arguments[0] ?? ''));

        parent::write(...$arguments);
    }

    public function writeStream(...$arguments): void
    {
        $this->traceRecorder?->stored((string) ($arguments[0] ?? ''));

        parent::writeStream(...$arguments);
    }
}
