<?php

namespace Tests\Unit;

use App\Features\SwapProbes;
use Tests\TestCase;

class SwapProbesTest extends TestCase
{
    protected const CONTROLLER = 'App\Http\Controllers\ProjectController';

    /**
     * What the script prints for an app whose projects belong to a team,
     * whose notes belong to a person, and whose tags belong to nobody.
     *
     * @param  list<array<string, mixed>>  $routes
     */
    protected function printed(array $routes): string
    {
        return "Booting.\n".json_encode([
            'user' => 'User',
            'routes' => $routes,
            'owners' => [
                'Project' => [['path' => [['relation' => 'team', 'model' => 'Team']], 'end' => 'Team']],
                'Task' => [['path' => [['relation' => 'project', 'model' => 'Project'], ['relation' => 'team', 'model' => 'Team']], 'end' => 'Team']],
                'Team' => [['path' => [], 'end' => 'Team']],
                'Note' => [['path' => [['relation' => 'user', 'model' => 'User']], 'end' => 'user']],
                'User' => [['path' => [], 'end' => 'user']],
            ],
            'tenants' => ['Team' => ['relation' => 'members', 'column' => 'role', 'role' => 'owner']],
        ]);
    }

    /**
     * A route of the projects controller.
     *
     * @param  list<string>  $methods
     * @param  list<array{string, string|null}>  $params  Each parameter's name and model
     * @return array<string, mixed>
     */
    protected function route(array $methods, string $uri, array $params, string $action = 'show', string $controller = self::CONTROLLER): array
    {
        return ['methods' => $methods, 'uri' => $uri, 'name' => null, 'domain' => null, 'controller' => $controller, 'action' => $action, 'params' => array_map(fn (array $param) => ['name' => $param[0], 'model' => $param[1], 'field' => null], $params)];
    }

    public function test_each_route_of_a_touched_controller_is_swapped_and_a_nested_one_also_by_its_last_record(): void
    {
        $found = SwapProbes::found($this->printed([
            $this->route(['GET', 'HEAD'], '/projects/{project}', [['project', 'Project']]),
            $this->route(['POST'], '/projects/{project}/archive', [['project', 'Project']], 'archive'),
            $this->route(['POST'], '/projects/{project}/tasks', [['project', 'Project']], 'store'),
            $this->route(['PUT'], '/projects/{project}/tasks/{task}', [['project', 'Project'], ['task', 'Task']], 'update'),
            // Another controller's route is not the change's.
            $this->route(['GET'], '/notes/{note}', [['note', 'Note']], 'show', 'App\Http\Controllers\NoteController'),
        ]));

        $this->assertNotNull($found);
        $plan = SwapProbes::plan($found, [self::CONTROLLER], [], 30);
        $probes = array_map(fn (array $probe) => "{$probe['method']} {$probe['uri']} {$probe['mode']} {$probe['action']} ".($probe['payload'] ?? '-'), $plan['probes']);

        $this->assertSame([
            'GET /projects/{project} all view -',
            'POST /projects/{project}/archive all act -',
            'POST /projects/{project}/tasks all create Task',
            'PUT /projects/{project}/tasks/{task} all update Task',
            'PUT /projects/{project}/tasks/{task} leaf update Task',
        ], $probes);
        $this->assertSame([0, 'Team'], [$plan['skipped'], $plan['probes'][0]['team']]);
    }

    public function test_a_route_with_a_value_that_is_not_a_record_or_a_record_with_no_owner_is_counted_and_left_out(): void
    {
        $found = SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}/files/{name}', [['project', 'Project'], ['name', null]]),
            $this->route(['GET'], '/tags/{tag}', [['tag', 'Tag']]),
            // A note's address under a project: the note is not found on the project's links.
            $this->route(['GET'], '/projects/{project}/notes/{note}', [['project', 'Project'], ['note', 'Note']]),
            // A team's members are the role probes'.
            $this->route(['DELETE'], '/teams/{team}/members/{member}', [['team', 'Team'], ['member', 'User']]),
        ]));

        $plan = SwapProbes::plan((array) $found, [self::CONTROLLER], [], 30);

        $this->assertSame([[], 3], [$plan['probes'], $plan['skipped']]);
    }

    public function test_a_route_a_person_outside_the_team_already_tries_is_not_tried_again_and_the_limit_holds(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}', [['project', 'Project']]),
            $this->route(['DELETE'], '/projects/{project}', [['project', 'Project']], 'destroy'),
            $this->route(['PATCH'], '/projects/{project}', [['project', 'Project']], 'update'),
        ]));

        $plan = SwapProbes::plan($found, [self::CONTROLLER], ['GET /projects/{project}'], 1);

        $this->assertSame(['DELETE'], array_column($plan['probes'], 'method'));
    }

    public function test_output_that_is_not_the_scripts_or_names_that_are_not_words_are_not_read(): void
    {
        $this->assertNull(SwapProbes::found('Fatal error: Class "Team" not found'));

        $found = SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}', [['project";exit;//', 'Project']]),
        ]));

        $this->assertSame([], $found['routes'] ?? null, 'a parameter that is not a word drops its route');
    }

    public function test_a_swap_that_worked_is_a_finding_and_one_refused_or_shared_is_not(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}', [['project', 'Project']]),
            $this->route(['POST'], '/projects/{project}/archive', [['project', 'Project']], 'archive'),
            $this->route(['DELETE'], '/projects/{project}', [['project', 'Project']], 'destroy'),
            $this->route(['GET'], '/projects/{project}/tasks/{task}', [['project', 'Project'], ['task', 'Task']]),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'];
        $sent = fn (int $status, int $writes = 0, bool $invalid = false) => compact('status', 'invalid', 'writes');
        $line = fn (int $id, array $control, array $swap, ?int $guest = null, ?bool $policy = null) => json_encode(['id' => $id, 'owners' => true, 'control' => $control, 'swap' => $swap, 'guest' => $guest, 'policy' => $policy]);

        $measured = SwapProbes::measure($probes, SwapProbes::parse(implode("\n", [
            // Another team's project opened.
            $line(0, $sent(200), $sent(200), 302, false),
            // Archived another team's project.
            $line(1, $sent(200, 1), $sent(302, 1)),
            // Refused: the delete wrote nothing.
            $line(2, $sent(302, 1), $sent(403)),
            // All of another team's: shared, anyone can open it.
            $line(3, $sent(200), $sent(200), 200),
            // My project, your task: opened.
            $line(4, $sent(200), $sent(200), 302),
        ])));

        $this->assertSame([4, 1, 1, 0], [$measured['tried'], $measured['refused'], $measured['shared'], $measured['untried']]);
        $this->assertSame(['GET all', 'POST all', 'GET leaf'], array_map(fn (array $finding) => "{$finding['method']} {$finding['mode']}", $measured['findings']));

        $said = SwapProbes::describe($measured, 2);
        $this->assertStringContainsString('A signed-in person could see a project of another team: GET /projects/{project} answered 200. Make this route check the Project policy', $said);
        $this->assertStringContainsString('could act on a project of another team: POST /projects/{project}/archive answered 302.', $said);
        $this->assertStringContainsString('could see a task of another team, through the address of their own project: GET /projects/{project}/tasks/{task} answered 200. Scope the route\'s bindings', $said);
        $this->assertStringContainsString('1 is shared on purpose', $said);
        $this->assertStringContainsString('2 routes were left out', $said);
    }

    public function test_a_swap_proves_nothing_when_the_persons_own_records_did_not_work_or_could_not_be_made(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}', [['project', 'Project']]),
            $this->route(['PATCH'], '/projects/{project}', [['project', 'Project']], 'update'),
            $this->route(['POST'], '/projects/{project}/archive', [['project', 'Project']], 'archive'),
            $this->route(['DELETE'], '/projects/{project}', [['project', 'Project']], 'destroy'),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'];
        $sent = fn (int $status, int $writes = 0, bool $invalid = false) => compact('status', 'invalid', 'writes');

        $measured = SwapProbes::measure($probes, SwapProbes::parse(implode("\n", [
            // The person's own project was not found.
            json_encode(['id' => 0, 'owners' => true, 'control' => $sent(404), 'swap' => $sent(200)]),
            // The values were turned down.
            json_encode(['id' => 1, 'owners' => true, 'control' => $sent(302, 0, true), 'swap' => $sent(302, 1)]),
            // The factory broke.
            json_encode(['id' => 2, 'broke' => true]),
            // The swap broke the page.
            json_encode(['id' => 3, 'owners' => true, 'control' => $sent(302, 1), 'swap' => $sent(500, 1)]),
        ])));

        $this->assertSame([0, 4, []], [$measured['tried'], $measured['untried'], $measured['findings']]);
    }

    public function test_a_change_that_wrote_nothing_still_judges_a_refusal_but_not_a_swap_that_went_through(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['PUT'], '/current-team/{team}', [['team', 'Team']], 'update', 'App\Http\Controllers\CurrentTeamController'),
            $this->route(['PATCH'], '/projects/{project}', [['project', 'Project']], 'update'),
            $this->route(['POST'], '/projects/{project}/archive', [['project', 'Project']], 'archive'),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER, 'App\Http\Controllers\CurrentTeamController'], [], 30)['probes'];
        $sent = fn (int $status, int $writes = 0, bool $invalid = false) => compact('status', 'invalid', 'writes');

        $measured = SwapProbes::measure($probes, SwapProbes::parse(implode("\n", [
            // Switching to the team the person is already on writes nothing; another team's is refused.
            json_encode(['id' => 0, 'owners' => true, 'control' => $sent(302), 'swap' => $sent(403), 'guest' => null, 'policy' => false]),
            // Nothing written either way: no proof the swap did anything.
            json_encode(['id' => 1, 'owners' => true, 'control' => $sent(302), 'swap' => $sent(302), 'guest' => null, 'policy' => false]),
            // The person's own values were turned down, so the refusal may be the values'.
            json_encode(['id' => 2, 'owners' => true, 'control' => $sent(302, 0, true), 'swap' => $sent(404), 'guest' => null, 'policy' => false]),
        ])));

        $this->assertSame([1, 1, 2, []], [$measured['tried'], $measured['refused'], $measured['untried'], $measured['findings']]);
    }

    public function test_the_test_makes_each_persons_records_with_the_apps_factories(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}/tasks/{task}', [['project', 'Project'], ['task', 'Task']]),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'];

        $test = SwapProbes::test($probes, $found, 'storage/logs/access/swaps.jsonl');

        $this->assertStringContainsString('class SwapProbeTest extends TestCase', $test);
        $this->assertStringContainsString("\$this->probe(1, 'GET', '/projects/{project}/tasks/{task}',", $test);
        $this->assertStringContainsString("'Task' => array ( 0 => array ( 'path' => array ( 0 => array ( 'relation' => 'project', 'model' => 'Project', ), 1 => array ( 'relation' => 'team', 'model' => 'Team', ), ), 'end' => 'Team', ), ),", $test);
        $this->assertStringNotContainsString("'Note' =>", $test, 'only the owners of records the swaps use');
        $this->assertStringContainsString("base_path('storage/logs/access/swaps.jsonl')", $test);
        $this->assertStringNotContainsString('__', str_replace(['__construct', '__invoke'], '', $test), 'every placeholder is filled');
    }
}
