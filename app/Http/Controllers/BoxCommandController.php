<?php

namespace App\Http\Controllers;

use App\Actions\Workspaces\ClaimBoxCommands;
use App\Actions\Workspaces\FinishBoxCommand;
use App\Http\Requests\BoxCommandResultRequest;
use App\Models\BoxCommand;
use App\Workspaces\Drivers\RunnerDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BoxCommandController extends Controller
{
    /**
     * Hand the runner its new commands and the ones to stop.
     */
    public function claim(Request $request, ClaimBoxCommands $claimBoxCommands): JsonResponse
    {
        return response()->json($claimBoxCommands->handle((string) $request->attributes->get('runner')));
    }

    /**
     * Record how a command ended.
     */
    public function result(BoxCommandResultRequest $request, BoxCommand $command, FinishBoxCommand $finishBoxCommand): Response
    {
        $finishBoxCommand->handle($command, $request->validated());

        return response()->noContent();
    }

    /**
     * Send the packed project an "unpack" command points at.
     */
    public function archive(Request $request, BoxCommand $command): BinaryFileResponse
    {
        abort_unless($command->runner === $request->attributes->get('runner') && $command->type === 'unpack', 404);

        $path = RunnerDriver::archivePath((string) ($command->payload['archive'] ?? ''));

        abort_unless(is_file($path), 404);

        return response()->download($path);
    }
}
