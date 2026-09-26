export type FeatureRequestStatus =
    | 'generating'
    | 'generated'
    | 'failed'
    | 'cancelled';

export type ProjectSummary = {
    id: number;
    name: string;
    source_path: string;
    published_at: string | null;
};

/** One app on the owner's apps list. */
export type ProjectListItem = {
    id: number;
    name: string;
    published_at: string | null;
    changed_at: string | null;
    waiting: number;
};

export type ChangeState = 'waiting' | 'working' | 'kept' | 'stopped' | 'undone';

/** One ask the owner made, with its follow-ups folded in. */
export type ChangeItem = {
    id: number;
    prompt: string;
    summary: string | null;
    state: ChangeState;
    updated_at: string | null;
};

export type FeatureRequestSummary = {
    id: number;
    prompt: string;
    status: FeatureRequestStatus;
    created_at?: string | null;
    target_step?: string | null;
    accepted?: boolean;
};

export type ProjectTelemetry = {
    requests: number;
    accepted: number;
    reverted: number;
    cost_usd: number;
    unpriced_calls: number;
    cost_per_accepted_change_usd: number | null;
    runs_verified: number;
    first_attempt_passed: number;
    repairs_before_acceptance: number | null;
    reviewed: number;
    with_unexpected_changes: number;
    input_tokens: number;
    output_tokens: number;
    visual_edits: number;
    setup_cost_usd: number;
    interventions: {
        adjustments: number;
        stops: number;
        retries: number;
        undos: number;
    };
    interventions_per_accepted_change: number | null;
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
    can_retry: boolean;
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
    question: {
        text: string;
        why: string;
        options: string[];
        recommended: string | null;
    } | null;
    answers: {
        question: string;
        answer: string;
        decided_by: 'owner' | 'builder';
    }[];
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
    verified: {
        criterion: string;
        test_file: string | null;
        test_name: string | null;
        evidence: 'tested' | 'not_run' | 'not_run_by_checks' | 'no_test';
        named_in_diff: boolean;
    }[];
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

export type Device = 'base' | 'md' | 'lg';

export type VisualProperty =
    | 'layout'
    | 'direction'
    | 'wrap'
    | 'align'
    | 'justify'
    | 'columns'
    | 'gap'
    | 'width'
    | 'max_width'
    | 'padding_x'
    | 'padding_y'
    | 'margin_x'
    | 'margin_y'
    | 'border'
    | 'radius'
    | 'shadow'
    | 'text_size'
    | 'text_weight'
    | 'text_color'
    | 'background';

export type VisualValue = number | string;

export type EditorPreview = {
    id: number;
    status: PreviewStatus;
    error: string | null;
    origin: string;
    revision: string | null;
    updating: boolean;
};

export type InspectedElement = {
    target: string;
    file: string;
    line: number;
    tag: string | null;
    instance: boolean;
    shared: { name: string; uses: number } | null;
    editable: boolean;
    reason: 'updating' | 'not_found' | 'dynamic' | null;
    classes: string;
    values: Record<
        Device,
        Partial<Record<VisualProperty, { value: VisualValue; from: Device }>>
    >;
    area: {
        key: string;
        name: string;
        summary: string | null;
        rules: string[];
        behaviors: string[];
    } | null;
    revision: string;
};

export type SelectedElement = {
    source: string | null;
    instance: string | null;
    tag: string;
    text: string;
    width: number;
};

export type VisualEditSummary = {
    id: number;
    tag: string;
    device: Device;
    properties: VisualProperty[];
    created_at: string | null;
    reverted_at: string | null;
};

export type NotesSection = {
    heading: string;
    body: string;
};

export type UnderstandingArea = {
    key: string;
    name: string;
    summary: string | null;
    behaviors: string[];
    rules: string[];
    connections: {
        to: string;
        name: string;
        reason: string;
        strength: 'strong' | 'possible' | 'historical';
    }[];
    tested: boolean;
    file: string | null;
};

export type CheckFinding = {
    title: string;
    details: string[];
};

export type DeploymentSummary = {
    id: number;
    status: 'checking' | 'pushing' | 'published' | 'failed';
    commit: string;
    checks: { name: string; passed: boolean }[];
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
};

export type ProjectPublishing = {
    connected: boolean;
    target: string | null;
    branch: string | null;
    deployments: DeploymentSummary[];
};

export type NotesDraft = {
    status: 'drafting' | 'ready' | 'failed';
    purpose: string | null;
    areas: {
        key: string;
        name: string;
        summary: string;
        behaviors: string[];
        rules: string[];
    }[];
    error: string | null;
};
