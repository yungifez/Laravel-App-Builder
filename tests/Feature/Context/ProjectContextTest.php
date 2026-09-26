<?php

namespace Tests\Feature\Context;

use App\Actions\Context\ClassifyChange;
use App\Actions\Context\CompileContext;
use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\ProjectContext;
use App\Enums\ContextMode;
use App\Enums\EffectStrength;
use Tests\TestCase;

class ProjectContextTest extends TestCase
{
    protected const INVITATIONS = <<<'MARKDOWN'
    ---
    capability: invitations
    summary: Owners and admins invite people to join a team.
    paths: [app/Actions/Invitations/*, app/Models/Invitation.php, routes/web.php]
    behaviors:
        - key: invite-member
          name: Invite a member
    effects:
        - to: membership
          strength: strong
          reason: Accepted invitations create memberships.
          source: agent
          observed: 2026-09-26
        - to: billing
          strength: possible
          reason: Active members may count towards paid seats.
          source: owner
    ---
    # Invitations

    Only owners and admins can invite.
    MARKDOWN;

    public function test_a_capability_file_is_read_from_its_frontmatter_and_notes()
    {
        $capability = Capability::fromMarkdown('.builder/capabilities/invitations.md', self::INVITATIONS);

        $this->assertSame('invitations', $capability->key);
        $this->assertSame('Invitations', $capability->name);
        $this->assertSame('Owners and admins invite people to join a team.', $capability->summary);
        $this->assertSame([['key' => 'invite-member', 'name' => 'Invite a member']], $capability->behaviors);
        $this->assertSame(EffectStrength::Strong, $capability->effects[0]->strength);
        $this->assertSame('2026-09-26', $capability->effects[0]->observed);
        $this->assertSame('owner', $capability->effects[1]->source);
        $this->assertStringStartsWith('# Invitations', $capability->notes);
        $this->assertTrue($capability->claims('app/Actions/Invitations/InviteMember.php'));
        $this->assertFalse($capability->claims('app/Actions/Teams/CreateTeam.php'));
    }

    public function test_a_plain_markdown_file_is_an_area_named_after_the_file()
    {
        $capability = Capability::fromMarkdown('.builder/capabilities/team-billing.md', "Owners pay per team.\n");

        $this->assertSame('team-billing', $capability->key);
        $this->assertSame('Team Billing', $capability->name);
        $this->assertSame([], $capability->paths);
        $this->assertSame('Owners pay per team.', $capability->notes);
    }

    public function test_an_invalid_capability_file_is_refused_with_the_reason()
    {
        $this->expectException(InvalidContextFile::class);
        $this->expectExceptionMessage('.builder/capabilities/x.md:');

        Capability::fromMarkdown('.builder/capabilities/x.md', "---\ncapability: x\neffects:\n    - to: y\n      strength: certain\n      reason: Because.\n      source: agent\n---\n");
    }

    public function test_selective_context_has_the_project_notes_the_requested_areas_their_effects_and_an_index()
    {
        $pack = app(CompileContext::class)->handle($this->context(), ['invitations', 'unknown'], ContextMode::Selective);

        $this->assertSame(['invitations'], $pack->targets);
        $this->assertStringContainsString('We call customers clients.', $pack->text);
        $this->assertStringContainsString('Only owners and admins can invite.', $pack->text);
        $this->assertStringContainsString('- Membership (strong): Accepted invitations create memberships.', $pack->text);
        $this->assertStringContainsString('- billing (possible): Active members may count towards paid seats.', $pack->text);
        $this->assertStringContainsString('- Membership: People in a team and their roles. (.builder/capabilities/membership.md)', $pack->text);
        $this->assertStringNotContainsString('Owners can remove members.', $pack->text);
        $this->assertSame(['.builder/project.md', '.builder/capabilities/invitations.md', 'index'], array_column($pack->included, 'file'));
        $this->assertGreaterThan(0, $pack->tokens());
        $this->assertCount(2, $pack->outline);
    }

    public function test_the_comparison_modes_send_everything_nothing_or_no_effects()
    {
        $compile = app(CompileContext::class);

        $flat = $compile->handle($this->context(), ['invitations'], ContextMode::Flat);
        $this->assertStringContainsString('Owners can remove members.', $flat->text);
        $this->assertStringContainsString('Only owners and admins can invite.', $flat->text);

        $none = $compile->handle($this->context(), ['invitations'], ContextMode::None);
        $this->assertSame('', $none->text);
        $this->assertSame([], $none->included);
        $this->assertCount(2, $none->outline);

        $withoutEffects = $compile->handle($this->context(), ['invitations'], ContextMode::SelectiveWithoutEffects);
        $this->assertStringContainsString('Only owners and admins can invite.', $withoutEffects->text);
        $this->assertStringNotContainsString('May also affect', $withoutEffects->text);
    }

    public function test_the_configured_mode_is_the_default()
    {
        config(['builder.context.mode' => 'flat']);

        $this->assertSame(ContextMode::Flat, app(CompileContext::class)->handle($this->context(), [])->mode);
    }

    public function test_a_change_is_sorted_into_requested_may_also_affect_unexpected_and_unclaimed_files()
    {
        $context = new ProjectContext(capabilities: [
            'invitations' => Capability::fromMarkdown('.builder/capabilities/invitations.md', self::INVITATIONS),
            'membership' => Capability::fromMarkdown('.builder/capabilities/membership.md', "---\ncapability: membership\npaths: [app/Models/Membership.php, routes/web.php]\n---\n"),
            'account' => Capability::fromMarkdown('.builder/capabilities/account.md', "---\ncapability: account\npaths: [app/Actions/Account/*]\n---\n"),
        ]);

        $classification = app(ClassifyChange::class)->handle($context, ['invitations'], $this->diffFor([
            'app/Actions/Invitations/InviteMember.php',
            'routes/web.php',
            'app/Models/Membership.php',
            'app/Actions/Account/DeleteAccount.php',
            'database/migrations/2026_09_26_000000_create_invitations_table.php',
            '.builder/capabilities/invitations.md',
        ]));

        $this->assertSame(['invitations' => ['app/Actions/Invitations/InviteMember.php', 'routes/web.php']], $classification->requested);
        $this->assertSame(['membership' => ['app/Models/Membership.php']], $classification->mayAlsoAffect);
        $this->assertSame(['account' => ['app/Actions/Account/DeleteAccount.php']], $classification->unexpected);
        $this->assertSame(['database/migrations/2026_09_26_000000_create_invitations_table.php'], $classification->unclaimed);
        $this->assertSame(['.builder/capabilities/invitations.md'], $classification->contextUpdates);
        $this->assertSame('requested', $classification->sectionFor('invitations'));
        $this->assertSame('may_also_affect', $classification->sectionFor('membership'));
        $this->assertSame('unexpected', $classification->sectionFor('account'));
        $this->assertSame('other', $classification->sectionFor(null));
    }

    public function test_without_any_areas_every_changed_file_is_unclaimed()
    {
        $classification = app(ClassifyChange::class)->handle(new ProjectContext, [], $this->diffFor(['app/Models/Team.php']));

        $this->assertSame([], $classification->touched());
        $this->assertSame(['app/Models/Team.php'], $classification->unclaimed);
    }

    /**
     * A small project context: project notes and two areas.
     */
    protected function context(): ProjectContext
    {
        return new ProjectContext(
            project: 'We call customers clients.',
            capabilities: [
                'invitations' => Capability::fromMarkdown('.builder/capabilities/invitations.md', self::INVITATIONS),
                'membership' => Capability::fromMarkdown('.builder/capabilities/membership.md', "---\ncapability: membership\nsummary: People in a team and their roles.\n---\n# Membership\n\nOwners can remove members.\n"),
            ],
        );
    }

    /**
     * A diff that adds one line to each of the given files.
     *
     * @param  list<string>  $paths
     */
    protected function diffFor(array $paths): string
    {
        return implode('', array_map(fn (string $path) => "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n@@ -1 +1,2 @@\n line\n+added\n", $paths));
    }
}
