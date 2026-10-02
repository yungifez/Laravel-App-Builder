<?php

namespace TraceRecorder;

use GuzzleHttp\Psr7\Response;

/**
 * The answer of an outside service that could not do what it was asked: a
 * server error. It tells the recorder when it is asked for its status, so
 * the trace says if the app looked at how the call went.
 */
class Answer extends Response
{
    public function __construct(protected Recorder $recorder)
    {
        parent::__construct(500, ['Content-Type' => 'application/json'], '{"message":"Server Error"}');
    }

    public function getStatusCode(): int
    {
        $this->recorder->asked();

        return parent::getStatusCode();
    }
}
