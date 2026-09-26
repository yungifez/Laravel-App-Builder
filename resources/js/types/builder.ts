export type FeatureRequestStatus =
    | 'generating'
    | 'generated'
    | 'failed'
    | 'cancelled';

export type ProjectSummary = {
    id: number;
    name: string;
    source_path: string;
};

export type FeatureRequestSummary = {
    id: number;
    prompt: string;
    status: FeatureRequestStatus;
    created_at?: string | null;
    target_step?: string | null;
    accepted?: boolean;
};

export type ProjectCommit = {
    sha: string;
    subject: string;
    author: string;
    committed_at: string;
};

export type ChangeStep = {
    key: string;
    kind: string;
    label: string;
    file: string;
    symbol: string;
    detail: string;
};

export type ChangedFile = {
    path: string;
    additions: number;
    deletions: number;
    diff: string;
};

export type FeatureRequestDetail = {
    id: number;
    prompt: string;
    status: FeatureRequestStatus;
    summary: string | null;
    error: string | null;
    target_step: ChangeStep | null;
    steps: ChangeStep[];
    files: ChangedFile[];
    commit_sha: string | null;
    accepted_at: string | null;
    revert_sha: string | null;
    reverted_at: string | null;
    can_accept: boolean;
};

export type VerificationStatus =
    | 'queued'
    | 'running'
    | 'passed'
    | 'failed'
    | 'errored'
    | 'unverified';

export type VerificationOutcome =
    | 'passed'
    | 'failed'
    | 'errored'
    | 'skipped'
    | 'not_applicable';

export type VerificationResult = {
    name: string;
    stage: 'apply' | 'setup' | 'checks' | 'acceptance';
    outcome: VerificationOutcome;
    exit_code: number | null;
    timed_out: boolean;
    duration_ms: number;
    output: string;
};

export type Verification = {
    id: number;
    status: VerificationStatus;
    results: VerificationResult[];
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type RunStatus =
    | 'queued'
    | 'planning'
    | 'implementing'
    | 'verifying'
    | 'reviewing'
    | 'completed'
    | 'needs_user_decision'
    | 'cancelling'
    | 'cancelled'
    | 'failed';

export type RunEvent = {
    sequence: number;
    type: string;
    data: Record<string, unknown>;
    created_at: string | null;
};

export type Run = {
    id: number;
    status: RunStatus;
    driver: string;
    error: string | null;
    workspace_revision: number;
    plan: {
        summary: string;
        acceptance_criteria: string[];
        assumptions: string[];
        understood_as: string | null;
        current_behavior: string | null;
        preserve: string[];
    } | null;
    context: {
        mode: 'none' | 'flat' | 'selective' | 'selective_without_effects';
        targets: string[];
        included: { file: string; tokens: number }[];
        tokens: number;
        problems: string[];
    } | null;
    review: RunReview | null;
    built_by: {
        adapter: string;
        provider: string;
        model: string | null;
        backup: boolean;
        reason: string | null;
    } | null;
    repairs: number;
    operations: number;
    budget: { operations: number; minutes: number; repairs: number };
    started_at: string | null;
    finished_at: string | null;
    events: RunEvent[];
};

export type ChangeSection =
    | 'requested'
    | 'may_also_affect'
    | 'unexpected'
    | 'other';

export type ChangedArea = { key: string; name: string; files: string[] };

export type RunReview = {
    summary: string;
    changes: {
        area: string | null;
        area_name: string | null;
        section: ChangeSection;
        behavior: string;
        before: string;
        now: string;
    }[];
    areas: Record<Exclude<ChangeSection, 'other'>, ChangedArea[]>;
    preserved: {
        area: string | null;
        area_name: string | null;
        statement: string;
        evidence: 'verified' | 'untouched' | 'not_checked';
        unchanged: boolean;
        tests: number;
    }[];
    unclaimed: string[];
    context_updates: string[];
};

export type PreviewStatus = 'starting' | 'ready' | 'failed' | 'stopped';

export type Preview = {
    id: number;
    status: PreviewStatus;
    error: string | null;
    url: string;
    expires_at: string | null;
};
