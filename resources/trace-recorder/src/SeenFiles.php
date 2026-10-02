<?php

namespace TraceRecorder;

use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\DecoratedAdapter;

/**
 * Stands in for the app's disks (the Storage facade): each disk it makes
 * tells the recorder of every file the app writes, copies, moves or deletes (see SeenDisk). A disk
 * the app made before the recorder started, or makes with a driver of its
 * own, is not seen.
 */
class SeenFiles extends FilesystemManager
{
    public ?Recorder $traceRecorder = null;

    /**
     * Take the place of the app's disks. What the app set up stays: its
     * own drivers and the disks it has made.
     */
    public static function over(FilesystemManager $files, mixed $app, Recorder $recorder): self
    {
        $seen = new self($app);
        $seen->traceRecorder = $recorder;
        $seen->customCreators = $files->customCreators;
        $seen->disks = $files->disks;

        return $seen;
    }

    protected function createFlysystem(...$arguments)
    {
        // An older Flysystem has nothing to put around a disk.
        if ($this->traceRecorder !== null && isset($arguments[0]) && class_exists(DecoratedAdapter::class)) {
            $disk = new SeenDisk($arguments[0]);
            $disk->traceRecorder = $this->traceRecorder;
            $arguments[0] = $disk;
        }

        return parent::createFlysystem(...$arguments);
    }
}
