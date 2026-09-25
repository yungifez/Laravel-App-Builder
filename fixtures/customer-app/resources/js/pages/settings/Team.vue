<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import CurrentTeamController from '@/actions/App/Http/Controllers/CurrentTeamController';
import TeamController from '@/actions/App/Http/Controllers/Settings/TeamController';
import TeamMemberController from '@/actions/App/Http/Controllers/Settings/TeamMemberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/teams';
import type { RoleOption, Team, TeamMember, TeamSummary } from '@/types';

type Props = {
    team: Team;
    members: TeamMember[];
    teams: TeamSummary[];
    assignableRoles: RoleOption[];
    can: {
        updateTeam: boolean;
        updateMemberRoles: boolean;
        removeMembers: boolean;
    };
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Team settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Team settings" />

    <h1 class="sr-only">Team settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Team"
            description="Manage your current team and its members"
        />

        <Form
            v-bind="TeamController.update.form(team)"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="team-name">Team name</Label>
                <Input
                    id="team-name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="team.name"
                    :disabled="!can.updateTeam"
                    required
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div v-if="can.updateTeam" class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-team-button">
                    Save
                </Button>
            </div>
        </Form>
    </div>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Members"
            description="People who belong to this team and their roles"
        />

        <ul class="divide-y rounded-lg border" data-test="team-members">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">
                        {{ member.name }}
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ member.email }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Form
                        v-if="can.updateMemberRoles && member.role !== 'owner'"
                        v-bind="
                            TeamMemberController.update.form({
                                team: team.id,
                                member: member.id,
                            })
                        "
                        class="flex items-center gap-2"
                        v-slot="{ errors, processing }"
                    >
                        <Select name="role" :default-value="member.role">
                            <SelectTrigger
                                class="w-32"
                                :aria-label="`Role for ${member.name}`"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="role in assignableRoles"
                                    :key="role.value"
                                    :value="role.value"
                                >
                                    {{ role.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="processing"
                        >
                            Update
                        </Button>
                        <InputError :message="errors.role" />
                    </Form>

                    <Badge v-else variant="secondary">
                        {{ member.role_label }}
                    </Badge>

                    <Form
                        v-if="can.removeMembers && member.role !== 'owner'"
                        v-bind="
                            TeamMemberController.destroy.form({
                                team: team.id,
                                member: member.id,
                            })
                        "
                        v-slot="{ processing }"
                    >
                        <Button
                            variant="destructive"
                            size="sm"
                            :disabled="processing"
                        >
                            Remove
                        </Button>
                    </Form>
                </div>
            </li>
        </ul>
    </div>

    <div v-if="teams.length > 1" class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Switch team"
            description="Choose which team you are working in"
        />

        <ul class="divide-y rounded-lg border">
            <li
                v-for="item in teams"
                :key="item.id"
                class="flex items-center justify-between p-4"
            >
                <span class="text-sm font-medium">{{ item.name }}</span>

                <Badge v-if="item.id === team.id" variant="outline">
                    Current
                </Badge>

                <Form
                    v-else
                    v-bind="CurrentTeamController.update.form(item)"
                    v-slot="{ processing }"
                >
                    <Button variant="outline" size="sm" :disabled="processing">
                        Switch
                    </Button>
                </Form>
            </li>
        </ul>
    </div>
</template>
