<?php

namespace App\Workspaces\Machines\Contracts;

/**
 * A cloud that starts and deletes runner machines for the pool. A cloud
 * only handles whole machines: once started, a machine runs its boot
 * script, and its runner connects to the control plane by itself, like a
 * machine added by hand. Nothing else needs to know which cloud it is on.
 */
interface MachineCloud
{
    /**
     * Start a machine that runs the boot script once, and return its id on
     * this cloud. The name is the runner's name.
     */
    public function create(string $name, string $bootScript): string;

    /**
     * Delete a machine and its disk. A machine that is already gone is not
     * an error.
     */
    public function delete(string $id): void;

    /**
     * Get the machines this control plane started on the cloud, by id, with
     * the runner name each was started for.
     *
     * @return array<string, string>
     */
    public function machines(): array;

    /**
     * Get a shell command that prints the address the control plane reaches
     * the machine's previews at, usually its private network address.
     */
    public function serviceHostCommand(): string;

    /**
     * Get how many minutes the cloud bills a machine for at a time, so an
     * empty machine is kept until its paid time is nearly over. Null when
     * the cloud bills by the second.
     */
    public function billingMinutes(): ?int;
}
