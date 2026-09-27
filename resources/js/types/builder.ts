export type FeatureRequestStatus =
    | 'generating'
    | 'generated'
    | 'answered'
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
    // How many of the app's own tests guard it; null before they first ran.
    tests: number | null;
};

/** A look an owner can start a new app with, drawn from its own colours. */
export type DesignOption = {
    key: string;
    name: string;
    description: string;
    font: string;
    radius: string;
    colors: Partial<
        Record<
            | 'background'
            | 'foreground'
            | 'primary'
            | 'primary-foreground'
            | 'accent'
            | 'muted-foreground'
            | 'border',
            string
        >
    >;
};

export type ChangeState =
    | 'waiting'
    | 'working'
    | 'answered'
    | 'kept'
    | 'stopped'
    | 'undone'
    | 'dismissed';

/** One ask the owner made, with its follow-ups folded in. */
export type ChangeItem = {
    id: number;
    prompt: string;
    summary: string | null;
    state: ChangeState;
    /** Waiting on the owner's answer to a question. */
    asks: boolean;
    /** The question it waits on, when it asks. */
    question: string | null;
    /** Nothing of it is kept, so it can be marked as not needed. */
    dismissable: boolean;
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
    first_attempt_unverified: number;
    repairs_before_acceptance: number | null;
    reviewed: number;
    with_unexpected_changes: number;
    input_tokens: number;
    output_tokens: number;
    visual_edits: number;
    setup_cost_usd: number;
    owner_actions: {
        adjustments: number;
        stops: number;
        retries: number;
        undos: number;
    };
    owner_actions_per_accepted_change: number | null;
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
    can_continue: boolean;
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

export type Run = {
    id: number;
    status: RunStatus;
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
    plan: {
        summary: string;
        /** The reply when the owner only asked about the app. */
        answer: string | null;
        acceptance_criteria: string[];
        assumptions: string[];
        understood_as: string | null;
        current_behavior: string | null;
        preserve: string[];
        /** What the owner might ask for next, sent with one tap. */
        next: string[];
    } | null;
    review: RunReview | null;
    /** What the change is doing right now, while it is being made. */
    progress: { text: string; changed: number } | null;
    /** How the change was made, step by step, in the owner's words. */
    work: WorkStep[];
    started_at: string | null;
    finished_at: string | null;
    /** What happened, in the owner's words; how it was done stays with us. */
    log: RunLogEntry[];
};

export type WorkStep = {
    kind:
        | 'thought'
        | 'read'
        | 'changed'
        | 'tested'
        | 'tried'
        | 'noted'
        | 'stage';
    text: string;
};

export type RunLogEntry = {
    sequence: number;
    text: string;
    created_at: string | null;
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
        evidence:
            | 'tested'
            | 'not_run'
            | 'not_run_by_checks'
            | 'claimed'
            | 'no_test';
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

// One plain sentence on how we know a change works.
export type ProofLine = {
    kind: 'passed' | 'caught' | 'reach' | 'gap';
    text: string;
};

// Everything about one change, as its page and the workspace chat show it.
export type ChangeDetail = {
    project: { id: number; name: string };
    featureRequest: FeatureRequestDetail;
    parent: { id: number; prompt: string } | null;
    earlier: {
        id: number;
        prompt: string;
        summary: string | null;
        status: FeatureRequestStatus;
    }[];
    followUps: FeatureRequestSummary[];
    verification: Verification | null;
    proof: ProofLine[];
    run: Run | null;
    preview: Preview | null;
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
    | 'height'
    | 'max_width'
    | 'padding_x'
    | 'padding_y'
    | 'margin_x'
    | 'margin_y'
    | 'border'
    | 'radius'
    | 'shadow'
    | 'rotate'
    | 'translate_x'
    | 'translate_y'
    | 'opacity'
    | 'text_size'
    | 'text_weight'
    | 'text_align'
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
    reason: 'updating' | 'behind' | 'not_found' | 'dynamic' | null;
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
    height?: number;
};

export type VisualEditSummary = {
    id: number;
    tag: string;
    device: Device;
    /** A change to how the part looks, or a move among its siblings. */
    kind: 'look' | 'move';
    properties: VisualProperty[];
    /** The part's classes and the app's version after this edit (or its undo). */
    classes: string;
    revision: string;
    /** Where the part is written. */
    target: string;
    /** How the part looks before and after a change to its look. */
    sides: Record<
        'before' | 'after',
        {
            classes: string;
            values: Partial<Record<VisualProperty, VisualValue>>;
        }
    > | null;
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
    // How many of the app's tests run this part's own code; null before any test run was mapped.
    checked_by: number | null;
    // What those tests check, in their authors' words.
    checks: string[];
    file: string | null;
};

export type CheckFinding = {
    title: string;
    details: string[];
};

export type DeploymentSummary = {
    id: number;
    status:
        | 'checking'
        | 'pushing'
        | 'confirming'
        | 'sent'
        | 'published'
        | 'needs_attention'
        | 'failed';
    commit: string;
    checks: { name: string; passed: boolean }[];
    error: string | null;
    health: {
        path: string;
        status: number | null;
        passed: boolean;
        key?: string;
    }[];
    problems: number;
    pushed_at: string | null;
    confirmed_at: string | null;
    created_at: string | null;
    finished_at: string | null;
};

export type ProjectPublishing = {
    connected: boolean;
    managed: boolean;
    target: string | null;
    branch: string | null;
    address: string | null;
    head: string | null;
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

/** Something that needs the owner, such as a change that is ready to try. */
export type OwnerNotification = {
    id: string;
    kind: 'ready' | 'answered' | 'question' | 'failed';
    title: string;
    body: string;
    project_id: number;
    feature_request_id: number;
    url: string;
    read: boolean;
    created_at: string | null;
};

export type Notifications = {
    unread: number;
    items: OwnerNotification[];
};

/** An idea the owner tries apart from their app, on its own branch. */
export type Idea = {
    id: number;
    name: string;
    branch: string;
};

export type Ideas = {
    /** The idea the owner is working in; null is the app itself. */
    current: Idea | null;
    open: Idea[];
    /** The main branch's name, shown to power users. */
    main: string;
};
