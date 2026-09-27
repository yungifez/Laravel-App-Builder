<?php

namespace App\Publishing\Exceptions;

use RuntimeException;

/**
 * The host did not take a release. The message is written for the owner.
 */
class PublishingFailed extends RuntimeException {}
