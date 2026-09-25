<?php

namespace App\Runs\Contracts;

use App\Runs\Exceptions\ToolFailed;
use App\Runs\Exceptions\ToolRejected;
use App\Runs\ToolContext;

interface Tool
{
    /**
     * Get the validation rules for the tool's arguments.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Run the tool and return its result.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws ToolRejected when the call is not allowed; nothing changed.
     * @throws ToolFailed when the call could not complete.
     */
    public function handle(ToolContext $context, array $arguments): array;
}
