export type TeamRole = 'owner' | 'admin' | 'member';

export type Team = {
    id: number;
    name: string;
    personal_team: boolean;
};

export type TeamSummary = {
    id: number;
    name: string;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    role: TeamRole;
    role_label: string;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};
