<?php

namespace App\Publishing\Contracts;

use App\Models\Deployment;
use App\Models\Project;
use App\Projects\Exceptions\RepositoryConflict;
use App\Publishing\Exceptions\PublishingFailed;
use App\Publishing\ReleaseProgress;
use Carbon\CarbonImmutable;

/**
 * A place that serves published apps. A host takes a checked commit and puts
 * it online; the checks before and after are the same for every host.
 */
interface PublishingHost
{
    /**
     * Determine if the project can be published without asking the owner
     * where to first.
     */
    public function ready(Project $project): bool;

    /**
     * Get the branch a release of the project goes to.
     */
    public function branch(Project $project): string;

    /**
     * Put the deployment's commit on the host and start taking it online,
     * setting the project's address when the host gives one.
     *
     * @throws PublishingFailed with a reason the owner can read.
     * @throws RepositoryConflict when the host has commits the project does not.
     */
    public function release(Project $project, Deployment $deployment): void;

    /**
     * Save a copy of the app's stored information before a release changes
     * how it is stored, and get the host's id for the copy, or null when the
     * host keeps no copies for us.
     *
     * @throws PublishingFailed when the host keeps copies but could not save one.
     */
    public function backup(Project $project, Deployment $deployment): ?string;

    /**
     * Get how far the host is with taking the deployment online.
     */
    public function progress(Deployment $deployment): ReleaseProgress;

    /**
     * Get what the host said when it could not build or start the
     * deployment's version, or null when it gave up for another reason,
     * such as the release being cancelled. Sending the same version again
     * fails the same way then, so its words are what a fix needs.
     */
    public function failure(Deployment $deployment): ?string;

    /**
     * Get the errors the app raised online between two moments, oldest
     * first, or null when the host cannot tell.
     *
     * @return list<array{class: string|null, message: string, at: string}>|null
     */
    public function errors(Deployment $deployment, CarbonImmutable $from, CarbonImmutable $to): ?array;

    /**
     * Get what the host charges us this billing period, in total and per
     * app it serves, or null when it does not tell us.
     *
     * @return array{currency: string, total_cents: int, applications: list<array{application: string, cents: int}>}|null
     */
    public function spend(): ?array;
}
