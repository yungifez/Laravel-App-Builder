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
     * @param  array<string, list<array<string, string>>>  $children
     */
    protected function printed(array $routes, array $children = []): string
    {
        return "Booting.\n".json_encode([
            'user' => 'User',
            'routes' => $routes,
            'owners' => [
                'Project' => [['path' => [['relation' => 'team', 'model' => 'Team', 'key' => 'team_id']], 'end' => 'Team']],
                'Task' => [['path' => [['relation' => 'project', 'model' => 'Project', 'key' => 'project_id'], ['relation' => 'team', 'model' => 'Team', 'key' => 'team_id']], 'end' => 'Team']],
                'Team' => [['path' => [], 'end' => 'Team']],
                'Note' => [['path' => [['relation' => 'user', 'model' => 'User', 'key' => 'user_id']], 'end' => 'user']],
                'User' => [['path' => [], 'end' => 'user']],
            ],
            'tenants' => ['Team' => ['relation' => 'members', 'column' => 'role', 'role' => 'owner']],
            'children' => $children,
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
            // The form keys come last: the task's project, sent as another team's.
            'POST /projects/{project}/tasks field create Task',
            'PUT /projects/{project}/tasks/{task} field update Task',
            // Then the extra fields of each form that saves.
            'POST /projects/{project}/tasks raise create Task',
            'PUT /projects/{project}/tasks/{task} raise update Task',
        ], $probes);
        $this->assertSame(['project_id', 'Project'], [$plan['probes'][5]['key'], $plan['probes'][5]['target']]);
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

        // The fifth and sixth are the PATCH's form key and extra fields, which never reported.
        $this->assertSame([0, 6, []], [$measured['tried'], $measured['untried'], $measured['findings']]);
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

        // The others untried are the PATCH's form key and both forms' extra fields, which never reported.
        $this->assertSame([1, 1, 5, []], [$measured['tried'], $measured['refused'], $measured['untried'], $measured['findings']]);
    }

    public function test_a_form_that_saved_a_row_pointing_at_someone_elses_record_is_a_finding_and_one_that_kept_its_own_key_is_not(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            // No record in the address: only the form can name one.
            $this->route(['POST'], '/tasks', []),
            $this->route(['PATCH'], '/projects/{project}', [['project', 'Project']], 'update'),
            $this->route(['POST'], '/notes', [], 'store'),
            $this->route(['POST'], '/projects/{project}/tasks', [['project', 'Project']], 'store'),
        ]));
        $probes = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::FIELD));
        $sent = fn (int $status, int $writes = 1, ?int $landed = null) => compact('status', 'writes', 'landed') + ['invalid' => false];
        $line = fn (int $id, array $control, array $swap) => json_encode(['id' => $id, 'owners' => true, 'control' => $control, 'swap' => $swap, 'guest' => null, 'policy' => null]);

        $this->assertSame([
            'POST /tasks create project_id',
            'PATCH /projects/{project} update team_id',
            'POST /notes assign user_id',
            'POST /projects/{project}/tasks create project_id',
        ], array_map(fn (array $probe) => "{$probe['method']} {$probe['uri']} {$probe['action']} {$probe['key']}", $probes));

        $measured = SwapProbes::measure($probes, SwapProbes::parse(implode("\n", [
            // Saved a task in another team's project.
            $line(0, $sent(302, 1, 1), $sent(302, 1, 1)),
            // Moved a project into a team the person is not in.
            $line(1, $sent(302, 1, 0), $sent(302, 1, 1)),
            // Saved a note in another person's name.
            $line(2, $sent(201, 1, 1), $sent(201, 1, 1)),
            // Wrote, but the task went to the project in the address: refused.
            $line(3, $sent(302, 1, 1), $sent(302, 1, 0)),
        ])));

        $this->assertSame([4, 1, 0], [$measured['tried'], $measured['refused'], $measured['untried']]);
        $said = SwapProbes::describe($measured, 0);
        $this->assertStringContainsString('A signed-in person could put a task in a project of another team: POST /tasks saved one with project_id set to it. Check that the project the form names is one the person may reach', $said);
        $this->assertStringContainsString('could move a project into a team they are not in: PATCH /projects/{project} saved one with team_id set to it.', $said);
        $this->assertStringContainsString('could save a note in another person\'s name: POST /notes saved one with user_id set to them. Set user_id from the signed-in person, not from the form.', $said);
    }

    public function test_a_form_swap_proves_nothing_when_the_persons_own_send_wrote_nothing_or_was_turned_down(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['POST'], '/tasks', []),
            $this->route(['POST'], '/notes', [], 'store'),
            // A form for a record nobody owns, or one that only does something, names no key.
            $this->route(['POST'], '/tags', [], 'store'),
            $this->route(['POST'], '/projects/{project}/archive', [['project', 'Project']], 'archive'),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'];
        $fields = array_values(array_filter($probes, fn (array $probe) => $probe['mode'] === SwapProbes::FIELD));
        $sent = fn (int $status, int $writes, ?int $landed, bool $invalid = false) => compact('status', 'writes', 'landed', 'invalid');

        $measured = SwapProbes::measure($fields, SwapProbes::parse(implode("\n", [
            json_encode(['id' => 0, 'owners' => true, 'control' => $sent(302, 0, 0), 'swap' => $sent(302, 1, 1)]),
            json_encode(['id' => 1, 'owners' => true, 'control' => $sent(302, 0, 0, true), 'swap' => $sent(302, 1, 1)]),
        ])));

        $this->assertSame(['POST /tasks', 'POST /notes'], array_map(fn (array $probe) => "{$probe['method']} {$probe['uri']}", $fields));
        $this->assertSame([0, 2, []], [$measured['tried'], $measured['untried'], $measured['findings']]);
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
        $this->assertStringContainsString("'Task' => array ( 0 => array ( 'path' => array ( 0 => array ( 'relation' => 'project', 'model' => 'Project', 'key' => 'project_id', ), 1 => array ( 'relation' => 'team', 'model' => 'Team', 'key' => 'team_id', ), ), 'end' => 'Team', ), ),", $test);
        $this->assertStringNotContainsString("'Note' =>", $test, 'only the owners of records the swaps use');
        $this->assertStringContainsString("base_path('storage/logs/access/swaps.jsonl')", $test);
        $this->assertStringNotContainsString('__', str_replace(['__construct', '__invoke'], '', $test), 'every placeholder is filled');
    }

    public function test_each_form_that_saves_is_sent_with_extra_fields_and_the_ones_its_code_names_are_left_out(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            // A profile form: no record in the address, so it saves the person's own account.
            [...$this->route(['PATCH'], '/settings/profile', [], 'update'), 'named' => []],
            // An admin's own form for roles names role, and a name that is not an extra field is dropped.
            [...$this->route(['PUT'], '/projects/{project}', [['project', 'Project']], 'update'), 'named' => ['role', 'colour']],
            // A form that only does something saves no record of its own.
            [...$this->route(['POST'], '/projects/{project}/archive', [['project', 'Project']], 'archive'), 'named' => []],
        ]));
        $raises = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::RAISE));

        $this->assertSame([
            'PATCH /settings/profile update User',
            'PUT /projects/{project} update Project role',
        ], array_map(fn (array $probe) => trim("{$probe['method']} {$probe['uri']} {$probe['action']} {$probe['payload']} ".implode(',', $probe['named'])), $raises));
        $this->assertStringContainsString("\$this->probe(0, 'PATCH', '/settings/profile', array ( ), 'User', 'raise', NULL, NULL, NULL, array ( ));", SwapProbes::test($raises, $found, 'swaps.jsonl'));
    }

    public function test_an_extra_field_saved_is_a_finding_unless_the_form_saved_it_without_it_too(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            [...$this->route(['PATCH'], '/settings/profile', [], 'update'), 'named' => []],
            [...$this->route(['POST'], '/notes', [], 'store'), 'named' => []],
            [...$this->route(['PUT'], '/projects/{project}', [['project', 'Project']], 'update'), 'named' => []],
            [...$this->route(['PATCH'], '/projects/{project}/tasks/{task}', [['project', 'Project'], ['task', 'Task']], 'update'), 'named' => []],
            [...$this->route(['PATCH'], '/settings/password', [], 'password'), 'named' => []],
        ]));
        $probes = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::RAISE));
        $sent = fn (int $status, array $raised = [], bool $invalid = false) => ['status' => $status, 'invalid' => $invalid, 'writes' => 1, 'landed' => null, 'raised' => $raised];
        $line = fn (int $id, array $control, array $swap) => json_encode(['id' => $id, 'owners' => true, 'control' => $control, 'swap' => $swap, 'guest' => null, 'policy' => null]);

        $measured = SwapProbes::measure($probes, SwapProbes::parse(implode("\n", [
            // The person made themselves an admin.
            $line(0, $sent(302), $sent(302, ['is_admin', 'not_a_field'])),
            // Every new note gets a balance, sent or not: the app's own default.
            $line(1, $sent(201, ['balance']), $sent(201, ['balance'])),
            // Turned down for the extra field, or saved without it.
            $line(2, $sent(302), $sent(302, ['role'], true)),
            $line(3, $sent(302), $sent(302)),
            // The table has none of the fields: nothing to try.
            json_encode(['id' => 4, 'owners' => true, 'none' => true]),
        ])));

        $this->assertSame([4, 3, 0], [$measured['tried'], $measured['refused'], $measured['untried']]);
        $this->assertSame([['PATCH', ['is_admin']]], array_map(fn (array $finding) => [$finding['method'], $finding['raised']], $measured['findings']));
        $this->assertStringContainsString('A signed-in person could give more rights to their own account by adding is_admin to the form: PATCH /settings/profile saved it. Save only the validated fields ($request->validated()), and keep is_admin out of the model\'s fillable attributes.', SwapProbes::describe($measured, 0));
    }

    public function test_an_extra_field_proves_nothing_when_the_form_without_it_did_not_work(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            [...$this->route(['PATCH'], '/settings/profile', [], 'update'), 'named' => []],
            [...$this->route(['POST'], '/notes', [], 'store'), 'named' => []],
        ]));
        $probes = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::RAISE));
        $sent = fn (int $status, int $writes, array $raised = [], bool $invalid = false) => ['status' => $status, 'invalid' => $invalid, 'writes' => $writes, 'landed' => null, 'raised' => $raised];

        $measured = SwapProbes::measure($probes, SwapProbes::parse(implode("\n", [
            // Wrote nothing as it is, or was turned down: the saved field proves nothing.
            json_encode(['id' => 0, 'owners' => true, 'control' => $sent(302, 0), 'swap' => $sent(302, 1, ['role'])]),
            json_encode(['id' => 1, 'owners' => true, 'control' => $sent(302, 1, [], true), 'swap' => $sent(302, 1, ['credits'])]),
        ])));

        $this->assertSame([0, 2, []], [$measured['tried'], $measured['untried'], $measured['findings']]);
    }

    public function test_each_removal_of_a_record_with_children_is_sent_with_one_of_each(): void
    {
        $children = [
            'Project' => [['relation' => 'tasks', 'model' => 'Task', 'key' => 'project_id'], ['relation' => 'brief', 'model' => 'Brief', 'key' => 'project_id']],
            // A name that is not a word is not read.
            'Note' => [['relation' => 'bad name', 'model' => 'Pin', 'key' => 'note_id']],
        ];
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['DELETE'], '/projects/{project}', [['project', 'Project']], 'destroy'),
            // A record nothing hangs off, and a route that does not remove.
            $this->route(['DELETE'], '/projects/{project}/tasks/{task}', [['project', 'Project'], ['task', 'Task']], 'destroy'),
            $this->route(['PUT'], '/projects/{project}', [['project', 'Project']], 'update'),
        ], $children));
        $removals = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::CHILDREN));

        $this->assertSame(['Project' => $children['Project']], $found['children']);
        $this->assertSame(['DELETE /projects/{project} Project'], array_map(fn (array $probe) => "{$probe['method']} {$probe['uri']} {$probe['leaf']}", $removals));
        $test = SwapProbes::test($removals, $found, 'swaps.jsonl');
        $this->assertStringContainsString("'children'", $test);
        $this->assertStringContainsString("'relation' => 'tasks', 'model' => 'Task', 'key' => 'project_id'", $test);
        $this->assertStringContainsString("method_exists(\$full[\$leaf], 'trashed')", $test, 'soft deletes never meet the links');
        $this->assertStringContainsString('PRAGMA foreign_keys', $test, 'links SQLite does not enforce prove nothing');
    }

    public function test_a_removal_that_broke_with_children_is_a_finding_and_one_removed_or_turned_down_is_not(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['DELETE'], '/projects/{project}', [['project', 'Project']], 'destroy'),
        ], ['Project' => [['relation' => 'tasks', 'model' => 'Task', 'key' => 'project_id']]]));
        $probe = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::CHILDREN))[0];
        $sent = fn (int $status, int $writes = 1) => ['status' => $status, 'invalid' => false, 'writes' => $writes, 'landed' => null, 'raised' => []];
        $line = fn (int $id, array $swap, ?string $exception = null) => json_encode(['id' => $id, 'owners' => true, 'control' => $sent(302), 'swap' => $swap, 'guest' => null, 'policy' => null, 'children' => ['tasks'], 'exception' => $exception]);

        $measured = SwapProbes::measure([$probe, $probe, $probe], SwapProbes::parse(implode("\n", [
            $line(0, $sent(500, 0), 'QueryException'),
            // Removed with its tasks, or turned down with a message.
            $line(1, $sent(302, 2)),
            $line(2, $sent(409, 0)),
        ])));

        $this->assertSame([3, 2, 0], [$measured['tried'], $measured['refused'], $measured['untried']]);
        $this->assertSame([['DELETE', ['tasks'], 'QueryException']], array_map(fn (array $finding) => [$finding['method'], $finding['children'], $finding['exception']], $measured['findings']));
        $this->assertStringContainsString('Removing a project that has a task broke the page (QueryException): DELETE /projects/{project} answered 500, while one without them was removed.', SwapProbes::describe($measured, 0));
    }

    public function test_a_removal_proves_nothing_when_the_bare_one_failed_or_the_links_are_not_enforced(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['DELETE'], '/projects/{project}', [['project', 'Project']], 'destroy'),
        ], ['Project' => [['relation' => 'tasks', 'model' => 'Task', 'key' => 'project_id']]]));
        $probe = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::CHILDREN))[0];
        $sent = fn (int $status, int $writes = 1) => ['status' => $status, 'invalid' => false, 'writes' => $writes, 'landed' => null, 'raised' => []];

        $measured = SwapProbes::measure([$probe, $probe, $probe], SwapProbes::parse(implode("\n", [
            // The bare one broke too: the route breaks for everyone.
            json_encode(['id' => 0, 'owners' => true, 'control' => $sent(500, 0), 'swap' => $sent(500, 0)]),
            // SQLite with its links off, or no record could be made.
            json_encode(['id' => 1, 'owners' => false]),
            // Soft deletes, or no child had a link the database checks.
            json_encode(['id' => 2, 'owners' => true, 'none' => true]),
        ])));

        $this->assertSame([0, 2, []], [$measured['tried'], $measured['untried'], $measured['findings']]);
    }

    public function test_each_list_whose_records_have_a_link_to_their_owner_is_opened_with_many(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            [...$this->route(['GET'], '/projects', [], 'index'), 'loads' => ['$projects = Project::all();', "bad\x01line"]],
            $this->route(['GET'], '/teams/{team}/projects', [['team', 'Team']], 'index'),
            $this->route(['GET'], '/projects/{project}/tasks', [['project', 'Project']], 'index'),
            // A list of people or of teams has no link of its own to an owner,
            // a list in another person's notes is not on the way to the
            // team, and an address that ends in a record is not a list.
            $this->route(['GET'], '/users', [], 'index'),
            $this->route(['GET'], '/teams', [], 'index'),
            $this->route(['GET'], '/notes/{note}/projects', [['note', 'Note']], 'index'),
            $this->route(['GET'], '/projects/{project}', [['project', 'Project']]),
        ]));
        $lists = array_values(array_filter(SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'], fn (array $probe) => $probe['mode'] === SwapProbes::LIST));

        $this->assertSame(['$projects = Project::all();'], $found['routes'][0]['loads'], 'a line that is not plain text is not read');
        $this->assertSame([
            'GET /projects Project team_id Team',
            'GET /teams/{team}/projects Project team_id Team',
            'GET /projects/{project}/tasks Task project_id Team',
        ], array_map(fn (array $probe) => "{$probe['method']} {$probe['uri']} {$probe['leaf']} {$probe['key']} {$probe['team']}", $lists));
        $test = SwapProbes::test($lists, $found, 'swaps.jsonl', 25);
        $this->assertStringContainsString('private const ROWS = 25;', $test);
        $this->assertStringContainsString("\$this->probe(0, 'GET', '/projects', array ( ), 'Project', 'list', NULL, 'team_id', NULL, array ( ));", $test);
        $this->assertStringContainsString("'X-Inertia' => 'true'", $test, 'Inertia props are read as well as JSON');
    }

    public function test_a_list_that_sent_every_record_is_the_changes_only_when_it_added_the_line_that_loads_it(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            [...$this->route(['GET'], '/projects', [], 'index'), 'loads' => ['$projects = Project::all();']],
            [...$this->route(['GET'], '/teams/{team}/projects', [['team', 'Team']], 'index'), 'loads' => ['return $team->projects;']],
            $this->route(['GET'], '/projects/{project}/tasks', [['project', 'Project']], 'index'),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'];
        $line = fn (int $id, int $shown) => json_encode(['id' => $id, 'owners' => true, 'control' => ['status' => 200, 'invalid' => false, 'writes' => 0], 'rows' => 60, 'shown' => $shown]);
        $ids = array_keys(array_filter($probes, fn (array $probe) => $probe['mode'] === SwapProbes::LIST));

        $lists = SwapProbes::measureLists($probes, SwapProbes::parse(implode("\n", [$line($ids[0], 60), $line($ids[1], 60), $line($ids[2], 15)])), $found, [
            'app/Http/Controllers/ProjectController.php' => ['new' => false, 'lines' => ['        $projects = Project::all();']],
        ]);

        $this->assertSame(3, $lists['tried']);
        $this->assertSame([['/projects', false], ['/teams/{team}/projects', true]], array_map(fn (array $finding) => [$finding['probe']['uri'], $finding['existing']], $lists['findings']));
        $described = SwapProbes::describeLists($lists);
        $this->assertStringContainsString('GET /projects sent all 60 projects at once ($projects = Project::all();), so the page gets slower with each one. Show a page at a time: paginate() or cursorPaginate() in its query, with links to the next page.', $described);
        $this->assertStringContainsString('Note, not a failure: GET /teams/{team}/projects sent all 60 projects at once (return $team->projects;), as it did before this change.', $described);
        $this->assertSame([0, 0], [SwapProbes::measure($probes, [])['tried'], SwapProbes::measure($probes, [])['untried'] - (count($probes) - count($ids))], 'the swaps do not count the lists');
    }

    public function test_a_list_that_broke_is_a_note_and_one_that_did_not_open_or_could_not_be_read_proves_nothing(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects', [], 'index'),
        ]));
        $probe = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'][0];
        $line = fn (int $id, int $status, ?int $shown, int $rows = 60) => json_encode(['id' => $id, 'owners' => true, 'control' => ['status' => $status, 'invalid' => false, 'writes' => 0], 'rows' => $rows, 'shown' => $shown, 'exception' => $status >= 500 ? 'QueryException' : null]);

        $lists = SwapProbes::measureLists([$probe, $probe, $probe, $probe, $probe], SwapProbes::parse(implode("\n", [
            $line(0, 500, null),
            // Sent to sign in, a Blade page, too few records made, or no records at all.
            $line(1, 302, null),
            $line(2, 200, null),
            $line(3, 200, 1, 1),
            json_encode(['id' => 4, 'owners' => false]),
        ])), $found, []);

        $this->assertSame([0, 4, []], [$lists['tried'], $lists['untried'], $lists['findings']]);
        $this->assertSame('Note, not a failure: GET /projects broke with 60 projects (QueryException); it answered 500.', SwapProbes::describeLists($lists));
    }

    public function test_the_limit_gives_each_kind_a_turn_so_a_long_kind_cannot_crowd_out_the_others(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            // Fifty pages of a project, then one probe of each other kind.
            ...array_map(fn (int $index) => $this->route(['GET'], "/projects/{project}/page{$index}", [['project', 'Project']]), range(1, 50)),
            [...$this->route(['PATCH'], '/settings/profile', [], 'update'), 'named' => []],
            $this->route(['POST'], '/projects', [], 'store'),
            $this->route(['GET'], '/projects', [], 'index'),
            $this->route(['DELETE'], '/projects/{project}', [['project', 'Project']], 'destroy'),
        ], ['Project' => [['relation' => 'tasks', 'model' => 'Task', 'key' => 'project_id']]]));
        $modes = fn (int $limit) => array_count_values(array_column(SwapProbes::plan($found, [self::CONTROLLER], [], $limit)['probes'], 'mode'));

        $this->assertSame([SwapProbes::ALL => 6, SwapProbes::FIELD => 1, SwapProbes::RAISE => 2, SwapProbes::LIST => 1, SwapProbes::CHILDREN => 1], $modes(11));
        $this->assertSame([SwapProbes::ALL => 51, SwapProbes::FIELD => 1, SwapProbes::RAISE => 2, SwapProbes::LIST => 1, SwapProbes::CHILDREN => 1], $modes(100), 'under the limit, every probe is kept');

        // The kinds keep their order, and the addresses keep theirs.
        $plan = SwapProbes::plan($found, [self::CONTROLLER], [], 11)['probes'];
        $this->assertSame([SwapProbes::ALL, SwapProbes::ALL, SwapProbes::ALL, SwapProbes::ALL, SwapProbes::ALL, SwapProbes::ALL, SwapProbes::FIELD, SwapProbes::RAISE, SwapProbes::RAISE, SwapProbes::LIST, SwapProbes::CHILDREN], array_column($plan, 'mode'));
        $this->assertSame(['/projects/{project}/page1', '/projects/{project}/page2'], [$plan[0]['uri'], $plan[1]['uri']]);
        $this->assertSame([], SwapProbes::plan($found, [self::CONTROLLER], [], 0)['probes']);
    }

    public function test_a_page_that_held_a_hidden_fields_stored_value_is_a_finding_once_per_page(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}', [['project', 'Project']]),
            $this->route(['GET'], '/projects/{project}/tasks', [['project', 'Project']], 'index'),
            $this->route(['GET'], '/notes/{note}', [['note', 'Note']], 'show'),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'];
        $ids = array_map(fn (array $probe) => "{$probe['uri']} {$probe['mode']}", $probes);
        $sent = ['status' => 200, 'invalid' => false, 'writes' => 0];
        $line = fn (string $probe, mixed $leaked) => json_encode(['id' => array_search($probe, $ids, true), 'owners' => true, 'control' => $sent, 'swap' => $sent, 'guest' => 302, 'policy' => null, 'leaked' => $leaked]);

        $leaks = SwapProbes::leaks($probes, SwapProbes::parse(implode("\n", [
            $line('/projects/{project} all', ['User.password', 'not a name']),
            // A list is read by its address and as a list: one page.
            $line('/projects/{project}/tasks all', ['User.remember_token']),
            $line('/projects/{project}/tasks list', ['User.password', 'User.remember_token']),
            $line('/notes/{note} all', []),
        ])));

        $this->assertSame(3, $leaks['read']);
        $this->assertSame([
            ['method' => 'GET', 'uri' => '/projects/{project}', 'fields' => ['User.password']],
            ['method' => 'GET', 'uri' => '/projects/{project}/tasks', 'fields' => ['User.remember_token', 'User.password']],
        ], $leaks['findings']);
        $this->assertStringContainsString('GET /projects/{project} sent User.password to the browser: the page holds the stored value the model keeps hidden. Send only what the page shows, with an API resource or ->only([...]);', SwapProbes::describeLeaks($leaks));
    }

    public function test_a_page_with_no_hidden_field_passes_and_one_that_did_not_open_was_not_read(): void
    {
        $found = (array) SwapProbes::found($this->printed([
            $this->route(['GET'], '/projects/{project}', [['project', 'Project']]),
            $this->route(['PUT'], '/projects/{project}', [['project', 'Project']], 'update'),
        ]));
        $probes = SwapProbes::plan($found, [self::CONTROLLER], [], 30)['probes'];
        $sent = ['status' => 200, 'invalid' => false, 'writes' => 1];

        // Only a GET reads the page; one that did not open says null.
        $this->assertSame(['read' => 0, 'findings' => []], SwapProbes::leaks($probes, SwapProbes::parse(implode("\n", [
            json_encode(['id' => 0, 'owners' => true, 'control' => $sent, 'swap' => $sent, 'guest' => 302, 'policy' => null, 'leaked' => null]),
            json_encode(['id' => 1, 'owners' => true, 'control' => $sent, 'swap' => $sent, 'guest' => null, 'policy' => null]),
        ]))));

        $leaks = SwapProbes::leaks($probes, SwapProbes::parse(json_encode(['id' => 0, 'owners' => true, 'control' => $sent, 'swap' => $sent, 'guest' => 302, 'policy' => null, 'leaked' => []])));
        $this->assertSame("Read 1 pages opened with the person's own records; none held a hidden field.", SwapProbes::describeLeaks($leaks));

        // The test reads what the models hide, and the secrets they may not.
        $test = SwapProbes::test($probes, $found, 'swaps.jsonl');
        $this->assertStringContainsString("private const SECRETS = array ( 0 => 'password', 1 => 'remember_token',", $test);
        $this->assertStringContainsString('$model->getHidden()', $test);
        $this->assertStringContainsString('strlen($value) >= 8', $test, 'a null, a flag or a short value is never a secret');
    }
}
